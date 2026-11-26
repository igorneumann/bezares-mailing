<?php
declare(strict_types=1);
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/xlsx.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/media.php';
require __DIR__ . '/lib/auto.php';

security_headers();
if (cfg()) { enforce_canonical(); migrate(); }
start_session();

if (!cfg()) { require __DIR__ . '/pages/install.php'; exit; }

$p = (string)($_GET['p'] ?? '');
$a = (string)($_GET['a'] ?? 'list');

if ($p === 'logout') { $_SESSION = []; session_destroy(); remember_clear(); redirect('index.php'); }
// Sesión de un usuario borrado o abierta antes de un cambio de contraseña: se cierra
if (!empty($_SESSION['uid']) && !session_valid()) { $_SESSION = []; session_regenerate_id(true); }
if (empty($_SESSION['uid']) && ($uid = remember_check())) {
    login_user(one('SELECT * FROM users WHERE id = ?', [$uid]));
    $_SESSION['rs'] = remember_parts()[0]; // la sesión depende de esa ficha: si se borra, se cierra
}
if (empty($_SESSION['uid'])) { require __DIR__ . '/pages/login.php'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

if (!isset(ADMIN_TABS[$p])) $p = default_tab(); // sin pestaña indicada (o desconocida): la elegida en Ajustes
try {
    require __DIR__ . "/pages/$p.php";
} catch (Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    layout_start('Error', $p);
    echo '<div class="flash err">Se ha producido un error: ' . e($e->getMessage()) . '</div>';
    layout_end();
}
