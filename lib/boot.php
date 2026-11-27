<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Madrid');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/security.php';

/* Ninguna respuesta de la aplicación se puede guardar en caché: hay hostings (Plesk con «caché de nginx») que, si no
   se indica, sirven la respuesta guardada sin ejecutar PHP, y entonces cron.php no hace nada, las aperturas no se
   cuentan y los enlaces de baja o de encuesta muestran páginas viejas. Cada página puede cambiarlo si lo necesita. */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
    header('X-Accel-Expires: 0'); // nginx como proxy: no guardar en su caché
}

/* ---------- Configuración y base de datos ---------- */

function cfg(): ?array
{
    static $c = false;
    if ($c === false) {
        $f = ROOT . '/data/config.php';
        $c = is_file($f) ? require $f : null;
    }
    return $c;
}

function db_connect(array $c): PDO
{
    if (($c['driver'] ?? 'sqlite') === 'mysql') {
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'], $c['pass']
        );
    } else {
        $dir = rtrim((string)($c['data_dir'] ?? '') !== '' ? (string)$c['data_dir'] : ROOT . '/data', '/\\');
        $pdo = new PDO('sqlite:' . $dir . '/' . basename((string)$c['file']));
        $pdo->exec('PRAGMA busy_timeout=8000');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function db(?PDO $set = null): PDO
{
    static $pdo;
    if ($set) $pdo = $set;
    if (!$pdo) $pdo = db_connect(cfg());
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
function insert(string $sql, array $p = []): int { q($sql, $p); return (int)db()->lastInsertId(); }

function schema_sql(string $driver): array
{
    $pk  = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $end = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $t = [
        "settings (k VARCHAR(60) PRIMARY KEY, v TEXT)",
        "users (id $pk, email VARCHAR(190) NOT NULL, pass_hash VARCHAR(255) NOT NULL)",
        "contacts (id $pk, email VARCHAR(190) NOT NULL UNIQUE, nombre VARCHAR(190) NOT NULL DEFAULT '',
            apellidos VARCHAR(190) NOT NULL DEFAULT '', empresa VARCHAR(190) NOT NULL DEFAULT '',
            tags VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'subscribed',
            token VARCHAR(40) NOT NULL, created_at VARCHAR(19) NOT NULL, unsub_at VARCHAR(19),
            unsub_tags VARCHAR(500) NOT NULL DEFAULT '')",
        "emails (id $pk, name VARCHAR(190) NOT NULL, subject VARCHAR(255) NOT NULL DEFAULT '',
            preheader VARCHAR(255) NOT NULL DEFAULT '', theme VARCHAR(60) NOT NULL DEFAULT 'comunicado',
            header_image VARCHAR(500) NOT NULL DEFAULT '',
            body MEDIUMTEXT, attachments TEXT, lang VARCHAR(5) NOT NULL DEFAULT 'es', survey_id INT, created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL)",
        "sends (id $pk, email_id INT NOT NULL, segment VARCHAR(190) NOT NULL DEFAULT '', total INT NOT NULL DEFAULT 0,
            created_at VARCHAR(19) NOT NULL, tag VARCHAR(190) NOT NULL DEFAULT '')",
        "queue (id $pk, send_id INT NOT NULL, email_id INT NOT NULL, contact_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending', error VARCHAR(500), sent_at VARCHAR(19), opened_at VARCHAR(19),
            clicked_at VARCHAR(19), clicks INT NOT NULL DEFAULT 0, unsub_at VARCHAR(19))",
        "clicks (id $pk, queue_id INT NOT NULL, url VARCHAR(1000) NOT NULL, at VARCHAR(19) NOT NULL)",
        "surveys (id $pk, slug VARCHAR(80) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL, description TEXT,
            thanks TEXT, max_per_contact INT NOT NULL DEFAULT 1, active INT NOT NULL DEFAULT 1,
            is_public INT NOT NULL DEFAULT 1, created_at VARCHAR(19) NOT NULL, title_en VARCHAR(255), description_en TEXT, thanks_en TEXT,
            notify_mode VARCHAR(10) NOT NULL DEFAULT '', notify_max INT NOT NULL DEFAULT 6, notify_es VARCHAR(500) NOT NULL DEFAULT '', notify_en VARCHAR(500) NOT NULL DEFAULT '',
            thank_es INT, thank_en INT, thank_min INT NOT NULL DEFAULT 0)",
        "questions (id $pk, survey_id INT NOT NULL, position INT NOT NULL DEFAULT 0, type VARCHAR(20) NOT NULL,
            label TEXT, options TEXT, is_required INT NOT NULL DEFAULT 0, label_en TEXT, options_en TEXT)",
        "responses (id $pk, survey_id INT NOT NULL, contact_id INT, email VARCHAR(190) NOT NULL, created_at VARCHAR(19) NOT NULL, lang VARCHAR(5),
            verified INT NOT NULL DEFAULT 1)",
        "answers (id $pk, response_id INT NOT NULL, question_id INT NOT NULL, value TEXT)",
        "throttle (id $pk, k VARCHAR(190) NOT NULL, at INT NOT NULL)",
        "confirmations (id $pk, hash VARCHAR(64) NOT NULL UNIQUE, contact_id INT NOT NULL, tags VARCHAR(500) NOT NULL DEFAULT '',
            data TEXT, created_at INT NOT NULL)",
        "nps_runs (id $pk, label VARCHAR(60) NOT NULL UNIQUE, email_id INT, survey_id INT, reminder_email_id INT, send_id INT,
            tag VARCHAR(190) NOT NULL DEFAULT '', sent_at VARCHAR(19) NOT NULL, reminder_at VARCHAR(19), results_at VARCHAR(19),
            reminder_done INT NOT NULL DEFAULT 0, results_done INT NOT NULL DEFAULT 0, note VARCHAR(500), email_en_id INT, send_en_id INT)",
        "remember_tokens (id $pk, user_id INT NOT NULL, selector VARCHAR(24) NOT NULL UNIQUE, hash VARCHAR(64) NOT NULL, expires INT NOT NULL)",
    ];
    $sql = [];
    foreach ($t as $def) $sql[] = "CREATE TABLE IF NOT EXISTS $def$end";
    $sql[] = "CREATE INDEX ix_contacts_token ON contacts (token)";
    $sql[] = "CREATE INDEX ix_queue_status ON queue (status, id)";
    $sql[] = "CREATE INDEX ix_queue_email ON queue (email_id, contact_id)";
    $sql[] = "CREATE INDEX ix_resp_survey ON responses (survey_id, email)";
    $sql[] = "CREATE INDEX ix_answers_resp ON answers (response_id)";
    $sql[] = "CREATE INDEX ix_throttle ON throttle (k, at)";
    $sql[] = "CREATE INDEX ix_queue_sent ON queue (sent_at)";
    $sql[] = "CREATE INDEX ix_clicks_queue ON clicks (queue_id)";
    $sql[] = "CREATE INDEX ix_questions_survey ON questions (survey_id)";
    $sql[] = "CREATE INDEX ix_remember_user ON remember_tokens (user_id)";
    return $sql;
}

