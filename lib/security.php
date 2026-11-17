<?php
declare(strict_types=1);

/* Seguridad común: carpeta de datos, IP del cliente, HTTPS, límites de frecuencia,
   validación de direcciones de red y comprobación de exposición de archivos. */

/* ---------- Carpeta de datos ---------- */

/**
 * Carpeta donde se guardan la base de datos SQLite y los archivos de importación.
 * Por defecto data/ dentro de la aplicación; config.php puede indicar otra ('data_dir'),
 * idealmente fuera de la carpeta pública del servidor web.
 */
function data_dir(): string
{
    $d = (string)(cfg()['data_dir'] ?? '');
    return rtrim($d !== '' ? $d : ROOT . '/data', '/\\');
}

/* ---------- IP del cliente y HTTPS (con proxy de confianza) ---------- */

/** IPs de los proxies inversos de confianza (config.php: 'trusted_proxies' => ['10.0.0.5']). */
function trusted_proxies(): array
{
    return array_values(array_filter(array_map('trim', (array)(cfg()['trusted_proxies'] ?? []))));
}
function from_trusted_proxy(): bool
{
    $r = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $r !== '' && in_array($r, trusted_proxies(), true);
}

/** IP real del visitante. X-Forwarded-For solo se tiene en cuenta si la petición llega de un proxy de confianza. */
function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (from_trusted_proxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Se recorre de derecha a izquierda y se toma la primera IP que no sea un proxy de confianza
        foreach (array_reverse(array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']))) as $hop) {
            if (!filter_var($hop, FILTER_VALIDATE_IP)) break;
            $ip = $hop;
            if (!in_array($hop, trusted_proxies(), true)) break;
        }
    }
    return $ip;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (from_trusted_proxy() && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return false;
}

/** true si la dirección configurada de la aplicación es https: entonces las cookies van siempre con Secure. */
function base_is_https(): bool
{
    $b = stored_base_url();
    return $b !== '' ? str_starts_with(strtolower($b), 'https://') : is_https();
}

/** Ruta de las cookies: la carpeta de la aplicación según la dirección configurada. */
function cookie_path(): string
{
    $b = stored_base_url();
    $p = $b !== '' ? (string)parse_url($b, PHP_URL_PATH) : '';
    return rtrim($p, '/') . '/';
}

/**
 * Antes de servir una página: si la petición no llega a la dirección configurada (otro host)
 * o llega por http estando activado «Forzar HTTPS», redirige a la dirección correcta.
 */
function enforce_canonical(): void
{
    if (PHP_SAPI === 'cli' || !cfg()) return;
    // Solo la dirección de config.php: si se cambia de dominio y la de Ajustes (versiones anteriores) quedó
    // apuntando al antiguo, no se redirige allí y se puede entrar por el nuevo para corregirla.
    $base = config_base_url();
    if ($base === '') return;
    $want = parse_url($base);
    if (empty($want['host'])) return;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $wantHost = strtolower($want['host'] . (isset($want['port']) ? ':' . $want['port'] : ''));
    $httpsWanted = strtolower($want['scheme'] ?? '') === 'https' && setting('force_https', '0') === '1';
    $hostOk = $host === $wantHost;
    if ($hostOk && (!$httpsWanted || is_https())) {
        if ($httpsWanted) header('Strict-Transport-Security: max-age=31536000');
        return;
    }
    // Solo se redirigen las peticiones GET/HEAD; un POST a un host incorrecto se rechaza
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        http_response_code(400);
        exit('Dirección no válida.');
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    // 302 (temporal) y sin caché: una 301 la guarda el navegador y seguiría redirigiendo al dominio antiguo
    // aunque luego se cambie la dirección en config.php.
    header('Cache-Control: no-store');
    header('Location: ' . rtrim($base, '/') . '/' . $script . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}

/* ---------- Incrustar la aplicación en otras webs de confianza (p. ej. Nextcloud) ---------- */

/**
 * Valida y normaliza un origen para frame-ancestors: https://host[:puerto] o https://*.dominio.
 * Acepta que se pegue la dirección completa (se queda solo con el dominio) o sin https://. Devuelve '' si no es válida.
 */
function normalize_origin(string $s): string
{
    $s = strtolower(trim($s));
    if ($s === '' || preg_match('/[\s\'";,]/', $s)) return '';
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#', $s)) $s = 'https://' . $s;
    $p = parse_url(str_replace('://*.', '://wildcard-x.', $s));
    if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host']) || isset($p['user'])) return '';
    $host = str_replace('wildcard-x.', '*.', $p['host']);
    if (!preg_match('/^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*$|^\[[0-9a-f:]+\]$/', $host)) return '';
    return $p['scheme'] . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
}
/** Webs que pueden mostrar la aplicación dentro de un iframe (Ajustes → URLs de confianza). */
function frame_origins(): array
{
    if (!cfg()) return [];
    $out = [];
    foreach (preg_split('/[\s,]+/', (string)setting('frame_origins'), -1, PREG_SPLIT_NO_EMPTY) as $o) {
        if (($n = normalize_origin($o)) !== '') $out[$n] = true;
    }
    return array_keys($out);
}
/** Valor de frame-ancestors: la propia aplicación y las webs de confianza. */
function frame_ancestors(): string
{
    return trim("'self' " . implode(' ', frame_origins()));
}
/** «Sitio» aproximado de un host (los dos últimos niveles: mailing.bezares.com → bezares.com). */
function site_of(string $host): string
{
    $host = strtolower(trim($host, '[]'));
    if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
    $parts = explode('.', preg_replace('/^\*\./', '', $host));
    return implode('.', array_slice($parts, -2));
}
/**
 * true si alguna web de confianza es de OTRO sitio que la aplicación (p. ej. Nextcloud en otro dominio).
 * Entonces el navegador no envía las cookies normales dentro del iframe y hay que usar SameSite=None.
 */
function cross_site_embedding(): bool
{
    $base = stored_base_url();
    $mine = site_of((string)parse_url($base, PHP_URL_HOST));
    foreach (frame_origins() as $o) {
        if (site_of((string)parse_url(str_replace('*.', 'x.', $o), PHP_URL_HOST)) !== $mine) return true;
    }
    return false;
}
/** SameSite de las cookies: None (con Secure y Partitioned) solo si hace falta para incrustar desde otro sitio por HTTPS. */
function cookie_samesite(): string
{
    return cross_site_embedding() && base_is_https() ? 'None' : 'Lax';
}
/** Añade «Partitioned» (CHIPS) a las cookies de la aplicación para que funcionen en iframes de otros sitios. */
function partition_cookies(): void
{
    static $done = false;
    if ($done || cookie_samesite() !== 'None') return;
    $done = true;
    header_register_callback(function () {
        $cookies = [];
        foreach (headers_list() as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) $cookies[] = trim(substr($h, 11));
        }
        if (!$cookies) return;
        header_remove('Set-Cookie');
        foreach ($cookies as $c) header('Set-Cookie: ' . $c . (stripos($c, 'partitioned') === false ? '; Partitioned' : ''), false);
    });
}

/**
 * Cabeceras de seguridad. Las páginas solo se pueden mostrar dentro de un iframe de la propia aplicación
 * (la vista previa de los correos) o de las URLs de confianza de Ajustes (p. ej. Nextcloud); ninguna otra web
 * puede incrustarlas (clickjacking). $frame: permitir que cualquier web la incruste (formulario de suscripción).
 */
function security_headers(bool $frame = false): void
{
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if (!$frame) {
        // X-Frame-Options no admite una lista de webs: si hay URLs de confianza, manda solo frame-ancestors
        if (!frame_origins()) header('X-Frame-Options: SAMEORIGIN');
        header('Content-Security-Policy: frame-ancestors ' . frame_ancestors());
    }
}

/* ---------- Límites de frecuencia ---------- */

/** Registra un intento para la clave indicada (por ejemplo «login-ip:1.2.3.4»). */
function throttle_hit(string $key): void
{
    $key = substr($key, 0, 190);
    q('INSERT INTO throttle (k, at) VALUES (?, ?)', [$key, time()]);
    // Limpieza ocasional de registros de más de un día
    if (random_int(1, 50) === 1) q('DELETE FROM throttle WHERE at < ?', [time() - 86400]);
}
function throttle_count(string $key, int $seconds): int
{
    return (int)val('SELECT COUNT(*) FROM throttle WHERE k = ? AND at > ?', [substr($key, 0, 190), time() - $seconds]);
}
/** true si todavía se permite otra acción (menos de $max en los últimos $seconds). */
function throttle_ok(string $key, int $max, int $seconds): bool
{
    return throttle_count($key, $seconds) < $max;
}
function throttle_clear(string $key): void
{
    q('DELETE FROM throttle WHERE k = ?', [substr($key, 0, 190)]);
}
function throttle_last(string $key): int
{
    return (int)val('SELECT MAX(at) FROM throttle WHERE k = ?', [substr($key, 0, 190)]);
}

/* ---------- Direcciones de red públicas (contra SSRF) ---------- */

/** true solo si la IP es pública y enrutable en Internet (excluye privadas, locales, reservadas, CGNAT, multicast…). */
function ip_is_public(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    $bin = inet_pton($ip);
    if ($bin === false) return false;
    if (strlen($bin) === 16) {
        // IPv6 que en realidad apunta a IPv4 (mapeada, NAT64, 6to4): se valida la IPv4 de dentro
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) return ip_is_public(inet_ntop(substr($bin, 12)));
        if (str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) return ip_is_public(inet_ntop(substr($bin, 12)));
        if (str_starts_with($bin, "\x20\x02")) return ip_is_public(inet_ntop(substr($bin, 2, 4)));
    }
    $blocked = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];
    foreach ($blocked as $cidr) if (ip_in_cidr($bin, $cidr)) return false;
    return true;
}
function ip_in_cidr(string $bin, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $n = inet_pton($net);
    if ($n === false || strlen($n) !== strlen($bin)) return false;
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8);
    if (substr($bin, 0, $bytes) !== substr($n, 0, $bytes)) return false;
    $rest = $bits % 8;
    if ($rest === 0) return true;
    $mask = (0xff << (8 - $rest)) & 0xff;
    return (ord($bin[$bytes]) & $mask) === (ord($n[$bytes]) & $mask);
}

