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

/** Solo direcciones http(s) públicas: evita que el servidor pida recursos de su red interna. */
function media_url_allowed(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    if (!empty(cfg()['allow_private_fetch'])) return true;
    $ips = @gethostbynamel($p['host']) ?: [];
    if (!$ips) return false;
    foreach ($ips as $ip)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}

function http_download(string $url, int $timeout = 20): string
{
    if (!media_url_allowed($url)) throw new RuntimeException('Dirección no válida o no accesible.');
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $data = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'BezaresMailing/1.1', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$data) {
                $data .= $chunk;
                return strlen($data) > MEDIA_MAX ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') {
            static $hops = 0;
            if (++$hops > 3) { $hops = 0; throw new RuntimeException('Demasiadas redirecciones.'); }
            try { return http_download($loc, $timeout); } finally { $hops = 0; }
        }
        if ($ok === false || $code !== 200) throw new RuntimeException($code ? "El servidor respondió $code." : "No se pudo descargar ($err).");
        return $data;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 0, 'user_agent' => 'BezaresMailing/1.1']]);
    $data = @file_get_contents($url, false, $ctx, 0, MEDIA_MAX + 1);
    if ($data === false) throw new RuntimeException('No se pudo descargar la imagen.');
    return $data;
}

/** Descarga una imagen de otra web y la guarda en la biblioteca. Devuelve el nombre del archivo. */
function media_fetch_url(string $url): string
{
    $data = http_download($url);
    $tmp = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($tmp, $data);
    try {
        $ext = media_check($tmp);
        $name = media_name(rawurldecode(basename((string)parse_url($url, PHP_URL_PATH))), $ext);
        if (!@copy($tmp, media_dir() . '/' . $name)) throw new RuntimeException('No se pudo guardar (permisos de la carpeta uploads).');
        return $name;
    } finally {
        @unlink($tmp);
    }
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
            if (!preg_match('#^https?://#i', $u) || str_starts_with($u, $own) || str_contains($u, 'placehold.co')) continue;
            $out[$u][] = $e['name'];
        }
    }
    ksort($out);
    return $out;
}

/** Copia a la biblioteca las imágenes indicadas y actualiza los correos que las usan. */
function media_localize(array $urls): array
{
    $res = ['ok' => 0, 'errors' => []];
    foreach (array_unique($urls) as $u) {
        try {
            $new = media_url(media_fetch_url($u));
            foreach (all('SELECT id, header_image, body FROM emails') as $e) {
                $body = str_replace([$u, e($u)], $new, (string)$e['body']);
                $head = $e['header_image'] === $u ? $new : $e['header_image'];
                if ($body !== (string)$e['body'] || $head !== $e['header_image'])
                    q('UPDATE emails SET body = ?, header_image = ? WHERE id = ?', [$body, $head, $e['id']]);
            }
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
    if ($v >= 5) return;
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
    set_setting('schema', '5');
    if ($v < 4 && function_exists('nps_bilingual_setup')) { try { nps_bilingual_setup(); } catch (Throwable $e) {} }
    try { nps_notify_setup(); } catch (Throwable $e) {}
}