/* ---------- Ajustes ---------- */

function setting(string $k, string $default = ''): string
{
    static $cache = null;
    if ($k === '__reset') { $cache = null; return ''; }
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT k, v FROM settings') as $r) $cache[$r['k']] = (string)$r['v'];
    }
    return $cache[$k] ?? $default;
}
function set_setting(string $k, string $v): void
{
    q('DELETE FROM settings WHERE k = ?', [$k]);
    q('INSERT INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
    setting('__reset');
}

/* ---------- Utilidades ---------- */

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function fecha(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : ''; }
function token(int $bytes = 16): string { return bin2hex(random_bytes($bytes)); }

/** Dirección deducida de la petición. Solo se usa en la instalación, como valor propuesto; después manda la de Ajustes. */
function detect_base_url(): string
{
    $https = is_https();
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$|^\[[0-9a-f:]+\](:\d{1,5})?$/i', $host)) $host = 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
/**
 * Dirección de la aplicación fijada en data/config.php ('base_url' => 'https://mailing.bezares.com').
 * Es la única que se usa para redirigir al dominio correcto, así que cambiar de dominio es editar esa línea.
 */
function config_base_url(): string
{
    return rtrim(trim((string)(cfg()['base_url'] ?? '')), '/');
}
/** Dirección guardada: la de config.php o, en instalaciones anteriores a la 2.15, la que quedó en Ajustes. */
function stored_base_url(): string
{
    if (!cfg()) return '';
    $b = config_base_url();
    return $b !== '' ? $b : rtrim((string)setting('base_url'), '/');
}
function base_url(): string
{
    $b = stored_base_url();
    return rtrim($b !== '' ? $b : detect_base_url(), '/');
}
/** true si la dirección es válida para la aplicación (http/https, sin consulta, fragmento ni usuario). */
function valid_base_url(string $base): bool
{
    $bp = parse_url($base);
    return (bool)filter_var($base, FILTER_VALIDATE_URL) && in_array(strtolower($bp['scheme'] ?? ''), ['http', 'https'], true)
        && !isset($bp['query']) && !isset($bp['fragment']) && !isset($bp['user']);
}
/** Cambia claves de data/config.php (null = quitarla). Devuelve false si el archivo no se puede escribir. */
function write_config(array $changes): bool
{
    $f = ROOT . '/data/config.php';
    $c = cfg();
    if (!$c || !is_writable($f)) return false;
    foreach ($changes as $k => $v) { if ($v === null) unset($c[$k]); else $c[$k] = $v; }
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX) === false) return false;
    @chmod($tmp, fileperms($f) & 0777);
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    return true;
}
function redirect(string $to): void { header('Location: ' . $to); exit; }
function flash(?string $msg = null, string $type = 'ok'): array
{
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return []; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = token();
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf() . '">'; }
function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('Sesión caducada. Vuelva atrás y recargue la página.');
    }
}
function start_session(): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('bzmailing');
    // Secure siempre que la dirección configurada sea https, aunque PHP no detecte bien el HTTPS detrás de un proxy
    // SameSite=None solo si la aplicación se incrusta desde otro sitio de confianza (Ajustes → URLs de confianza)
    $ss = cookie_samesite();
    session_set_cookie_params(['httponly' => true, 'samesite' => $ss, 'path' => cookie_path(), 'secure' => $ss === 'None' || base_is_https() || is_https()]);
    partition_cookies();
    session_start();
}
/* ---------- "Mantener la sesión iniciada" ----------
   Cada navegador recordado tiene su ficha en la tabla remember_tokens (30 días): la cookie lleva un identificador y
   un secreto, y en la base de datos solo se guarda el hash del secreto. Cerrar sesión borra la ficha de ese navegador
   y cambiar la contraseña borra todas las del usuario, así una cookie copiada deja de valer en el momento. */
