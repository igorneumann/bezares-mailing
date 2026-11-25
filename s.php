<?php
/* Formulario de encuesta (español o inglés: ?lang=en).
   Desde el correo llega como s.php?s=<encuesta>&c=<token del contacto>&k=<firma>&q<id>=<nota>:
   el correo y la nota aparecen ya rellenados y solo hay que pulsar "Enviar".
   Las respuestas de los dos idiomas se guardan en la misma encuesta, así que los resultados se cuentan juntos.

   Integridad:
   - Una respuesta solo se atribuye a un contacto (verificada) si llega con su enlace personal firmado para ESTA encuesta.
   - En las encuestas públicas, quien responde sin enlace escribe su correo: esa respuesta queda «sin verificar»,
     no se asocia a ningún contacto y no cuenta para el límite de respuestas de los enlaces personales.
   - Límites por IP (más estrictos para el formulario público), campo trampa y marca de tiempo firmada. */
declare(strict_types=1);
require __DIR__ . '/lib/public.php';
public_boot();

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$lang = lang_of($in['lang'] ?? 'es');
$survey = one('SELECT * FROM surveys WHERE slug = ?', [(string)($in['s'] ?? '')]);
if (!$survey) { http_response_code(404); public_message(tr('not_found_t', $lang), tr('not_found', $lang), 'err', $lang); }
$title = L($survey, 'title', $lang);
if (!$survey['active']) public_message($title, tr('closed', $lang), 'info', $lang);

$ip = client_ip();
// Vista previa de administrador (enlace firmado desde el editor): se ve el formulario, nunca se guarda nada
$preview = hash_equals(sign('preview|' . $survey['id']), (string)($in['preview'] ?? ''));
$tok = (string)($in['c'] ?? '');
$contact = null;
if ($tok !== '') {
    // Enlace personal: el token tiene que venir con la firma de esta encuesta
    $c = hash_equals(survey_sig($survey, $tok), (string)($in['k'] ?? '')) ? one('SELECT * FROM contacts WHERE token = ?', [$tok]) : null;
    if (!$c) public_message($title, tr('need_link', $lang), 'info', $lang);
    $contact = $c;
}
if (!$contact && !$survey['is_public'] && !$preview) public_message($title, tr('need_link', $lang), 'info', $lang);

$questions = all('SELECT * FROM questions WHERE survey_id = ? ORDER BY position, id', [$survey['id']]);
if ($contact && survey_limit_reached($survey, $contact['email'], true)) public_message($title, tr('already', $lang), 'ok', $lang);

/** Opciones a mostrar: [valor que se guarda (siempre el español) => texto en el idioma del visitante]. */
$optionMap = function (array $q) use ($lang): array {
    $es = question_options($q);
    $en = $lang === 'en' ? question_options(['options' => $q['options_en'] ?? '']) : [];
    $out = [];
    foreach ($es as $i => $o) $out[$o] = count($en) === count($es) ? $en[$i] : $o;
    return $out;
};

$errors = [];
$values = [];
$email = $contact ? $contact['email'] : norm_email((string)($in['email'] ?? ''));

foreach ($questions as $q) {
    $raw = $in['q' . $q['id']] ?? '';
    if ($q['type'] === 'checkbox') {
        $opts = question_options($q);
        $v = implode(' | ', array_values(array_intersect($opts, array_map('strval', (array)$raw))));
    } else {
        $v = is_array($raw) ? '' : trim((string)$raw);
        if ($q['type'] === 'nps' && !(ctype_digit($v) && (int)$v <= 10)) $v = '';
        if ($q['type'] === 'rating' && !(ctype_digit($v) && (int)$v >= 1 && (int)$v <= 5)) $v = '';
        if ($q['type'] === 'radio' && !in_array($v, question_options($q), true)) $v = '';
        if ($q['type'] === 'text') $v = mb_substr($v, 0, 500);
        if ($q['type'] === 'textarea') $v = mb_substr($v, 0, 5000);
    }
    $values[$q['id']] = $v;
}

$thanks = L($survey, 'thanks', $lang) ?: tr('thanks', $lang);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['web'] ?? '') !== '') public_message($title, $thanks, 'ok', $lang); // trampa anti-robots
    if ($preview) public_message($title, tr('preview', $lang), 'info', $lang);
    // Límites por IP: generales y, para el formulario público, más estrictos
    if (!throttle_ok('srv-ip:' . $ip, 30, 3600) || !throttle_ok('srv-ip:' . $ip, 200, 86400)) public_message($title, tr('too_many', $lang), 'err', $lang);
    throttle_hit('srv-ip:' . $ip);
    if (!$contact) {
        if (!throttle_ok('srv-pub-ip:' . $ip, 5, 3600) || !throttle_ok('srv-pub-ip:' . $ip, 20, 86400)) public_message($title, tr('too_many', $lang), 'err', $lang);
        throttle_hit('srv-pub-ip:' . $ip);
    }
    // Marca de tiempo firmada: prueba que el formulario se cargó (y, en el público, que no se envió en menos de 2 s)
    $ts = (int)($_POST['ts'] ?? 0);
    if (!hash_equals(sign('sts' . $ts), (string)($_POST['th'] ?? '')) || time() - $ts > 86400) public_message($title, tr('expired', $lang), 'err', $lang);
    if (!$contact && time() - $ts < 2) public_message($title, $thanks, 'ok', $lang);
    if (!valid_email($email)) $errors['email'] = tr('bad_email', $lang);
    foreach ($questions as $q) if ($q['is_required'] && $values[$q['id']] === '') $errors[$q['id']] = tr('required', $lang);
    if (!$errors) {
        $verified = $contact !== null;
        if (survey_limit_reached($survey, $email, $verified)) public_message($title, tr('already', $lang), 'ok', $lang);
        db()->beginTransaction();
        // Sin enlace personal la respuesta no se asocia a ningún contacto aunque el correo coincida
        $rid = insert('INSERT INTO responses (survey_id, contact_id, email, created_at, lang, verified) VALUES (?,?,?,?,?,?)',
            [$survey['id'], $verified ? $contact['id'] : null, $email, now(), $lang, $verified ? 1 : 0]);
        foreach ($questions as $q) q('INSERT INTO answers (response_id, question_id, value) VALUES (?,?,?)', [$rid, $q['id'], $values[$q['id']]]);
        db()->commit();
        survey_notify($survey, $questions, $values, $email, $contact, $lang, $verified); // aviso interno, si la encuesta lo tiene configurado
        // Agradecimiento al cliente: se envía después de mostrarle la página de gracias, para que no espere al servidor de correo
        register_shutdown_function(function () use ($survey, $questions, $values, $contact, $lang, $verified) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            survey_thanks($survey, $questions, $values, $contact, $lang, $verified);
        });
        public_message($title, $thanks, 'ok', $lang);
    }
}

