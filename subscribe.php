<?php
/* Formulario público de suscripción a la newsletter, en español o inglés (?lang=en).
   Página limpia (sin cabecera ni marco, fondo transparente) pensada para incrustarse en un iframe.

   Seguridad:
   - Un contacto dado de baja, rebotado o pendiente SOLO se reactiva confirmando por correo, aunque la doble
     confirmación esté desactivada. Los enlaces de confirmación son de un solo uso y caducan a los 7 días.
   - Con la doble confirmación activada, también las altas nuevas y los cambios en contactos ya suscritos
     (nuevas etiquetas) esperan a la confirmación.
   - Límites por IP y por dirección de correo, campo trampa y tiempo mínimo de envío firmado.
   - La respuesta al visitante es la misma exista o no la dirección, para no revelar quién está suscrito. */
declare(strict_types=1);
require __DIR__ . '/lib/public.php';
public_boot(true);

$lang = lang_of($_GET['lang'] ?? $_POST['lang'] ?? 'es');
$S = [
    'email' => ['Correo electrónico', 'Email address'], 'name' => ['Nombre', 'First name'], 'surname' => ['Apellidos', 'Last name'],
    'company' => ['Empresa', 'Company'], 'button' => ['Suscribirme', 'Subscribe'],
    'consent' => ['Acepto recibir comunicaciones de %s por correo electrónico.', 'I agree to receive email communications from %s.'],
    'privacy' => ['Política de privacidad', 'Privacy policy'],
    'bad_email' => ['Indique un correo electrónico válido.', 'Please enter a valid email address.'],
    'need_consent' => ['Marque la casilla para poder suscribirse.', 'Please tick the box to subscribe.'],
    'check_inbox' => ['¡Gracias! Le hemos enviado un correo: pulse el enlace que contiene para confirmar la suscripción.', 'Thank you! We have sent you an email: please click the link in it to confirm your subscription.'],
    'done' => ['¡Gracias por suscribirse! Si su dirección ya estaba registrada y se había dado de baja, le hemos enviado un correo para confirmar que quiere volver a recibir nuestras comunicaciones.',
        'Thank you for subscribing! If your address was already registered and had unsubscribed, we have sent you an email to confirm that you want to receive our communications again.'],
    'error' => ['No hemos podido tramitar la suscripción en este momento. Inténtelo de nuevo más tarde.', 'We could not process your subscription right now. Please try again later.'],
    'too_many' => ['Se han recibido demasiadas solicitudes desde su conexión. Inténtelo de nuevo más tarde.', 'Too many requests have been received from your connection. Please try again later.'],
    'expired' => ['El formulario ha caducado. Recargue la página e inténtelo de nuevo.', 'The form has expired. Please reload the page and try again.'],
];
$t = fn(string $k) => $S[$k][$lang === 'en' ? 1 : 0];
$company = setting('company', 'Bezares');
$double = setting('sub_double', '1') === '1';
$askName = setting('sub_fields', '1') === '1';
$privacy = setting('sub_privacy_' . $lang) ?: setting('sub_privacy_es');
$tagFor = fn() => tags_list(tags_pack(setting('sub_tag_' . $lang, $lang === 'en' ? 'newsletter-en' : 'newsletter-es')));
$ip = client_ip();

