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

$p = (string)($_GET['p'] ?? 'contacts');
$a = (string)($_GET['a'] ?? 'list');

if ($p === 'logout') { $_SESSION = []; session_destroy(); remember_clear(); redirect('index.php'); }
if (empty($_SESSION['uid']) && ($uid = remember_check())) { session_regenerate_id(true); $_SESSION['uid'] = $uid; }
if (empty($_SESSION['uid'])) { require __DIR__ . '/pages/login.php'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

if (!in_array($p, ['contacts', 'emails', 'media', 'surveys', 'auto', 'settings'], true)) $p = 'contacts';
try {
    require __DIR__ . "/pages/$p.php";
} catch (Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    layout_start('Error', $p);
    echo '<div class="flash err">Se ha producido un error: ' . e($e->getMessage()) . '</div>';
    layout_end();
}
