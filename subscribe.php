<?php
/* Formulario público de suscripción a la newsletter, en español o inglés (?lang=en).
   Página limpia (sin cabecera ni marco, fondo transparente) pensada para incrustarse en un iframe.
   Con la doble confirmación activada, el contacto queda "pendiente" hasta que pulsa el enlace que recibe por correo. */
declare(strict_types=1);
require __DIR__ . '/lib/public.php';
public_boot();

$lang = lang_of($_GET['lang'] ?? $_POST['lang'] ?? 'es');
$S = [
    'email' => ['Correo electrónico', 'Email address'], 'name' => ['Nombre', 'First name'], 'surname' => ['Apellidos', 'Last name'],
    'company' => ['Empresa', 'Company'], 'button' => ['Suscribirme', 'Subscribe'],
    'consent' => ['Acepto recibir comunicaciones de %s por correo electrónico.', 'I agree to receive email communications from %s.'],
    'privacy' => ['Política de privacidad', 'Privacy policy'],
    'bad_email' => ['Indique un correo electrónico válido.', 'Please enter a valid email address.'],
    'need_consent' => ['Marque la casilla para poder suscribirse.', 'Please tick the box to subscribe.'],
    'check_inbox' => ['¡Gracias! Le hemos enviado un correo: pulse el enlace que contiene para confirmar la suscripción.', 'Thank you! We have sent you an email: please click the link in it to confirm your subscription.'],
    'done' => ['¡Gracias! Su suscripción está confirmada.', 'Thank you! Your subscription is confirmed.'],
    'bad_link' => ['El enlace de confirmación no es válido o ha caducado.', 'The confirmation link is not valid or has expired.'],
    'error' => ['No hemos podido tramitar la suscripción en este momento. Inténtelo de nuevo más tarde.', 'We could not process your subscription right now. Please try again later.'],
    'mail_subject' => ['Confirme su suscripción', 'Please confirm your subscription'],
    'mail_text' => ['Hemos recibido una solicitud para suscribir esta dirección a las comunicaciones de %s. Para confirmarla, pulse el botón:', 'We received a request to subscribe this address to communications from %s. To confirm it, please click the button:'],
    'mail_button' => ['Confirmar suscripción', 'Confirm subscription'],
    'mail_ignore' => ['Si no lo ha solicitado usted, ignore este mensaje: no recibirá nada más.', 'If you did not request this, just ignore this message: you will not receive anything else.'],
];
$t = fn(string $k) => $S[$k][$lang === 'en' ? 1 : 0];
$company = setting('company', 'Bezares');
$double = setting('sub_double', '1') === '1';
$askName = setting('sub_fields', '1') === '1';
$privacy = setting('sub_privacy_' . $lang) ?: setting('sub_privacy_es');
$tagFor = fn() => tags_list(tags_pack(setting('sub_tag_' . $lang, $lang === 'en' ? 'newsletter-en' : 'newsletter-es')));

function sub_page(string $lang, string $inner): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    header('Referrer-Policy: no-referrer');
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

/* ---------- Confirmación desde el correo ---------- */
if (isset($_GET['confirm'])) {
    $c = one('SELECT * FROM contacts WHERE token = ?', [(string)$_GET['confirm']]);
    if (!$c || !hash_equals(sign('sub' . $c['id']), (string)($_GET['h'] ?? ''))) sub_page($lang, $msg($t('bad_link'), true));
    q("UPDATE contacts SET status = 'subscribed', unsub_at = NULL WHERE id = ?", [$c['id']]);
    sub_page($lang, $msg($t('done')));
}

