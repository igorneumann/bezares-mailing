<?php
declare(strict_types=1);

/* Biblioteca propia de imágenes y documentos: los archivos viven en /uploads y se sirven desde este mismo servidor. */

const MEDIA_TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
const MEDIA_MAX = 8 * 1024 * 1024;
/* Documentos que se pueden adjuntar a los correos: extensión => [tipo MIME, etiqueta] */
const DOC_TYPES = [
    'pdf' => ['application/pdf', 'PDF'],
    'doc' => ['application/msword', 'Word'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Word'],
    'xls' => ['application/vnd.ms-excel', 'Excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Excel'],
];
const DOC_MAX = 10 * 1024 * 1024;

function media_dir(): string { return ROOT . '/uploads'; }
function media_url(string $name): string { return base_url() . '/uploads/' . rawurlencode($name); }
function media_is_doc(string $name): bool { return isset(DOC_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))]); }
function media_mime(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return DOC_TYPES[$ext][0] ?? (['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream');
}

/** $kind: 'image', 'file' (documentos) o '' (todo). */
function media_list(string $kind = ''): array
{
    $out = [];
    foreach (glob(media_dir() . '/*.{png,jpg,jpeg,gif,webp,pdf,doc,docx,xls,xlsx}', GLOB_BRACE) ?: [] as $f) {
        $name = basename($f);
        $doc = media_is_doc($name);
        if (($kind === 'image' && $doc) || ($kind === 'file' && !$doc)) continue;
        $i = $doc ? null : @getimagesize($f);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $out[] = ['name' => $name, 'url' => media_url($name), 'size' => filesize($f), 'kind' => $doc ? 'file' : 'image',
            'ext' => $ext, 'label' => $doc ? DOC_TYPES[$ext][1] : 'Imagen', 'w' => $i[0] ?? 0, 'h' => $i[1] ?? 0, 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
function media_size(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '') . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** Nombre de archivo limpio y que no pise a otro existente. */
function media_name(string $original, string $ext): string
{
    $base = pathinfo($original, PATHINFO_FILENAME);
    $base = strtr(mb_strtolower($base), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base), '-') ?: 'archivo';
    $base = substr($base, 0, 60);
    $name = "$base.$ext";
    for ($i = 2; file_exists(media_dir() . '/' . $name); $i++) $name = "$base-$i.$ext";
    return $name;
}

/** Valida que los datos sean una imagen admitida y devuelve su extensión. */
function media_check(string $path): string
{
    if (filesize($path) > MEDIA_MAX) throw new RuntimeException('La imagen supera los 8 MB.');
    $info = @getimagesize($path);
    if (!$info || !isset(MEDIA_TYPES[$info[2]])) throw new RuntimeException('Solo se admiten imágenes PNG, JPG, GIF o WebP.');
    return MEDIA_TYPES[$info[2]];
}

/** Valida un documento (PDF, Word o Excel) por extensión y por su contenido real. Devuelve la extensión. */
function media_check_doc(string $path, string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isset(DOC_TYPES[$ext])) throw new RuntimeException('Tipo de archivo no admitido. Se aceptan imágenes, PDF, Word (.doc, .docx) y Excel (.xls, .xlsx).');
    if (filesize($path) > DOC_MAX) throw new RuntimeException('El documento supera los 10 MB.');
    $head = (string)file_get_contents($path, false, null, 0, 8);
    $ok = match ($ext) {
        'pdf' => str_starts_with($head, '%PDF'),
        'docx', 'xlsx' => str_starts_with($head, "PK\x03\x04"),
        default => str_starts_with($head, "\xD0\xCF\x11\xE0"),
    };
    if (!$ok) throw new RuntimeException('El contenido del archivo no corresponde a un documento ' . DOC_TYPES[$ext][1] . ' válido.');
    return $ext;
}

function media_store_upload(array $f, bool $allowDocs = true): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'El archivo supera el tamaño máximo que permite el servidor.' : 'No se ha recibido el archivo.');
    }
    if ($allowDocs && media_is_doc((string)$f['name'])) $ext = media_check_doc($f['tmp_name'], (string)$f['name']);
    else {
        try { $ext = media_check($f['tmp_name']); }
        catch (RuntimeException $e) {
            if ($allowDocs && !@getimagesize($f['tmp_name'])) throw new RuntimeException('Tipo de archivo no admitido. Se aceptan imágenes, PDF, Word (.doc, .docx) y Excel (.xls, .xlsx).');
            throw $e;
        }
    }
    $name = media_name((string)$f['name'], $ext);
    if (!move_uploaded_file($f['tmp_name'], media_dir() . '/' . $name))
        throw new RuntimeException('No se pudo guardar (revise los permisos de la carpeta uploads).');
    return $name;
}

