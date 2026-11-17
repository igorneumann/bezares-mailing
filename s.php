<?php
/* Formulario público de encuesta (español o inglés: ?lang=en).
   Desde el correo llega como s.php?s=<encuesta>&c=<token del contacto>&q<id>=<nota>:
   el correo y la nota aparecen ya rellenados y solo hay que pulsar "Enviar".
   Las respuestas de los dos idiomas se guardan en la misma encuesta, así que los resultados se cuentan juntos. */
declare(strict_types=1);
require __DIR__ . '/lib/public.php';
public_boot();

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$lang = lang_of($in['lang'] ?? 'es');
$survey = one('SELECT * FROM surveys WHERE slug = ?', [(string)($in['s'] ?? '')]);
if (!$survey) { http_response_code(404); public_message(tr('not_found_t', $lang), tr('not_found', $lang), 'err', $lang); }
$title = L($survey, 'title', $lang);
if (!$survey['active']) public_message($title, tr('closed', $lang), 'info', $lang);

$tok = (string)($in['c'] ?? '');
$contact = $tok !== '' ? one('SELECT * FROM contacts WHERE token = ?', [$tok]) : null;
if (!$contact && !$survey['is_public']) public_message($title, tr('need_link', $lang), 'info', $lang);

$questions = all('SELECT * FROM questions WHERE survey_id = ? ORDER BY position, id', [$survey['id']]);
if ($contact && survey_limit_reached($survey, $contact['email'])) public_message($title, tr('already', $lang), 'ok', $lang);

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
    if (!valid_email($email)) $errors['email'] = tr('bad_email', $lang);
    foreach ($questions as $q) if ($q['is_required'] && $values[$q['id']] === '') $errors[$q['id']] = tr('required', $lang);
    if (!$errors) {
        if (survey_limit_reached($survey, $email)) public_message($title, tr('already', $lang), 'ok', $lang);
        $cid = $contact['id'] ?? val('SELECT id FROM contacts WHERE email = ?', [$email]);
        db()->beginTransaction();
        $rid = insert('INSERT INTO responses (survey_id, contact_id, email, created_at, lang) VALUES (?,?,?,?,?)', [$survey['id'], $cid ?: null, $email, now(), $lang]);
        foreach ($questions as $q) q('INSERT INTO answers (response_id, question_id, value) VALUES (?,?,?)', [$rid, $q['id'], $values[$q['id']]]);
        db()->commit();
        survey_notify($survey, $questions, $values, $email, $contact, $lang); // aviso interno, si la encuesta lo tiene configurado
        public_message($title, $thanks, 'ok', $lang);
    }
}

$intro = L($survey, 'description', $lang);
public_start($title, $lang); ?>
<h1><?= e($title) ?></h1>
<?php if ($intro !== ''): ?><p class="intro"><?= nl2br(e($intro)) ?></p><?php endif ?>
<form method="post" action="s.php" class="survey" novalidate>
  <input type="hidden" name="s" value="<?= e($survey['slug']) ?>">
  <input type="hidden" name="c" value="<?= e($contact ? $tok : '') ?>">
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