/** Todas las IPs (IPv4 e IPv6) de un nombre de host. */
function resolve_host(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
    $ips = [];
    $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
    foreach (is_array($recs) ? $recs : [] as $r) {
        if (!empty($r['ip'])) $ips[] = $r['ip'];
        if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    foreach (@gethostbynamel($host) ?: [] as $ip) $ips[] = $ip;
    return array_values(array_unique($ips));
}

/* ---------- Comprobación de exposición de archivos privados ---------- */

/**
 * Pide desde fuera (por HTTP, a la dirección configurada) un archivo de prueba creado en la carpeta de datos
 * y un archivo interno. Devuelve la lista de problemas encontrados ([] = todo protegido).
 * Solo comprueba la carpeta de datos si está dentro de la carpeta de la aplicación.
 */
function exposure_check(): array
{
    if (!function_exists('curl_init')) return ['No se puede comprobar: falta la extensión cURL de PHP.'];
    $base = stored_base_url();
    if ($base === '') return ['No se puede comprobar: falta la dirección de la aplicación en Ajustes.'];
    $problems = [];
    $get = function (string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'BezaresMailing/selfcheck']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : ''];
    };
    $dataInside = str_starts_with(realpath(data_dir()) ?: data_dir(), (realpath(ROOT) ?: ROOT) . DIRECTORY_SEPARATOR);
    if ($dataInside) {
        $rel = substr(realpath(data_dir()) ?: data_dir(), strlen(realpath(ROOT) ?: ROOT) + 1);
        $name = 'probe-' . token(6) . '.txt';
        $secret = 'probe-' . token(8);
        if (@file_put_contents(data_dir() . '/' . $name, $secret) !== false) {
            [$code, $body] = $get($base . '/' . str_replace('\\', '/', $rel) . '/' . $name);
            @unlink(data_dir() . '/' . $name);
            if ($code === 0) $problems[] = 'No se pudo conectar con ' . $base . ' para hacer la comprobación (revise la dirección en Ajustes o compruébelo a mano desde otro equipo).';
            elseif (str_contains($body, $secret)) $problems[] = 'La carpeta de datos (' . $rel . '/) se puede descargar desde Internet: la base de datos y las importaciones están expuestas.';
        }
    }
    [$code, $body] = $get($base . '/lib/boot.php');
    if (str_contains($body, '<?php')) $problems[] = 'El servidor muestra el código fuente PHP (lib/boot.php): PHP no se está ejecutando para esta carpeta.';
    [$code] = $get($base . '/themes/comunicado.html');
    if ($code === 200) $problems[] = 'Las carpetas internas (lib, pages, themes, data) no están bloqueadas: las reglas .htaccess no se aplican en este servidor.';
    // Si el servidor no puede llamarse a sí mismo, la comprobación no es concluyente: se dice, no se da por buena
    $unreach = 'No se pudo conectar con ' . $base . ' para hacer la comprobación (revise la dirección en Ajustes o compruébelo a mano desde otro equipo).';
    if ($code === 0 && !in_array($unreach, $problems, true)) $problems[] = $unreach;
    return $problems;
}
