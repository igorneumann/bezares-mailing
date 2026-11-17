<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Madrid');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/security.php';

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
            status VARCHAR(20) NOT NULL DEFAULT 'pending', error VARCHAR(500), sent_at VARCHAR(19), opened_at VARCHAR(19))",
        "surveys (id $pk, slug VARCHAR(80) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL, description TEXT,
            thanks TEXT, max_per_contact INT NOT NULL DEFAULT 1, active INT NOT NULL DEFAULT 1,
            is_public INT NOT NULL DEFAULT 1, created_at VARCHAR(19) NOT NULL, title_en VARCHAR(255), description_en TEXT, thanks_en TEXT,
            notify_mode VARCHAR(10) NOT NULL DEFAULT '', notify_max INT NOT NULL DEFAULT 6, notify_es VARCHAR(500) NOT NULL DEFAULT '', notify_en VARCHAR(500) NOT NULL DEFAULT '')",
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
    ];
    $sql = [];
    foreach ($t as $def) $sql[] = "CREATE TABLE IF NOT EXISTS $def$end";
    $sql[] = "CREATE INDEX ix_contacts_token ON contacts (token)";
    $sql[] = "CREATE INDEX ix_queue_status ON queue (status, id)";
    $sql[] = "CREATE INDEX ix_queue_email ON queue (email_id, contact_id)";
    $sql[] = "CREATE INDEX ix_resp_survey ON responses (survey_id, email)";
    $sql[] = "CREATE INDEX ix_answers_resp ON answers (response_id)";
    $sql[] = "CREATE INDEX ix_throttle ON throttle (k, at)";
    $sql[] = "CREATE INDEX ix_questions_survey ON questions (survey_id)";
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
function base_url(): string
{
    $b = setting('base_url');
    return rtrim($b !== '' ? $b : detect_base_url(), '/');
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
   Cookie firmada (30 días) con el usuario, la caducidad y una firma que depende de la clave secreta
   y de la contraseña: al cambiar la contraseña, todas las sesiones recordadas dejan de valer. */
const REMEMBER_COOKIE = 'bzmailing_rec';
const REMEMBER_DAYS = 30;
function remember_cookie(string $value, int $expires): void
{
    $ss = cookie_samesite();
    setcookie(REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => cookie_path(), 'httponly' => true, 'samesite' => $ss,
        'secure' => $ss === 'None' || base_is_https() || is_https()]);
    partition_cookies();
}
function remember_sign(array $user, int $exp): string
{
    return hash_hmac('sha256', $user['id'] . '|' . $exp . '|' . $user['pass_hash'], cfg()['secret']);
}
function remember_set(array $user): void
{
    $exp = time() + REMEMBER_DAYS * 86400;
    remember_cookie($user['id'] . '.' . $exp . '.' . remember_sign($user, $exp), $exp);
}
function remember_clear(): void
{
    if (isset($_COOKIE[REMEMBER_COOKIE])) remember_cookie('', time() - 3600);
}
/** Devuelve el id del usuario si la cookie es válida. */
function remember_check(): ?int
{
    $parts = explode('.', (string)($_COOKIE[REMEMBER_COOKIE] ?? ''));
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int)$parts[1] < time()) return null;
    $u = one('SELECT * FROM users WHERE id = ?', [(int)$parts[0]]);
    return $u && hash_equals(remember_sign($u, (int)$parts[1]), $parts[2]) ? (int)$u['id'] : null;
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

const ADMIN_TABS = ['contacts' => 'Contactos', 'tags' => 'Etiquetas', 'emails' => 'Correos', 'media' => 'Biblioteca', 'surveys' => 'Encuestas', 'auto' => 'Automatización', 'settings' => 'Ajustes'];
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

function layout_start(string $title, string $active): void
{
    $tabs = ADMIN_TABS;
    $logo = logo_url();
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · Bezares Mailing</title>
<link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css">
<style>:root{--brand:<?= brand_color() ?>}</style>
</head><body>
<header class="top">
  <a class="brand" href="index.php"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif ?><span class="sr">Bezares Mailing</span></a>
  <nav><?php foreach ($tabs as $k => $l): ?><a href="index.php?p=<?= $k ?>" class="<?= $k === $active ? 'on' : '' ?>"><?= $l ?></a><?php endforeach ?></nav>
  <a class="out" href="index.php?p=logout">Salir</a>
</header>
<main>
<?php foreach (flash() as [$type, $msg]): ?><div class="flash <?= e($type) ?>"><?= e($msg) ?></div><?php endforeach ?>
<?php $exp = json_decode((string)setting('exposure'), true); if (!empty($exp['problems']) && $active !== 'settings'): ?><div class="flash err">Problema de seguridad en el servidor: <?= e($exp['problems'][0]) ?> <a href="index.php?p=settings#seguridad">Ver detalles</a></div><?php endif ?>
<?php
}
function layout_end(): void
{
    echo '</main><script>window.CSRF=' . json_encode(csrf()) . '</script><script src="assets/app.js"></script></body></html>';
}
