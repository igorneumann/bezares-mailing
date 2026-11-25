<?php
/* Píxel de apertura */
declare(strict_types=1);
require __DIR__ . '/lib/boot.php';
$q = (int)($_GET['q'] ?? 0);
if (cfg() && $q && hash_equals(sign('o' . $q), (string)($_GET['h'] ?? ''))) {
    try { q('UPDATE queue SET opened_at = ? WHERE id = ? AND opened_at IS NULL', [now(), $q]); } catch (Throwable $e) {}
}
header('Content-Type: image/gif');
header('Cache-Control: no-store, max-age=0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
