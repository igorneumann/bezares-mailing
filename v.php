<?php
/* "Ver este correo en el navegador" */
declare(strict_types=1);
require __DIR__ . '/lib/public.php';
public_boot();
$q = (int)($_GET['q'] ?? 0);
if (!$q || !hash_equals(sign('v' . $q), (string)($_GET['h'] ?? ''))) { http_response_code(404); exit('Enlace no válido.'); }
$item = one('SELECT * FROM queue WHERE id = ?', [$q]);
$email = $item ? one('SELECT * FROM emails WHERE id = ?', [$item['email_id']]) : null;
$contact = $item ? one('SELECT * FROM contacts WHERE id = ?', [$item['contact_id']]) : null;
if (!$email || !$contact) { http_response_code(404); exit('Este correo ya no está disponible.'); }
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: script-src 'none'");
echo render_email($email, $contact, ['queue_id' => $q])['html'];
