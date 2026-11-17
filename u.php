<?php
/* Baja de la lista (español o inglés: ?lang=en). Pide confirmación con un botón para que los antivirus de correo,
   que abren los enlaces automáticamente, no den de baja a nadie por error.
   También admite la baja en un clic de Gmail/Outlook (cabecera List-Unsubscribe-Post).
   Volver a suscribirse NUNCA es inmediato: se envía un correo con un enlace de confirmación de un solo uso. */
declare(strict_types=1);
require __DIR__ . '/lib/public.php';
public_boot();

$lang = lang_of($_GET['lang'] ?? $_POST['lang'] ?? 'es');
$tok = (string)($_GET['c'] ?? $_POST['c'] ?? '');
$c = $tok !== '' ? one('SELECT * FROM contacts WHERE token = ?', [$tok]) : null;
if (!$c) public_message(tr('unsub_t', $lang), tr('unsub_bad', $lang), 'err', $lang);
$hidden = '<input type="hidden" name="c" value="' . e($tok) . '"><input type="hidden" name="lang" value="' . $lang . '">';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['do'] ?? '') === 'resub') {
        if ($c['status'] !== 'subscribed') {
            try { send_confirmation($c, $lang); } catch (Throwable $e) {}
        }
        public_message(tr('unsub_t', $lang), sprintf(tr('resub_sent', $lang), $c['email']), 'ok', $lang);
    }
    q("UPDATE contacts SET status = 'unsubscribed', unsub_at = ? WHERE id = ?", [now(), $c['id']]);
    q('DELETE FROM confirmations WHERE contact_id = ?', [$c['id']]); // ningún enlace de confirmación anterior podrá reactivarlo
    q("UPDATE queue SET status = 'skipped', error = 'Baja' WHERE contact_id = ? AND status = 'pending'", [$c['id']]);
    public_start(tr('unsub_done_t', $lang), $lang); ?>
    <div class="pmsg ok"><h1><?= tr('unsub_done_t', $lang) ?></h1><p><?= e(sprintf(tr('unsub_done', $lang), $c['email'])) ?></p></div>
    <form method="post" action="u.php" class="center-form"><?= $hidden ?><input type="hidden" name="do" value="resub">
      <button class="linkbtn"><?= tr('resub_mistake', $lang) ?></button></form>
    <?php public_end(); exit;
}

public_start(tr('unsub_t', $lang), $lang); ?>
<div class="pmsg"><h1><?= tr('unsub_t', $lang) ?></h1>
<?php if ($c['status'] === 'unsubscribed'): ?>
  <p><?= e(sprintf(tr('unsub_already', $lang), $c['email'])) ?></p></div>
  <form method="post" action="u.php" class="center-form"><?= $hidden ?><input type="hidden" name="do" value="resub"><button class="linkbtn"><?= tr('resub', $lang) ?></button></form>
<?php else: ?>
  <p><?= sprintf(e(tr('unsub_ask', $lang)), '<b>' . e($c['email']) . '</b>') ?></p></div>
  <form method="post" action="u.php" class="center-form"><?= $hidden ?><button class="btn primary big"><?= tr('unsub_yes', $lang) ?></button></form>
<?php endif ?>
<?php public_end();