$intro = L($survey, 'description', $lang);
public_start($title, $lang); ?>
<?php if ($preview): ?><div class="pmsg info" style="padding:.5rem;border:1px dashed #f0a020;border-radius:6px;margin-bottom:1rem;"><?= tr('preview', $lang) ?></div><?php endif ?>
<h1><?= e($title) ?></h1>
<?php if ($intro !== ''): ?><p class="intro"><?= nl2br(e($intro)) ?></p><?php endif ?>
<form method="post" action="s.php" class="survey" novalidate>
  <input type="hidden" name="s" value="<?= e($survey['slug']) ?>">
  <input type="hidden" name="c" value="<?= e($contact ? $tok : '') ?>">
  <input type="hidden" name="k" value="<?= e($contact ? (string)($in['k'] ?? '') : '') ?>">
  <?php if ($preview): ?><input type="hidden" name="preview" value="<?= e((string)$in['preview']) ?>"><?php endif ?>
  <?php $ts0 = time(); ?><input type="hidden" name="ts" value="<?= $ts0 ?>"><input type="hidden" name="th" value="<?= sign('sts' . $ts0) ?>">
  <input type="hidden" name="lang" value="<?= $lang ?>">
  <div class="hp" aria-hidden="true"><label>No rellenar<input name="web" tabindex="-1" autocomplete="off"></label></div>

  <fieldset class="<?= isset($errors['email']) ? 'bad' : '' ?>">
    <legend><?= tr('email', $lang) ?> <em>*</em></legend>
    <input type="email" name="email" value="<?= e($email) ?>" <?= $contact ? 'readonly' : 'required' ?> placeholder="<?= tr('email_ph', $lang) ?>">
    <?php if (isset($errors['email'])): ?><p class="error"><?= e($errors['email']) ?></p><?php endif ?>
  </fieldset>

  <?php foreach ($questions as $q): $n = 'q' . $q['id']; $v = $values[$q['id']]; ?>
  <fieldset class="<?= isset($errors[$q['id']]) ? 'bad' : '' ?>">
    <legend><?= e(L($q, 'label', $lang)) ?><?= $q['is_required'] ? ' <em>*</em>' : '' ?></legend>
    <?php if ($q['type'] === 'nps' || $q['type'] === 'rating'):
        [$min, $max] = $q['type'] === 'nps' ? [0, 10] : [1, 5]; ?>
      <div class="scale n<?= $max - $min + 1 ?>">
        <?php for ($i = $min; $i <= $max; $i++): ?>
          <label><input type="radio" name="<?= $n ?>" value="<?= $i ?>" <?= $v === (string)$i ? 'checked' : '' ?>><span><?= $i ?></span></label>
        <?php endfor ?>
      </div>
      <div class="ends"><span><?= tr('low_' . $q['type'], $lang) ?></span><span><?= tr('high_' . $q['type'], $lang) ?></span></div>
    <?php elseif ($q['type'] === 'textarea'): ?>
      <textarea name="<?= $n ?>" rows="4"><?= e($v) ?></textarea>
    <?php elseif ($q['type'] === 'radio' || $q['type'] === 'checkbox'):
        $chosen = $q['type'] === 'checkbox' ? explode(' | ', $v) : [$v]; ?>
      <div class="opts">
        <?php foreach ($optionMap($q) as $o => $text): ?>
          <label><input type="<?= $q['type'] ?>" name="<?= $n . ($q['type'] === 'checkbox' ? '[]' : '') ?>" value="<?= e($o) ?>" <?= in_array((string)$o, $chosen, true) ? 'checked' : '' ?>> <?= e($text) ?></label>
        <?php endforeach ?>
      </div>
    <?php else: ?>
      <input type="text" name="<?= $n ?>" value="<?= e($v) ?>">
    <?php endif ?>
    <?php if (isset($errors[$q['id']])): ?><p class="error"><?= e($errors[$q['id']]) ?></p><?php endif ?>
  </fieldset>
  <?php endforeach ?>
  <button class="btn primary big"><?= tr('send', $lang) ?></button>
</form>
<?php public_end();