/* ---------- Imágenes de cabecera ----------
   En la Biblioteca se marcan las imágenes que sirven de cabecera de las newsletters (ajuste header_images, lista de
   nombres de archivo); el generador solo ofrece esas. La cabecera por defecto (NEWSLETTER_HEADER) lo es siempre. */

/** Nombres marcados como cabecera (pueden incluir archivos que ya no existen). */
function header_names(): array
{
    $list = json_decode(setting('header_images'), true);
    $out = [];
    foreach (is_array($list) ? $list : [] as $n) if (($n = basename((string)$n)) !== '') $out[$n] = true;
    return array_keys($out);
}
function is_header_image(string $name): bool
{
    return $name === NEWSLETTER_HEADER || in_array($name, header_names(), true);
}
/** Marca o desmarca una imagen como cabecera. */
function header_set(string $name, bool $on): void
{
    $list = array_values(array_diff(header_names(), [$name]));
    if ($on) $list[] = $name;
    set_setting('header_images', json_encode($list, JSON_UNESCAPED_UNICODE));
}
/** Imágenes de la biblioteca marcadas como cabecera (solo las que existen), las más recientes primero. */
function header_images(): array
{
    return array_values(array_filter(media_list('image'), fn($m) => is_header_image($m['name'])));
}

/* ---------- Adjuntos de los correos ---------- */

/** Nombres de archivo adjuntos a un correo (solo los que siguen existiendo en la biblioteca). */
function email_attachments(array $email): array
{
    $list = json_decode((string)($email['attachments'] ?? ''), true);
    $out = [];
    foreach (is_array($list) ? $list : [] as $n) {
        $n = basename((string)$n);
        if ($n !== '' && preg_match('/\.(png|jpe?g|gif|webp|pdf|docx?|xlsx?)$/i', $n) && is_file(media_dir() . '/' . $n)) $out[] = $n;
    }
    return array_values(array_unique($out));
}

/* ---------- Descargas desde otras webs (protección contra SSRF) ----------
   - Solo http/https, solo puertos 80 y 443, sin usuario:contraseña en la dirección.
   - Se resuelven TODAS las IP del nombre (IPv4 e IPv6) y todas tienen que ser públicas.
   - La conexión se fija a la IP ya validada (CURLOPT_RESOLVE), así un cambio de DNS entre la comprobación
     y la conexión (DNS rebinding) no puede llevarla a otra máquina. Después se verifica la IP realmente usada.
   - Las redirecciones se siguen a mano (máximo 3) y cada destino se vuelve a validar igual.
   - No se usa ningún proxy del entorno. No hay ninguna opción para permitir redes privadas. */

const FETCH_PORTS = ['http' => 80, 'https' => 443];

/** Valida una dirección y devuelve [esquema, host, puerto, IP validada]. Lanza una excepción si no se permite. */
function safe_target(string $url): array
{
    $p = parse_url(trim($url));
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if (!$p || !isset(FETCH_PORTS[$scheme])) throw new RuntimeException('Solo se admiten direcciones http o https.');
    if (isset($p['user']) || isset($p['pass'])) throw new RuntimeException('La dirección no puede incluir usuario ni contraseña.');
    $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
    if ($host === '' || !preg_match('/^[a-z0-9.\-:]+$/', $host)) throw new RuntimeException('Dirección no válida.');
    $port = (int)($p['port'] ?? FETCH_PORTS[$scheme]);
    if (!in_array($port, FETCH_PORTS, true)) throw new RuntimeException('Solo se admiten los puertos 80 y 443.');
    $ips = resolve_host($host);
    if (!$ips) throw new RuntimeException('No se encuentra el servidor ' . $host . '.');
    foreach ($ips as $ip) {
        if (!ip_is_public($ip)) throw new RuntimeException('La dirección apunta a una red privada, local o reservada: no está permitido.');
    }
    // Se prefiere IPv4 (más compatible en hostings compartidos)
    usort($ips, fn($a, $b) => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));
    return [$scheme, $host, $port, $ips[0]];
}