function sub_page(string $lang, string $inner): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    $c = brand_color(); ?><!doctype html>
<html lang="<?= $lang ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Newsletter</title>
<style>
*{box-sizing:border-box}html,body{margin:0;padding:0;background:transparent}
body{font-family:Ubuntu,"Segoe UI",Helvetica,Arial,sans-serif;font-size:15px;line-height:1.4;color:#16242d;padding:2px}
form{display:grid;gap:10px;max-width:560px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
label{display:block;font-size:13px;font-weight:500;color:#3c4c57}
input[type=text],input[type=email]{display:block;width:100%;margin-top:3px;padding:9px 11px;font:inherit;border:1px solid #c5ced6;border-radius:6px;background:#fff}
input:focus{outline:2px solid #009fe3;outline-offset:1px;border-color:#009fe3}
.consent{display:flex;gap:8px;align-items:flex-start;font-weight:400;font-size:12.5px;color:#4a5a65}
.consent input{margin-top:2px;flex:none}.consent a{color:<?= $c ?>}
button{justify-self:start;font:inherit;font-weight:500;padding:10px 26px;border:0;border-radius:6px;background:<?= $c ?>;color:#fff;cursor:pointer}
button:hover{filter:brightness(1.12)}
.msg{padding:12px 14px;border-radius:6px;background:#e6f4ec;border:1px solid #b9dfc8;color:#1d6a3c;max-width:560px}
.msg.err{background:#fdecec;border-color:#f3bcbc;color:#a12626}
.hp{position:absolute;left:-9999px;height:0;overflow:hidden}
@media (max-width:420px){.two{grid-template-columns:1fr}}
</style></head><body>
<?= $inner ?>
<script>
// Avisa a la página que incrusta el formulario de su altura, para que pueda ajustar el iframe
(function(){function s(){try{parent.postMessage({bezaresMailingHeight:document.documentElement.scrollHeight},'*')}catch(e){}}
s();window.addEventListener('load',s);window.addEventListener('resize',s);})();
</script>
</body></html><?php
    exit;
}
$msg = fn(string $text, bool $err = false) => '<div class="msg' . ($err ? ' err' : '') . '" role="status">' . e($text) . '</div>';

/* ---------- Confirmación desde el correo: GET muestra un botón, POST consume el token ----------
   Así los antivirus que abren los enlaces del correo no confirman nada por su cuenta. */
if (isset($_GET['confirm']) || isset($_POST['confirm'])) {
    $raw = (string)($_POST['confirm'] ?? $_GET['confirm']);
    if (!throttle_ok('confirm-try:' . $ip, 30, 3600)) sub_page($lang, $msg($t('too_many'), true));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        throttle_hit('confirm-try:' . $ip);
        $c = confirmation_redeem($raw);
        sub_page($lang, $c ? $msg(tr('confirm_done', $lang)) : $msg(tr('confirm_bad', $lang), true));
    }
    $found = confirmation_find($raw);
    if (!$found) { throttle_hit('confirm-try:' . $ip); sub_page($lang, $msg(tr('confirm_bad', $lang), true)); }
    sub_page($lang, '<form method="post" action="subscribe.php' . ($lang === 'en' ? '?lang=en' : '') . '">'
        . '<input type="hidden" name="confirm" value="' . e($raw) . '"><input type="hidden" name="lang" value="' . $lang . '">'
        . '<p style="margin:0">' . sprintf(e(tr('confirm_ask', $lang)), '<b>' . e($found['contact']['email']) . '</b>') . '</p>'
        . '<button>' . e(tr('confirm_button', $lang)) . '</button></form>');
}

$err = '';
$in = ['email' => '', 'nombre' => '', 'apellidos' => '', 'empresa' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($in as $k => $_) $in[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 150);
    $email = norm_email($in['email']);
    // Anti-robots: campo trampa y tiempo mínimo desde que se cargó el formulario (marca de tiempo firmada)
    $ts = (int)($_POST['ts'] ?? 0);
    $bot = ($_POST['web'] ?? '') !== '' || !hash_equals(sign('ts' . $ts), (string)($_POST['th'] ?? '')) || time() - $ts < 2;
    $neutral = $msg($t($double ? 'check_inbox' : 'done'));
    if ($bot) sub_page($lang, $neutral);
    if (!throttle_ok('sub-ip:' . $ip, 10, 3600) || !throttle_ok('sub-ip:' . $ip, 30, 86400)) sub_page($lang, $msg($t('too_many'), true));
    if (time() - $ts > 43200) $err = $t('expired');
    elseif (!valid_email($email)) $err = $t('bad_email');
    elseif (empty($_POST['consent'])) $err = $t('need_consent');
    else {
        throttle_hit('sub-ip:' . $ip);
        try {
            $data = ['nombre' => $in['nombre'], 'apellidos' => $in['apellidos'], 'empresa' => $in['empresa']];
            $ex = one('SELECT * FROM contacts WHERE email = ?', [$email]);
            if (!$ex) {
                if ($double) {
                    // Alta nueva pendiente: las etiquetas se añaden al confirmar
                    $id = insert('INSERT INTO contacts (email, nombre, apellidos, empresa, tags, status, token, created_at) VALUES (?,?,?,?,?,?,?,?)',
                        [$email, $in['nombre'], $in['apellidos'], $in['empresa'], '', 'pending', token(), now()]);
                    send_confirmation(one('SELECT * FROM contacts WHERE id = ?', [$id]), $lang, $tagFor(), $data);
                } else {
                    insert('INSERT INTO contacts (email, nombre, apellidos, empresa, tags, status, token, created_at) VALUES (?,?,?,?,?,?,?,?)',
                        [$email, $in['nombre'], $in['apellidos'], $in['empresa'], tags_pack($tagFor()), 'subscribed', token(), now()]);
                }
            } elseif ($ex['status'] !== 'subscribed') {
                // Baja, rebote o pendiente: la reactivación exige SIEMPRE confirmar por correo. No se toca nada hasta entonces.
                send_confirmation($ex, $lang, $tagFor(), $data);
            } elseif ($double) {
                // Ya suscrito: las nuevas etiquetas (otro listado) también esperan a la confirmación
                if (array_diff($tagFor(), tags_list($ex['tags']))) send_confirmation($ex, $lang, $tagFor(), $data);
            } else {
                // Sin doble confirmación y ya suscrito: se añade la etiqueta del listado, sin modificar sus datos
                q('UPDATE contacts SET tags = ? WHERE id = ?', [tags_pack(array_merge(tags_list($ex['tags']), $tagFor())), $ex['id']]);
            }
            // Misma respuesta exista o no el contacto: no se revela quién está ya suscrito o dado de baja
            sub_page($lang, $neutral);
        } catch (Throwable $e) {
            $err = $t('error');
        }
    }
}

$ts = time();
ob_start(); ?>
<?php if ($err): ?><?= $msg($err, true) ?><div style="height:10px"></div><?php endif ?>
<form method="post" action="subscribe.php<?= $lang === 'en' ? '?lang=en' : '' ?>" novalidate>
  <input type="hidden" name="lang" value="<?= $lang ?>"><input type="hidden" name="ts" value="<?= $ts ?>"><input type="hidden" name="th" value="<?= sign('ts' . $ts) ?>">
  <div class="hp" aria-hidden="true"><label>Web<input type="text" name="web" tabindex="-1" autocomplete="off"></label></div>
  <label><?= $t('email') ?> *<input type="email" name="email" value="<?= e($in['email']) ?>" required autocomplete="email"></label>
  <?php if ($askName): ?>
  <div class="two">
    <label><?= $t('name') ?><input type="text" name="nombre" value="<?= e($in['nombre']) ?>" autocomplete="given-name"></label>
    <label><?= $t('surname') ?><input type="text" name="apellidos" value="<?= e($in['apellidos']) ?>" autocomplete="family-name"></label>
  </div>
  <label><?= $t('company') ?><input type="text" name="empresa" value="<?= e($in['empresa']) ?>" autocomplete="organization"></label>
  <?php endif ?>
  <label class="consent"><input type="checkbox" name="consent" value="1" required>
    <span><?= e(sprintf($t('consent'), $company)) ?><?php if ($privacy !== ''): ?> <a href="<?= e($privacy) ?>" target="_blank" rel="noopener"><?= $t('privacy') ?></a><?php endif ?></span></label>
  <button><?= $t('button') ?></button>
</form>
<?php sub_page($lang, ob_get_clean());