const REMEMBER_COOKIE = 'bzmailing_rec';
const REMEMBER_DAYS = 30;
function remember_cookie(string $value, int $expires): void
{
    $ss = cookie_samesite();
    setcookie(REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => cookie_path(), 'httponly' => true, 'samesite' => $ss,
        'secure' => $ss === 'None' || base_is_https() || is_https()]);
    partition_cookies();
}
/** [identificador, secreto] de la cookie actual, o null si no tiene el formato esperado. */
function remember_parts(): ?array
{
    $parts = explode('.', (string)($_COOKIE[REMEMBER_COOKIE] ?? ''));
    if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{18}$/', $parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) return null;
    return $parts;
}
function remember_set(array $user): void
{
    remember_forget_current();
    $exp = time() + REMEMBER_DAYS * 86400;
    $selector = token(9);
    $secret = token(32);
    q('DELETE FROM remember_tokens WHERE expires < ?', [time()]);
    q('INSERT INTO remember_tokens (user_id, selector, hash, expires) VALUES (?, ?, ?, ?)',
        [(int)$user['id'], $selector, hash('sha256', $secret), $exp]);
    remember_cookie($selector . '.' . $secret, $exp);
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['rs'] = $selector;
}
/** Borra la ficha de este navegador (si la tiene). */
function remember_forget_current(): void
{
    if ($p = remember_parts()) { try { q('DELETE FROM remember_tokens WHERE selector = ?', [$p[0]]); } catch (Throwable $e) {} }
}
function remember_clear(): void
{
    remember_forget_current();
    if (isset($_COOKIE[REMEMBER_COOKIE])) remember_cookie('', time() - 3600);
}
/** Cierra todos los navegadores recordados del usuario (al cambiar la contraseña). */
function remember_revoke_all(int $uid): void
{
    q('DELETE FROM remember_tokens WHERE user_id = ?', [$uid]);
}
/** Devuelve el id del usuario si la cookie corresponde a una ficha vigente. */
function remember_check(): ?int
{
    if (!($p = remember_parts())) return null;
    $r = one('SELECT user_id, hash, expires FROM remember_tokens WHERE selector = ?', [$p[0]]);
    if (!$r || (int)$r['expires'] < time() || !hash_equals((string)$r['hash'], hash('sha256', $p[1]))) return null;
    return one('SELECT id FROM users WHERE id = ?', [(int)$r['user_id']]) ? (int)$r['user_id'] : null;
}

