<?php
/* Tarea programada: ejecuta las automatizaciones (NPS periódico) y envía un lote de la cola. Uso:
     - Web:  https://sudominio/cron.php?key=CLAVE   (la clave está en Ajustes)
     - CLI:  php cron.php */
declare(strict_types=1);
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/xlsx.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/media.php';
require __DIR__ . '/lib/auto.php';
if (!cfg()) exit("Sin instalar\n");
if (PHP_SAPI !== 'cli' && !hash_equals(setting('cron_key', token()), (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('Clave incorrecta'); }
header('Content-Type: text/plain; charset=UTF-8');
migrate();
set_setting('cron_last', now());
foreach (automation_run() as $line) echo $line, "\n";
$r = process_queue();
echo "enviados={$r['sent']} fallidos={$r['failed']} pendientes={$r['pending']}" . ($r['limited'] ? ' (límite por hora alcanzado)' : '') . "\n";