/** Petición GET conectando obligatoriamente a $ip. Devuelve [código HTTP, cuerpo, Location absoluta]. */
function http_fetch_pinned(string $url, string $host, int $port, string $ip, int $timeout): array
{
    $data = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'BezaresMailing/2.7',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$data) {
            $data .= $chunk;
            return strlen($data) > MEDIA_MAX ? 0 : strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $used = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    $err = curl_error($ch);
    curl_close($ch);
    if ($used !== '' && inet_pton($used) !== inet_pton($ip)) throw new RuntimeException('La conexión no se hizo con la dirección validada.');
    if ($ok === false && !($code >= 300 && $code < 400)) {
        throw new RuntimeException(strlen($data) > MEDIA_MAX ? 'El archivo supera los 8 MB.' : 'No se pudo descargar (' . $err . ').');
    }
    return [$code, $data, $loc];
}

function http_download(string $url, int $timeout = 20): string
{
    if (!function_exists('curl_init')) throw new RuntimeException('El servidor necesita la extensión cURL de PHP para descargar archivos de otras webs.');
    for ($hop = 0; $hop <= 3; $hop++) {
        [, $host, $port, $ip] = safe_target($url);
        [$code, $data, $loc] = http_fetch_pinned($url, $host, $port, $ip, $timeout);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($code !== 200) throw new RuntimeException('El servidor respondió ' . $code . '.');
        return $data;
    }
    throw new RuntimeException('Demasiadas redirecciones.');
}