/* ---------- Sesión iniciada ----------
   La sesión guarda una huella de la contraseña: si la contraseña cambia, las demás sesiones abiertas dejan de valer.
   Si la sesión se abrió con «mantener la sesión iniciada», guarda también su ficha ('rs'): al cerrar sesión en ese
   navegador se borra la ficha y cae cualquier otra sesión abierta con una copia de la cookie. */
function pass_tag(array $user): string
{
    return substr(hash_hmac('sha256', 'session|' . $user['id'] . '|' . $user['pass_hash'], cfg()['secret']), 0, 32);
}
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['pw'] = pass_tag($user);
    unset($_SESSION['rs']); // remember_set() la pone si se marca «mantener la sesión iniciada»
}
/** true si la sesión sigue siendo válida (el usuario existe y no ha cambiado la contraseña desde que se abrió). */
function session_valid(): bool
{
    $u = one('SELECT * FROM users WHERE id = ?', [(int)($_SESSION['uid'] ?? 0)]);
    if (!$u) return false;
    if (!empty($_SESSION['rs']) && !val('SELECT 1 FROM remember_tokens WHERE selector = ?', [(string)$_SESSION['rs']])) return false;
    // Sesiones abiertas antes de esta versión: se les pone la huella actual
    if (empty($_SESSION['pw'])) { $_SESSION['pw'] = pass_tag($u); return true; }
    return hash_equals(pass_tag($u), (string)$_SESSION['pw']);
}

function valid_email(string $s): bool { return (bool)filter_var($s, FILTER_VALIDATE_EMAIL); }
function norm_email(string $s): string { return mb_strtolower(trim($s)); }

/** Etiquetas: se guardan como ",a,b," para poder filtrar con LIKE. */
function tags_pack($tags): string
{
    if (!is_array($tags)) $tags = preg_split('/[,;|]/', (string)$tags);
    $out = [];
    foreach ($tags as $t) {
        $t = mb_strtolower(trim($t));
        if ($t !== '') $out[$t] = true;
    }
    return $out ? ',' . implode(',', array_keys($out)) . ',' : '';
}
function tags_list(string $packed): array { return $packed === '' ? [] : explode(',', trim($packed, ',')); }
/** Listados (etiquetas) de los que el contacto se ha dado de baja. */
function optouts(array $contact): array { return tags_list((string)($contact['unsub_tags'] ?? '')); }
/** ¿Puede recibir un envío dirigido a la etiqueta $tag? ('' = envío a todos los suscritos) */
function can_receive(array $contact, string $tag): bool
{
    if (($contact['status'] ?? '') !== 'subscribed') return false;
    return $tag === '' || !in_array(mb_strtolower($tag), optouts($contact), true);
}
/** Condición SQL: contactos que tienen la etiqueta y NO se han dado de baja de ella. Devuelve [sql, params]. */
function tag_audience_sql(string $tag, string $alias = ''): array
{
    $a = $alias !== '' ? $alias . '.' : '';
    $t = '%,' . mb_strtolower($tag) . ',%';
    return ["{$a}tags LIKE ? AND {$a}unsub_tags NOT LIKE ?", [$t, $t]];
}

function all_tags(): array
{
    $n = [];
    foreach (all("SELECT tags FROM contacts WHERE tags <> ''") as $r)
        foreach (tags_list($r['tags']) as $t) $n[$t] = ($n[$t] ?? 0) + 1;
    ksort($n);
    return $n;
}
function sign(string $data): string { return substr(hash_hmac('sha256', $data, cfg()['secret']), 0, 16); }
/** Clave del modo kiosco del formulario de suscripción; cambia al regenerarla en Automatización. */
function kiosk_key(): string { return sign('kiosk|' . setting('sub_kiosk_gen', '1')); }

