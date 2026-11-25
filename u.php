<?php
/* Baja (español o inglés: ?lang=en).
   - El enlace de cada correo lleva el mensaje (&m=): la baja es SOLO del listado (etiqueta) al que se envió ese correo.
     Por ejemplo, quien se da de baja de la newsletter sigue recibiendo los correos de clientes o proveedores.
     La página ofrece también «darme de baja de todas las comunicaciones».
   - Si el correo se envió a todos los suscritos (sin listado), o el enlace no lleva mensaje, la baja es de todo.
   - Pide confirmación con un botón para que los antivirus que abren enlaces no den de baja a nadie por error.
     La baja en un clic de Gmail/Outlook (cabecera List-Unsubscribe-Post) da de baja del listado de ese correo.
   - Volver a suscribirse NUNCA es inmediato: se envía un correo con un enlace de confirmación de un solo uso. */
declare(strict_types=1);
require __DIR__ . '/lib/public.php';
public_boot();

$lang = lang_of($_GET['lang'] ?? $_POST['lang'] ?? 'es');
$tok = (string)($_GET['c'] ?? $_POST['c'] ?? '');
$c = $tok !== '' ? one('SELECT * FROM contacts WHERE token = ?', [$tok]) : null;
if (!$c) public_message(tr('unsub_t', $lang), tr('unsub_bad', $lang), 'err', $lang);

// Listado del correo: el del envío al que pertenece el mensaje, solo si ese mensaje se envió a este contacto
$m = (int)($_GET['m'] ?? $_POST['m'] ?? 0);
$tag = $m ? (string)val('SELECT s.tag FROM queue q JOIN sends s ON s.id = q.send_id WHERE q.id = ? AND q.contact_id = ?', [$m, $c['id']]) : '';
$company = setting('company', 'Bezares');
$hidden = '<input type="hidden" name="c" value="' . e($tok) . '"><input type="hidden" name="lang" value="' . $lang . '">'
    . ($m ? '<input type="hidden" name="m" value="' . $m . '">' : '');
$form = fn(string $do, string $label, string $cls) => '<form method="post" action="u.php" class="center-form">' . $hidden
    . '<input type="hidden" name="do" value="' . $do . '"><button class="' . $cls . '">' . e($label) . '</button></form>';

function unsubscribe_all(array $c): void
{
    q("UPDATE contacts SET status = 'unsubscribed', unsub_at = ? WHERE id = ?", [now(), $c['id']]);
    q('DELETE FROM confirmations WHERE contact_id = ?', [$c['id']]); // ningún enlace de confirmación anterior podrá reactivarlo
    q("UPDATE queue SET status = 'skipped', error = 'Baja' WHERE contact_id = ? AND status = 'pending'", [$c['id']]);
}
function unsubscribe_list(array $c, string $tag): void
{
    q('UPDATE contacts SET unsub_tags = ? WHERE id = ?', [tags_pack(array_merge(optouts($c), [$tag])), $c['id']]);
    // Envíos pendientes de ese listado y confirmaciones pendientes que lo reactivarían
    q("UPDATE queue SET status = 'skipped', error = 'Baja del listado' WHERE contact_id = ? AND status = 'pending'
        AND send_id IN (SELECT id FROM sends WHERE tag = ?)", [$c['id'], $tag]);
    q('DELETE FROM confirmations WHERE contact_id = ? AND tags LIKE ?', [$c['id'], '%,' . $tag . ',%']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'resub' || $do === 'resub_list') {
        $wanted = $do === 'resub_list' && $tag !== '' ? [$tag] : [];
        $needs = $c['status'] !== 'subscribed' || ($wanted && in_array($tag, optouts($c), true));
        if ($needs) { try { send_confirmation($c, $lang, $wanted); } catch (Throwable $e) {} }
        public_message(tr('unsub_t', $lang), sprintf(tr('resub_sent', $lang), $c['email']), 'ok', $lang);
    }
    // Para las estadísticas: el correo desde el que se dio de baja
    if ($m) { try { q('UPDATE queue SET unsub_at = COALESCE(unsub_at, ?) WHERE id = ? AND contact_id = ?', [now(), $m, $c['id']]); } catch (Throwable $e) {} }
    // Sin «do» (baja en un clic del programa de correo) o «list»: baja del listado si el correo tenía uno
    if ($do !== 'all' && $tag !== '') {
        unsubscribe_list($c, $tag);
        public_start(tr('unsub_done_t', $lang), $lang); ?>
        <div class="pmsg ok"><h1><?= tr('unsub_done_t', $lang) ?></h1><p><?= e(sprintf(tr('unsub_list_done', $lang), $c['email'], $tag, $company)) ?></p></div>
        <p class="center-form muted"><?= e(sprintf(tr('unsub_all_hint', $lang), $company)) ?></p>
        <?= $form('all', tr('unsub_all_yes', $lang), 'btn') ?>
        <div style="height:.8rem"></div>
        <?= $form('resub_list', sprintf(tr('resub_list_mistake', $lang), $tag), 'linkbtn') ?>
        <?php public_end(); exit;
    }
    unsubscribe_all($c);
    public_start(tr('unsub_done_t', $lang), $lang); ?>
    <div class="pmsg ok"><h1><?= tr('unsub_done_t', $lang) ?></h1><p><?= e(sprintf(tr('unsub_done', $lang), $c['email'])) ?></p></div>
    <?= $form('resub', tr('resub_mistake', $lang), 'linkbtn') ?>
    <?php public_end(); exit;
}

public_start(tr('unsub_t', $lang), $lang); ?>
<div class="pmsg"><h1><?= tr('unsub_t', $lang) ?></h1>
<?php if ($c['status'] === 'unsubscribed'): ?>
  <p><?= e(sprintf(tr('unsub_already', $lang), $c['email'])) ?></p></div>
  <?= $form('resub', tr('resub', $lang), 'linkbtn') ?>
<?php elseif ($tag !== '' && in_array($tag, optouts($c), true)): ?>
  <p><?= e(sprintf(tr('unsub_list_already', $lang), $c['email'], $tag)) ?></p></div>
  <?= $form('all', tr('unsub_all_yes', $lang), 'btn') ?>
  <div style="height:.8rem"></div>
  <?= $form('resub_list', sprintf(tr('resub_list_mistake', $lang), $tag), 'linkbtn') ?>
<?php elseif ($tag !== ''): ?>
  <p><?= sprintf(e(tr('unsub_list_ask', $lang)), '<b>' . e($c['email']) . '</b>', '<b>' . e($tag) . '</b>') ?></p></div>
  <?= $form('list', sprintf(tr('unsub_list_yes', $lang), $tag), 'btn primary big') ?>
  <div style="height:.8rem"></div>
  <?= $form('all', tr('unsub_all_yes', $lang), 'linkbtn') ?>
<?php else: ?>
  <p><?= sprintf(e(tr('unsub_ask', $lang)), '<b>' . e($c['email']) . '</b>') ?></p></div>
  <?= $form('all', tr('unsub_yes', $lang), 'btn primary big') ?>
<?php endif ?>
<?php public_end();
