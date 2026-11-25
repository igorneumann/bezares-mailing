<?php
/* Clic en un enlace de un correo: se anota (para las estadísticas) y se redirige al destino original. */
declare(strict_types=1);
require __DIR__ . '/lib/boot.php';
$q = (int)($_GET['q'] ?? 0);
$url = (string)($_GET['u'] ?? '');
// Solo se redirige a direcciones firmadas por la aplicación: no sirve como redirector abierto
if (!cfg() || !$q || !preg_match('#^https?://#i', $url) || !hash_equals(sign('c' . $q . '|' . $url), (string)($_GET['h'] ?? ''))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Enlace no válido.');
}
try {
    $now = now();
    q('UPDATE queue SET clicks = clicks + 1, clicked_at = COALESCE(clicked_at, ?), opened_at = COALESCE(opened_at, ?) WHERE id = ?', [$now, $now, $q]);
    q('INSERT INTO clicks (queue_id, url, at) VALUES (?, ?, ?)', [$q, mb_substr($url, 0, 1000), $now]);
} catch (Throwable $e) { /* la redirección no debe fallar por las estadísticas */ }
header('Referrer-Policy: no-referrer');
header('Location: ' . $url, true, 302);