function logo_url(): string
{
    $f = setting('logo');
    return $f !== '' && is_file(ROOT . '/uploads/' . $f) ? base_url() . '/uploads/' . rawurlencode($f) : '';
}
function brand_color(): string
{
    $c = setting('color', '#00567d');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#00567d';
}

/* ---------- Plantilla del panel ---------- */

/* ---------- Preferencias del panel ---------- */

const ADMIN_TABS = ['contacts' => 'Contactos', 'tags' => 'Etiquetas', 'emails' => 'Correos', 'stats' => 'Estadísticas', 'media' => 'Biblioteca', 'surveys' => 'Encuestas', 'auto' => 'Automatización', 'settings' => 'Ajustes'];
const PER_PAGE_OPTIONS = [25, 50, 100, 250, 500, 1000, 0]; // 0 = todos

function default_tab(): string
{
    $t = setting('default_tab', 'contacts');
    return isset(ADMIN_TABS[$t]) ? $t : 'contacts';
}
/** Contactos por página: el de la URL (?per=) si es una opción válida, si no el de Ajustes. 0 = todos. */
function contacts_per_page(): int
{
    foreach ([$_GET['per'] ?? null, setting('contacts_per_page', '50')] as $v) {
        if ($v !== null && $v !== '' && in_array((int)$v, PER_PAGE_OPTIONS, true)) return (int)$v;
    }
    return 50;
}
function per_page_label(int $n): string { return $n === 0 ? 'Todos' : (string)$n; }

/** Iconos del menú lateral (trazos de 24×24 que heredan el color del texto). */
const NAV_ICONS = [
    'contacts' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'tags'     => 'M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01',
    'emails'   => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6',
    'stats'    => 'M3 3v18h18M7 15l4-4 3 3 6-6M20 8v4M20 8h-4',
    'media'    => 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15.5 8.5h.01',
    'surveys'  => 'M9 4H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2M9 2h6v4H9zM9 13l2 2 4-4',
    'auto'     => 'M13 2L4 14h7l-1 8 9-12h-7l1-8z',
    'settings' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
];
function nav_icon(string $k): string
{
    return '<svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="' . (NAV_ICONS[$k] ?? '') . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

function layout_start(string $title, string $active): void
{
    $me = (string)(one('SELECT email FROM users WHERE id = ?', [(int)($_SESSION['uid'] ?? 0)])['email'] ?? '');
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · Bezares Mailing</title>
<link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css?v=<?= (int)@filemtime(ROOT . '/assets/app.css') ?>">
<style>:root{--brand:<?= brand_color() ?>}</style>
</head><body class="admin">
<div class="shell">
  <aside class="sidebar">
    <a class="sb-brand" href="index.php" title="Bezares Mailing"><img src="assets/logo-bezares-mailing-light.png" alt="Bezares Mailing" width="180" height="51"></a>
    <nav class="sb-nav" aria-label="Secciones">
      <?php foreach (ADMIN_TABS as $k => $l): ?>
      <a href="index.php?p=<?= $k ?>" title="<?= e($l) ?>"<?= $k === $active ? ' class="on" aria-current="page"' : '' ?>><?= nav_icon($k) ?><span><?= e($l) ?></span></a>
      <?php endforeach ?>
    </nav>
    <div class="sb-foot">
      <?php if ($me !== ''): ?><div class="sb-who" title="<?= e($me) ?>"><?= e($me) ?></div><div class="sb-role">Administrador</div><?php endif ?>
      <a class="sb-out" href="index.php?p=logout" title="Cerrar sesión"><span class="sb-out-l">Cerrar sesión</span><span class="sb-out-s">Salir</span></a>
    </div>
  </aside>
  <main class="app">
<?php foreach (flash() as [$type, $msg]): ?><div class="flash <?= e($type) ?>"><?= e($msg) ?></div><?php endforeach ?>
<?php $exp = json_decode((string)setting('exposure'), true); if (!empty($exp['problems']) && $active !== 'settings'): ?><div class="flash err">Problema de seguridad en el servidor: <?= e($exp['problems'][0]) ?> <a href="index.php?p=settings#seguridad">Ver detalles</a></div><?php endif ?>
<?php
}
function layout_end(): void
{
    echo '</main></div><script>window.CSRF=' . json_encode(csrf()) . '</script><script src="assets/app.js?v=' . (int)@filemtime(ROOT . '/assets/app.js') . '"></script></body></html>';
}
