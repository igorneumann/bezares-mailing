<?php
/* Plantilla de las páginas públicas (encuesta y baja). */
function public_start(string $title, string $lang = 'es'): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    $logo = logo_url();
    ?><!doctype html>
<html lang="<?= $lang === 'en' ? 'en' : 'es' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?></title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css">
<style>:root{--brand:<?= brand_color() ?>}</style></head>
<body class="public">
<div class="pcard">
  <div class="plogo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e(setting('company')) ?>"><?php else: ?><b><?= e(setting('company')) ?></b><?php endif ?></div>
<?php
}
function public_end(): void
{
    echo '</div><p class="pfoot">© ' . e(setting('company')) . '</p></body></html>';
}
function public_message(string $title, string $msg, string $kind = 'info', string $lang = 'es'): void
{
    public_start($title, $lang);
    echo '<div class="pmsg ' . e($kind) . '"><h1>' . e($title) . '</h1><p>' . nl2br(e($msg)) . '</p></div>';
    public_end();
    exit;
}
function public_boot(): void
{
    require_once __DIR__ . '/boot.php';
    require_once __DIR__ . '/mailer.php';
    if (!cfg()) { http_response_code(503); exit('Aplicación sin instalar.'); }
}