$err = '';
$in = ['email' => '', 'nombre' => '', 'apellidos' => '', 'empresa' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($in as $k => $_) $in[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 150);
    $email = norm_email($in['email']);
    // Anti-robots: campo trampa y tiempo mínimo desde que se cargó el formulario
    $ts = (int)($_POST['ts'] ?? 0);
    $bot = ($_POST['web'] ?? '') !== '' || !hash_equals(sign('ts' . $ts), (string)($_POST['th'] ?? '')) || time() - $ts < 2;
    if ($bot) sub_page($lang, $msg($t($double ? 'check_inbox' : 'done'))); // respuesta neutra
    if (time() - $ts > 43200) $err = $t('error'); // formulario abierto desde hace más de 12 h: se pide reintentar
    elseif (!valid_email($email)) $err = $t('bad_email');
    elseif (empty($_POST['consent'])) $err = $t('need_consent');
    else {
        try {
            $ex = one('SELECT * FROM contacts WHERE email = ?', [$email]);
            // Límite de altas pendientes por hora: evita que el formulario se use para enviar correos en masa
            if ($double && (!$ex || $ex['status'] !== 'subscribed')
                && (int)val("SELECT COUNT(*) FROM contacts WHERE status = 'pending' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 3600)]) >= 60)
                throw new RuntimeException('límite');
            $status = $double ? 'pending' : 'subscribed';
            if (!$ex) {
                $id = insert('INSERT INTO contacts (email, nombre, apellidos, empresa, tags, status, token, created_at) VALUES (?,?,?,?,?,?,?,?)',
                    [$email, $in['nombre'], $in['apellidos'], $in['empresa'], tags_pack($tagFor()), $status, token(), now()]);
                $ex = one('SELECT * FROM contacts WHERE id = ?', [$id]);
            } else {
                // Contacto existente: se añaden las etiquetas y se completan los datos vacíos; una baja solo se reactiva tras confirmar
                q('UPDATE contacts SET nombre = ?, apellidos = ?, empresa = ?, tags = ? WHERE id = ?', [$ex['nombre'] ?: $in['nombre'], $ex['apellidos'] ?: $in['apellidos'],
                    $ex['empresa'] ?: $in['empresa'], tags_pack(array_merge(tags_list($ex['tags']), $tagFor())), $ex['id']]);
                if ($ex['status'] !== 'subscribed' && !$double) q("UPDATE contacts SET status = 'subscribed', unsub_at = NULL WHERE id = ?", [$ex['id']]);
            }
            if ($double && $ex['status'] !== 'subscribed') {
                $url = base_url() . '/subscribe.php?confirm=' . $ex['token'] . '&h=' . sign('sub' . $ex['id']) . ($lang === 'en' ? '&lang=en' : '');
                $color = brand_color();
                $f = 'font-family:Ubuntu, Helvetica, Arial, sans-serif;';
                $logo = logo_url();
                $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><title>' . e($t('mail_subject')) . '</title></head><body style="margin:0;padding:20px;background:#eef1f4;">'
                    . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;"><tr><td style="padding:28px;">'
                    . ($logo ? '<img src="' . e($logo) . '" alt="' . e($company) . '" width="200" style="display:block;width:200px;height:auto;border:0;margin:0 0 20px;">' : '')
                    . '<p style="' . $f . 'font-size:15px;line-height:1.5;color:#222;margin:0 0 18px;">' . e(sprintf($t('mail_text'), $company)) . '</p>'
                    . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="' . $color . '" style="background:' . $color . ';border-radius:4px;">'
                    . '<a href="' . e($url) . '" style="display:inline-block;padding:12px 26px;' . $f . 'font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">' . e($t('mail_button')) . '</a></td></tr></table>'
                    . '<p style="' . $f . 'font-size:12px;line-height:1.5;color:#777;margin:20px 0 0;">' . e($t('mail_ignore')) . '</p></td></tr></table></body></html>';
                $smtp = null;
                try { deliver($email, ['subject' => $t('mail_subject'), 'html' => $html, 'text' => html_to_text($html)], $smtp); }
                finally { if ($smtp) $smtp->close(); }
            }
            // Misma respuesta exista o no el contacto: no se revela quién está ya suscrito
            sub_page($lang, $msg($t($double ? 'check_inbox' : 'done')));
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