/* Direcciones ya copiadas a la biblioteca (url => archivo), para no descargar otra vez la misma imagen. */
function media_sources(): array
{
    $m = json_decode(setting('media_sources'), true);
    return is_array($m) ? $m : [];
}
function media_source_set(string $url, string $name): void
{
    $m = media_sources();
    unset($m[$url]);
    $m[$url] = $name;
    if (count($m) > 2000) $m = array_slice($m, -2000, null, true); // las más recientes
    set_setting('media_sources', json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Archivo de la biblioteca con exactamente el mismo contenido que $path ('' si no hay ninguno). */
function media_find_same(string $path, string $except = ''): string
{
    $size = filesize($path);
    $hash = null;
    foreach (glob(media_dir() . '/*.{png,jpg,jpeg,gif,webp,pdf,doc,docx,xls,xlsx}', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === $except || filesize($f) !== $size) continue; // primero por tamaño: no hace falta leer todos
        $hash ??= sha1_file($path);
        if (sha1_file($f) === $hash) return basename($f);
    }
    return '';
}

/**
 * Copia a la biblioteca una imagen de otra web y devuelve el nombre del archivo. Si esa dirección ya se copió, o la
 * biblioteca ya tiene una imagen idéntica (mismo contenido), devuelve la existente en vez de crear un duplicado.
 */
function media_fetch_url(string $url): string
{
    $known = media_sources()[$url] ?? '';
    if ($known !== '' && is_file(media_dir() . '/' . basename($known))) return basename($known);
    $data = http_download($url);
    $tmp = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($tmp, $data);
    try {
        $ext = media_check($tmp);
        $name = media_find_same($tmp);
        if ($name === '') {
            $name = media_name(rawurldecode(basename((string)parse_url($url, PHP_URL_PATH))), $ext);
            if (!@copy($tmp, media_dir() . '/' . $name)) throw new RuntimeException('No se pudo guardar (permisos de la carpeta uploads).');
        }
        media_source_set($url, $name);
        return $name;
    } finally {
        @unlink($tmp);
    }
}

/** Nombres de archivo usados en algún correo (imagen de cabecera, cuerpo o adjunto), sin consultar uno a uno. */
function media_used_names(): array
{
    $used = [];
    $hay = '';
    foreach (all('SELECT header_image, body FROM emails') as $e) $hay .= "\n" . $e['header_image'] . "\n" . $e['body'];
    foreach (media_list() as $m) {
        $n = $m['name'];
        if (str_contains($hay, '/uploads/' . rawurlencode($n)) || str_contains($hay, '/uploads/' . $n)) $used[$n] = true;
    }
    try {
        foreach (all("SELECT attachments FROM emails WHERE attachments IS NOT NULL AND attachments <> ''") as $e)
            foreach (email_attachments($e) as $n) $used[$n] = true;
    } catch (Throwable $e) {}
    return $used;
}

/**
 * Grupos de archivos idénticos (mismo contenido). En cada grupo se conserva uno: el que se usa en correos, el logo o
 * las cabeceras marcadas no se tocan nunca; si ninguno se usa, se queda el más antiguo. Devuelve [[conservar...], [borrar...]] por grupo.
 */
function media_duplicates(): array
{
    $bySize = [];
    foreach (media_list() as $m) $bySize[$m['size']][] = $m;
    $used = null;
    $groups = [];
    foreach ($bySize as $files) {
        if (count($files) < 2) continue;
        $byHash = [];
        foreach ($files as $m) $byHash[sha1_file(media_dir() . '/' . $m['name'])][] = $m;
        foreach ($byHash as $same) {
            if (count($same) < 2) continue;
            $used ??= media_used_names();
            $protect = fn($m) => isset($used[$m['name']]) || $m['name'] === setting('logo') || is_header_image($m['name']);
            usort($same, fn($a, $b) => $a['time'] <=> $b['time'] ?: strlen($a['name']) <=> strlen($b['name']));
            $keep = array_values(array_filter($same, $protect));
            if (!$keep) $keep = [$same[0]];
            $drop = array_values(array_filter($same, fn($m) => !in_array($m, $keep, true)));
            if ($drop) $groups[] = ['keep' => array_column($keep, 'name'), 'drop' => array_column($drop, 'name')];
        }
    }
    return $groups;
}

/** Borra los duplicados que no se usan en ningún correo. Devuelve [archivos borrados, bytes liberados]. */
function media_remove_duplicates(): array
{
    $n = 0; $bytes = 0;
    $map = media_sources();
    foreach (media_duplicates() as $g) {
        foreach ($g['drop'] as $name) {
            $path = media_dir() . '/' . basename($name);
            $size = (int)@filesize($path);
            if (is_file($path) && @unlink($path)) {
                $n++; $bytes += $size;
                // Las direcciones que apuntaban al borrado pasan a apuntar al que se conserva
                foreach ($map as $u => $f) if ($f === $name) $map[$u] = $g['keep'][0];
            }
        }
    }
    if ($n) set_setting('media_sources', json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return [$n, $bytes];
}

/** Imágenes de otros servidores usadas en los correos: [url => [nombres de correos]]. */
function media_external_urls(): array
{
    $own = base_url() . '/';
    $out = [];
    foreach (all('SELECT id, name, header_image, body FROM emails') as $e) {
        $urls = [];
        if ($e['header_image'] !== '') $urls[] = $e['header_image'];
        if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*"([^"]+)"/i', (string)$e['body'], $m)) $urls = array_merge($urls, $m[1]);
        foreach (array_unique($urls) as $u) {
            $u = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^https?://#i', $u) || str_contains($u, 'placehold.co')) continue;
            // Las de esta misma dirección solo se avisan si no existen aquí (p. ej. rutas del sistema anterior
            // en el mismo dominio, como /media/images/…, que este ya no sirve)
            if (str_starts_with($u, $own) && !preg_match('#^' . preg_quote($own, '#') . '(media/images/|mautic/)#i', $u)) continue;
            $out[$u][] = $e['name'];
        }
    }
    ksort($out);
    return $out;
}

/**
 * Si la URL es de un archivo de esta biblioteca servido desde otra dirección (p. ej. la anterior a un cambio de
 * dominio: https://mailing2.bezares.com/uploads/foto.png) devuelve el nombre del archivo; si no, ''.
 */
function media_relocatable(string $u): string
{
    $path = (string)parse_url($u, PHP_URL_PATH);
    $sameHost = strtolower((string)parse_url($u, PHP_URL_HOST)) === strtolower((string)parse_url(base_url(), PHP_URL_HOST));
    // En otro host, solo rutas /uploads/archivo (como las de esta aplicación); en el propio, cualquier ruta antigua
    if (!preg_match($sameHost ? '#/([^/]+)$#' : '#/uploads/([^/]+)$#', $path, $m)) return '';
    $name = basename(rawurldecode($m[1]));
    return $name !== '' && $name[0] !== '.' && is_file(media_dir() . '/' . $name) ? $name : '';
}

/** Cambia en los correos una URL por otra (cabecera y cuerpo, también en su forma con entidades HTML). */
function media_replace_url(string $old, string $new): int
{
    $n = 0;
    foreach (all('SELECT id, header_image, body FROM emails') as $e) {
        $body = str_replace([$old, e($old)], [$new, e($new)], (string)$e['body']);
        $head = $e['header_image'] === $old ? $new : $e['header_image'];
        if ($body !== (string)$e['body'] || $head !== $e['header_image']) {
            q('UPDATE emails SET body = ?, header_image = ? WHERE id = ?', [$body, $head, $e['id']]);
            $n++;
        }
    }
    return $n;
}

/** Copia a la biblioteca las imágenes indicadas y actualiza los correos que las usan. */
function media_localize(array $urls): array
{
    $res = ['ok' => 0, 'errors' => []];
    foreach (array_unique($urls) as $u) {
        try {
            media_replace_url($u, media_url(media_fetch_url($u)));
            $res['ok']++;
        } catch (Throwable $e) {
            $res['errors'][] = basename((string)parse_url($u, PHP_URL_PATH)) . ': ' . $e->getMessage();
        }
    }
    return $res;
}

/** Correos que usan un archivo de la biblioteca (como imagen o como adjunto). */
function media_usage(string $name): array
{
    $u = media_url($name);
    $out = array_column(all('SELECT name FROM emails WHERE header_image = ? OR body LIKE ?', [$u, '%' . $u . '%']), 'name');
    try {
        foreach (all("SELECT name, attachments FROM emails WHERE attachments IS NOT NULL AND attachments <> ''") as $e)
            if (in_array($name, email_attachments($e), true)) $out[] = $e['name'] . ' (adjunto)';
    } catch (Throwable $e) {}
    return array_values(array_unique($out));
}

/** Añade las columnas nuevas a instalaciones anteriores. */
function migrate(): void
{
    $v = (int)setting('schema', '1');
    if ($v >= 11) return;
    $try = function (string $sql) { try { db()->exec($sql); } catch (Throwable $e) { /* ya existe */ } };
    if ($v < 2) $try('ALTER TABLE emails ADD COLUMN attachments TEXT');
    // v3: tabla de campañas NPS automáticas (CREATE TABLE IF NOT EXISTS del esquema completo)
    foreach (schema_sql(cfg()['driver'] ?? 'sqlite') as $sql) if (str_starts_with($sql, 'CREATE TABLE')) $try($sql);
    // v4: encuestas y correos en dos idiomas
    foreach (["emails ADD COLUMN lang VARCHAR(5) NOT NULL DEFAULT 'es'", 'surveys ADD COLUMN title_en VARCHAR(255)', 'surveys ADD COLUMN description_en TEXT',
        'surveys ADD COLUMN thanks_en TEXT', 'questions ADD COLUMN label_en TEXT', 'questions ADD COLUMN options_en TEXT', 'responses ADD COLUMN lang VARCHAR(5)',
        'nps_runs ADD COLUMN email_en_id INT', 'nps_runs ADD COLUMN send_en_id INT'] as $col) $try("ALTER TABLE $col");
    // v5: aviso por correo de las respuestas de cada encuesta
    foreach (["notify_mode VARCHAR(10) NOT NULL DEFAULT ''", 'notify_max INT NOT NULL DEFAULT 6', "notify_es VARCHAR(500) NOT NULL DEFAULT ''",
        "notify_en VARCHAR(500) NOT NULL DEFAULT ''"] as $col) $try("ALTER TABLE surveys ADD COLUMN $col");
    // v6: seguridad. Tokens de confirmación de un solo uso, respuestas verificadas, límites de frecuencia
    $try('ALTER TABLE responses ADD COLUMN verified INT NOT NULL DEFAULT 1');
    $try('CREATE INDEX ix_throttle ON throttle (k, at)');
    if ($v < 6) {
        // Las encuestas NPS solo admiten respuestas desde el enlace personal del correo
        q("UPDATE surveys SET is_public = 0 WHERE slug = 'nps' OR title LIKE 'NPS-Q%'");
    }
    set_setting('schema', '6');
    // v7: bajas por listado. Cada envío recuerda su etiqueta y cada contacto, de qué etiquetas se ha dado de baja.
    $try("ALTER TABLE contacts ADD COLUMN unsub_tags VARCHAR(500) NOT NULL DEFAULT ''");
    $try("ALTER TABLE sends ADD COLUMN tag VARCHAR(190) NOT NULL DEFAULT ''");
    if ($v < 7) {
        // Envíos anteriores: el segmento era el nombre de la etiqueta salvo en los envíos a todos y los recordatorios
        foreach (all("SELECT id, segment FROM sends WHERE tag = ''") as $r) {
            $seg = mb_strtolower(trim((string)$r['segment']));
            if ($seg === '' || in_array($seg, ['todos los suscritos', 'todos', 'recordatorio: sin respuesta', 'reminder: no answer'], true) || str_contains($seg, ' + ')) continue;
            if ((int)val('SELECT COUNT(*) FROM contacts WHERE tags LIKE ?', ['%,' . $seg . ',%']) > 0) q('UPDATE sends SET tag = ? WHERE id = ?', [$seg, $r['id']]);
        }
    }
    set_setting('schema', '7');
    // v8: seguimiento de clics para las estadísticas (CREATE TABLE de arriba crea la tabla clicks)
    $try('ALTER TABLE queue ADD COLUMN clicked_at VARCHAR(19)');
    $try('ALTER TABLE queue ADD COLUMN clicks INT NOT NULL DEFAULT 0');
    $try('ALTER TABLE queue ADD COLUMN unsub_at VARCHAR(19)'); // baja desde ese correo
    $try('CREATE INDEX ix_queue_sent ON queue (sent_at)');
    $try('CREATE INDEX ix_clicks_queue ON clicks (queue_id)');
    set_setting('schema', '8');
    // v9: correos de ejemplo de los temas «Moderno»
    if ($v < 9) { try { modern_seed(); } catch (Throwable $e) {} }
    set_setting('schema', '9');
    // v10: correo de agradecimiento al cliente que responde una encuesta (p. ej. pedir una reseña en Google)
    $try('ALTER TABLE surveys ADD COLUMN thank_es INT');
    $try('ALTER TABLE surveys ADD COLUMN thank_en INT');
    $try('ALTER TABLE surveys ADD COLUMN thank_min INT NOT NULL DEFAULT 0');
    if ($v < 10) { try { thanks_seed(); } catch (Throwable $e) {} }
    set_setting('schema', '10');
    // v11: «Mantener la sesión iniciada» con fichas en la base de datos (la tabla la crea el CREATE TABLE de arriba)
    $try('CREATE INDEX ix_remember_user ON remember_tokens (user_id)');
    set_setting('schema', '11');
    if ($v < 4 && function_exists('nps_bilingual_setup')) { try { nps_bilingual_setup(); } catch (Throwable $e) {} }
    try { nps_notify_setup(); } catch (Throwable $e) {}
}
