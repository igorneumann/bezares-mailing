<?php
$TYPES = array_map(fn($t) => $t[0], sf_types()); // lib/surveyform.php
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$survey = $id ? one('SELECT * FROM surveys WHERE id = ?', [$id]) : null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

function slugify(string $s): string
{
    $s = strtr(mb_strtolower(trim($s)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/* ---------- Guardar ---------- */
if ($a === 'save' && $isPost) {
    $title = trim($_POST['title'] ?? '') ?: 'Encuesta sin título';
    $slug = slugify($_POST['slug'] ?? '') ?: slugify($title) ?: 'encuesta';
    $base = $slug;
    for ($i = 2; one('SELECT id FROM surveys WHERE slug = ? AND id <> ?', [$slug, $id]); $i++) $slug = "$base-$i";
    $d = [$slug, $title, trim($_POST['description'] ?? ''), trim($_POST['thanks'] ?? ''), max(0, (int)($_POST['max_per_contact'] ?? 1)),
        empty($_POST['active']) ? 0 : 1, empty($_POST['is_public']) ? 0 : 1];
    db()->beginTransaction();
    if ($survey) q('UPDATE surveys SET slug=?, title=?, description=?, thanks=?, max_per_contact=?, active=?, is_public=? WHERE id=?', [...$d, $id]);
    else $id = insert('INSERT INTO surveys (slug, title, description, thanks, max_per_contact, active, is_public, created_at) VALUES (?,?,?,?,?,?,?,?)', [...$d, now()]);
    q('UPDATE surveys SET title_en = ?, description_en = ?, thanks_en = ? WHERE id = ?',
        [trim($_POST['title_en'] ?? ''), trim($_POST['description_en'] ?? ''), trim($_POST['thanks_en'] ?? ''), $id]);
    $nm = in_array($_POST['notify_mode'] ?? '', ['all', 'low'], true) ? $_POST['notify_mode'] : '';
    q('UPDATE surveys SET notify_mode = ?, notify_max = ?, notify_es = ?, notify_en = ? WHERE id = ?',
        [$nm, max(0, min(10, (int)($_POST['notify_max'] ?? 6))), implode(', ', email_list((string)($_POST['notify_es'] ?? ''))), implode(', ', email_list((string)($_POST['notify_en'] ?? ''))), $id]);
    $emailOk = fn($v) => ($v = (int)$v) && val('SELECT 1 FROM emails WHERE id = ?', [$v]) ? $v : null;
    q('UPDATE surveys SET thank_es = ?, thank_en = ?, thank_min = ? WHERE id = ?',
        [$emailOk($_POST['thank_es'] ?? 0), $emailOk($_POST['thank_en'] ?? 0), max(0, min(10, (int)($_POST['thank_min'] ?? 0))), $id]);
    $steps = in_array($_POST['steps'] ?? '', ['question', 'section'], true) ? $_POST['steps'] : '';
    q('UPDATE surveys SET config = ? WHERE id = ?', [json_encode(['steps' => $steps]), $id]);
    $keep = [];
    $pos = 0;
    $map = []; // posición en el formulario => id (para las condiciones, que solo miran preguntas anteriores)
    foreach ((array)($_POST['q'] ?? []) as $i => $row) {
        if (!is_array($row)) continue;
        $label = trim($row['label'] ?? '');
        $type = isset($TYPES[$row['type'] ?? '']) ? $row['type'] : 'text';
        if ($label === '') continue;
        $qd = [++$pos, $type, $label, trim($row['options'] ?? ''), empty($row['required']) || $type === 'section' ? 0 : 1];
        $qid = (int)($row['id'] ?? 0);
        if ($qid && one('SELECT id FROM questions WHERE id = ? AND survey_id = ?', [$qid, $id]))
            q('UPDATE questions SET position=?, type=?, label=?, options=?, is_required=? WHERE id=?', [...$qd, $qid]);
        else $qid = insert('INSERT INTO questions (position, type, label, options, is_required, survey_id) VALUES (?,?,?,?,?,?)', [...$qd, $id]);
        $cfg = sf_cfg_from_post($row, $type, $map[(string)($row['cond_ref'] ?? '')] ?? 0);
        q('UPDATE questions SET label_en = ?, options_en = ?, config = ? WHERE id = ?', [trim($row['label_en'] ?? ''), trim($row['options_en'] ?? ''), $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null, $qid]);
        $map[(string)$i] = $qid;
        $keep[] = $qid;
    }
    foreach (all('SELECT id FROM questions WHERE survey_id = ?', [$id]) as $old) {
        if (!in_array((int)$old['id'], $keep, true)) {
            q('DELETE FROM answers WHERE question_id = ?', [$old['id']]);
            q('DELETE FROM questions WHERE id = ?', [$old['id']]);
        }
    }
    db()->commit();
    flash('Encuesta guardada.');
    redirect('index.php?p=surveys&a=edit&id=' . $id);
}
/* ---------- Vista previa en directo del editor: la encuesta tal como está en el formulario, sin guardar nada ---------- */
if ($a === 'livepreview' && $isPost) {
    require_once __DIR__ . '/../lib/public.php';
    $lang = ($_POST['pv_lang'] ?? '') === 'en' ? 'en' : 'es';
    $pick = fn($k) => trim((string)($lang === 'en' && trim((string)($_POST[$k . '_en'] ?? '')) !== '' ? $_POST[$k . '_en'] : ($_POST[$k] ?? '')));
    $qsPv = sf_preview_questions((array)($_POST['q'] ?? []), $TYPES);
    $stepsPv = in_array($_POST['steps'] ?? '', ['question', 'section'], true) ? $_POST['steps'] : '';
    $titlePv = $pick('title') ?: 'Encuesta sin título';
    public_start($titlePv, $lang); ?>
<h1><?= e($titlePv) ?></h1>
<?php if ($pick('description') !== ''): ?><p class="intro"><?= nl2br(e($pick('description'))) ?></p><?php endif ?>
<form class="survey" novalidate data-sf-preview<?= $stepsPv ? ' data-steps="' . e($stepsPv) . '"' : '' ?> data-next="<?= e(sf_t('next', $lang)) ?>" data-back="<?= e(sf_t('back', $lang)) ?>" data-of="<?= e(sf_t('of', $lang)) ?>" data-required="<?= e(tr('required', $lang)) ?>">
  <fieldset class="sf-email" data-req="1"><legend><?= tr('email', $lang) ?> <em>*</em></legend><input type="email" name="email" placeholder="<?= tr('email_ph', $lang) ?>"></fieldset>
  <?php foreach ($qsPv as $q) echo sf_field($q, '', $lang, ['name' => 'q' . $q['id'], 'label' => L($q, 'label', $lang), 'opts' => sf_option_map($q, $lang),
      'lowhigh' => in_array($q['type'], ['nps', 'rating'], true) ? [tr('low_' . $q['type'], $lang), tr('high_' . $q['type'], $lang)] : []]); ?>
  <button type="button" class="btn primary big" data-sf-submit><?= tr('send', $lang) ?></button>
</form>
<script src="assets/encuesta.js" defer></script>
<?php public_end();
    exit;
}
if ($a === 'delete' && $isPost && $survey) {
    q('DELETE FROM answers WHERE response_id IN (SELECT id FROM responses WHERE survey_id = ?)', [$id]);
    q('DELETE FROM responses WHERE survey_id = ?', [$id]);
    q('DELETE FROM questions WHERE survey_id = ?', [$id]);
    q('UPDATE emails SET survey_id = NULL WHERE survey_id = ?', [$id]);
    q('DELETE FROM surveys WHERE id = ?', [$id]);
    flash('Encuesta eliminada.');
    redirect('index.php?p=surveys');
}
if ($a === 'delresp' && $isPost && $survey) {
    $rid = (int)($_POST['rid'] ?? 0);
    if (one('SELECT id FROM responses WHERE id = ? AND survey_id = ?', [$rid, $id])) {
        q('DELETE FROM answers WHERE response_id = ?', [$rid]);
        q('DELETE FROM responses WHERE id = ?', [$rid]);
        flash('Respuesta eliminada (ese contacto podrá volver a responder).');
    }
    redirect('index.php?p=surveys&a=results&id=' . $id);
}

/* ---------- Resultados ---------- */
if (($a === 'results' || $a === 'export') && $survey) {
    $view = in_array($_GET['v'] ?? '', ['es', 'en'], true) ? $_GET['v'] : '';   // '' = general (español + inglés)
    $questions = survey_stats($survey, $view);
    $responses = all('SELECT r.*, c.nombre, c.apellidos, c.empresa FROM responses r LEFT JOIN contacts c ON c.id = r.contact_id WHERE r.survey_id = ?' . lang_filter($view) . ' ORDER BY r.id DESC', [$id]);
    $ans = [];
    foreach (all('SELECT a.* FROM answers a JOIN responses r ON r.id = a.response_id WHERE r.survey_id = ?', [$id]) as $x) $ans[$x['response_id']][$x['question_id']] = $x['value'];

    if ($a === 'export') {
        $rows = [array_merge(['fecha', 'email', 'nombre', 'empresa', 'idioma', 'verificada'], array_column($questions, 'label'))];
        foreach ($responses as $r) {
            $row = [$r['created_at'], $r['email'], trim($r['nombre'] . ' ' . $r['apellidos']), (string)$r['empresa'], lang_of($r['lang'] ?? 'es'), empty($r['verified']) ? 'no' : 'sí'];
            foreach ($questions as $qq) {
                $v = $ans[$r['id']][$qq['id']] ?? '';
                $row[] = in_array($qq['type'], ['nps', 'rating', 'faces', 'scale', 'slider'], true) && $v !== '' ? (int)$v : $v;
            }
            $rows[] = $row;
        }
        download_table($rows, 'resultados-' . $survey['slug'] . ($view ? '-' . $view : '') . '-' . date('Ymd'), ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv');
    }

    $summary = survey_lang_summary($survey);
    $sentTo = $summary[$view]['sent'];
    $vq = $view ? '&v=' . $view : '';
    $sign = fn($n) => $n === null ? '—' : ($n > 0 ? '+' : '') . $n;
    layout_start('Resultados', 'surveys'); ?>
    <div class="head"><h1>Resultados: <?= e($survey['title']) ?></h1>
      <div class="actions">
        <a class="btn" href="index.php?p=surveys&a=export&format=csv&id=<?= $id . $vq ?>">Exportar CSV</a>
        <a class="btn" href="index.php?p=surveys&a=export&format=xlsx&id=<?= $id . $vq ?>">Exportar Excel</a>
        <a class="btn" href="index.php?p=surveys&a=edit&id=<?= $id ?>">Editar</a>
        <a class="btn" href="index.php?p=surveys">Volver</a>
      </div></div>

    <div class="card table langsum"><table>
      <thead><tr><th>Resultados</th><th>Enviados</th><th>Respuestas</th><th>Tasa</th><th>NPS</th><th>Promotores (9–10)</th><th>Pasivos (7–8)</th><th>Detractores (0–6)</th><th>Nota media</th></tr></thead>
      <tbody>
      <?php foreach ($summary as $lg => $row): $q0 = $row['q']; $n0 = max(1, $q0['n'] ?? 0); ?>
        <tr class="<?= $lg === $view ? 'cur' : '' ?>">
          <td><a href="index.php?p=surveys&a=results&id=<?= $id . ($lg ? '&v=' . $lg : '') ?>"><b><?= e($row['label']) ?></b></a></td>
          <td><?= $row['sent'] ?></td><td><?= $row['responses'] ?></td>
          <td><?= $row['sent'] ? round(min(100, $row['responses'] / $row['sent'] * 100)) . '%' : '—' ?></td>
          <?php if ($q0): ?>
            <td><span class="npsbadge <?= $q0['nps'] === null ? '' : ($q0['nps'] >= 50 ? 'good' : ($q0['nps'] >= 0 ? 'mid' : 'bad')) ?>"><?= $sign($q0['nps']) ?></span></td>
            <td><?= $q0['promoters'] ?> <span class="muted">· <?= $q0['n'] ? round($q0['promoters'] / $n0 * 100) . '%' : '—' ?></span></td>
            <td><?= $q0['passives'] ?> <span class="muted">· <?= $q0['n'] ? round($q0['passives'] / $n0 * 100) . '%' : '—' ?></span></td>
            <td><?= $q0['detractors'] ?> <span class="muted">· <?= $q0['n'] ? round($q0['detractors'] / $n0 * 100) . '%' : '—' ?></span></td>
            <td><?= $q0['avg'] === null ? '—' : number_format($q0['avg'], 1, ',', '') ?></td>
          <?php else: ?><td colspan="5" class="muted">Esta encuesta no tiene pregunta NPS</td><?php endif ?>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>

    <div class="filters">
      <span class="muted">Detalle de:</span>
      <div class="seg"><?php foreach ($summary as $lg => $row): ?><a class="<?= $lg === $view ? 'on' : '' ?>" href="index.php?p=surveys&a=results&id=<?= $id . ($lg ? '&v=' . $lg : '') ?>"><?= e($lg === '' ? 'General' : $row['label']) ?> <i><?= $row['responses'] ?></i></a><?php endforeach ?></div>
    </div>
    <div class="kpis">
      <?php $nUnv = count(array_filter($responses, fn($r) => empty($r['verified']))); ?>
      <div class="card kpi"><span>Respuestas · <?= e($summary[$view]['label']) ?><?= $nUnv ? " · $nUnv sin verificar" : '' ?></span><b><?= count($responses) ?></b></div>
      <div class="card kpi"><span>Contactos que recibieron la encuesta</span><b><?= $sentTo ?></b></div>
      <div class="card kpi"><span>Tasa de respuesta</span><b><?= $sentTo ? round(min(100, count($responses) / $sentTo * 100)) . '%' : '—' ?></b></div>
    </div>
    <?php foreach ($questions as $qq): ?>
      <div class="card result">
        <h3><?= e($qq['label']) ?> <small><?= $qq['n'] ?> respuesta(s)</small></h3>
        <?php if ($qq['type'] === 'nps'): $n = max(1, $qq['n']); ?>
          <div class="nps">
            <div class="score <?= $qq['nps'] === null ? '' : ($qq['nps'] >= 50 ? 'good' : ($qq['nps'] >= 0 ? 'mid' : 'bad')) ?>">
              <span>NPS</span><b><?= $qq['nps'] === null ? '—' : ($qq['nps'] > 0 ? '+' : '') . $qq['nps'] ?></b>
              <small>de −100 a +100</small>
            </div>
            <div class="groups">
              <div class="stack">
                <i class="p" style="width:<?= $qq['promoters'] / $n * 100 ?>%"></i><i class="n" style="width:<?= $qq['passives'] / $n * 100 ?>%"></i><i class="d" style="width:<?= $qq['detractors'] / $n * 100 ?>%"></i>
              </div>
              <ul>
                <li><i class="dot p"></i>Promotores (9–10): <b><?= $qq['promoters'] ?></b> · <?= round($qq['promoters'] / $n * 100) ?>%</li>
                <li><i class="dot n"></i>Pasivos (7–8): <b><?= $qq['passives'] ?></b> · <?= round($qq['passives'] / $n * 100) ?>%</li>
                <li><i class="dot d"></i>Detractores (0–6): <b><?= $qq['detractors'] ?></b> · <?= round($qq['detractors'] / $n * 100) ?>%</li>
              </ul>
              <p class="muted">NPS = % promotores − % detractores. Nota media: <?= $qq['avg'] === null ? '—' : number_format($qq['avg'], 1, ',', '') ?></p>
            </div>
          </div>
        <?php endif ?>
        <?php if (isset($qq['dist'])): $mx = max(1, max($qq['dist'] ?: [0])); ?>
          <?php if (in_array($qq['type'], ['rating', 'faces', 'scale'], true)): ?><p class="muted">Nota media: <b><?= $qq['avg'] === null ? '—' : number_format($qq['avg'], 2, ',', '') ?></b></p><?php endif ?>
          <div class="dist">
          <?php foreach ($qq['dist'] as $k => $v):
              $cls = $qq['type'] === 'nps' ? ($k <= 6 ? 'd' : ($k <= 8 ? 'n' : 'p')) : ''; ?>
            <div class="drow"><span class="lab"><?= e(sf_dist_label($qq, $k)) ?></span><span class="track"><i class="<?= $cls ?>" style="width:<?= $v / $mx * 100 ?>%"></i></span><span class="num"><?= $v ?></span></div>
          <?php endforeach ?>
          </div>
        <?php elseif (($extra = sf_result_extra($qq)) !== ''): ?>
          <?= $extra ?>
        <?php else: ?>
          <?php if (empty($qq['texts'])): ?><p class="muted">Sin respuestas todavía.</p><?php endif ?>
          <ul class="texts"><?php foreach (array_slice(array_reverse($qq['texts'] ?? []), 0, 100) as $t): ?><li><?= nl2br(e($t)) ?></li><?php endforeach ?></ul>
        <?php endif ?>
      </div>
    <?php endforeach ?>

    <h2>Respuestas individuales</h2>
    <div class="card table"><table>
      <thead><tr><th>Fecha</th><th>Correo</th><?php foreach ($questions as $qq): ?><th><?= e(mb_strimwidth($qq['label'], 0, 40, '…')) ?></th><?php endforeach ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($responses as $r): ?>
        <tr><td class="nowrap muted"><?= fecha($r['created_at']) ?></td><td><?= e($r['email']) ?><?= ($r['lang'] ?? '') === 'en' ? ' <span class="tag">EN</span>' : '' ?><?= empty($r['verified']) ? ' <span class="pill pending" title="Formulario público: el correo lo escribió quien respondió">Sin verificar</span>' : '' ?><?= $r['empresa'] ? '<br><span class="muted">' . e($r['empresa']) . '</span>' : '' ?></td>
          <?php foreach ($questions as $qq): ?><td><?= nl2br(e($ans[$r['id']][$qq['id']] ?? '')) ?></td><?php endforeach ?>
          <td><form method="post" action="index.php?p=surveys&a=delresp&id=<?= $id ?>" onsubmit="return confirm('¿Eliminar esta respuesta?')"><?= csrf_field() ?><input type="hidden" name="rid" value="<?= $r['id'] ?>"><button class="btn small danger" title="Eliminar">×</button></form></td></tr>
      <?php endforeach ?>
      <?php if (!$responses): ?><tr><td colspan="<?= count($questions) + 3 ?>" class="empty">Todavía no hay respuestas.</td></tr><?php endif ?>
      </tbody>
    </table></div>
    <?php layout_end(); return;
}

/* ---------- Editor ---------- */
if ($a === 'edit') {
    $s = $survey ?? ['id' => 0, 'slug' => '', 'title' => '', 'description' => '', 'thanks' => '¡Gracias por su respuesta!', 'max_per_contact' => 1, 'active' => 1, 'is_public' => 0, 'config' => ''];
    $scfg = json_decode((string)($s['config'] ?? ''), true) ?: [];
    $qs = $s['id'] ? all('SELECT * FROM questions WHERE survey_id = ? ORDER BY position, id', [$s['id']]) : [];
    if (!$qs) $qs = [['id' => 0, 'type' => 'nps', 'label' => '', 'options' => '', 'is_required' => 1, 'config' => '']];
    $idx = [];
    foreach ($qs as $i => $q) $idx[(int)$q['id']] = $i; // la condición guarda el id; el formulario, la posición
    $nResp = $s['id'] ? (int)val('SELECT COUNT(*) FROM responses WHERE survey_id = ?', [$s['id']]) : 0;
    $types = sf_types();
    $typeSelect = function (string $cur) use ($types): string {
        $o = '';
        $g = '';
        foreach ($types as $k => $t) {
            if ($t[2] !== $g) { $o .= ($g !== '' ? '</optgroup>' : '') . '<optgroup label="' . e($t[2]) . '">'; $g = $t[2]; }
            $o .= '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . ' data-ic="' . e($t[1]) . '">' . e($t[1] . ' ' . $t[0]) . '</option>';
        }
        return $o . '</optgroup>';
    };
    $qrow = function ($i, $q, bool $open = false) use ($typeSelect, $types, $idx) {
        $c = sf_cfg($q);
        $t = (string)$q['type'];
        $ref = !empty($c['cond_q']) && isset($idx[(int)$c['cond_q']]) ? (string)$idx[(int)$c['cond_q']] : '';
        $n = 'q[' . $i . ']';
        $sc = $t === 'scale' ? $c : [];
        $sl = $t === 'slider' ? $c : [];
        ob_start(); ?>
      <details class="qcard" data-q data-i="<?= e((string)$i) ?>"<?= $open ? ' open' : '' ?>>
        <summary><span class="qgrip" draggable="true" title="Arrastra para ordenar">⋮⋮</span><span class="qn"></span><span class="qic" data-qic><?= e($types[$t][1] ?? '') ?></span>
          <span class="qlab" data-qlab><?= $q['label'] !== '' ? e($q['label']) : '<i>Sin texto</i>' ?></span><span class="qty" data-qty><?= e($types[$t][0] ?? '') ?></span>
          <span class="qreq" data-qreq<?= !empty($q['is_required']) && $t !== 'section' ? '' : ' hidden' ?>>Obligatoria</span><span class="qcondb" data-qcondb<?= $ref !== '' ? '' : ' hidden' ?> title="Se muestra según otra respuesta">⤷ condicional</span></summary>
        <div class="qbody">
          <input type="hidden" name="<?= $n ?>[id]" value="<?= (int)$q['id'] ?>">
          <div class="row2">
            <label><span data-label-q>Pregunta</span><input name="<?= $n ?>[label]" value="<?= e($q['label']) ?>" data-qlabel></label>
            <label class="en">En inglés<input name="<?= $n ?>[label_en]" value="<?= e($q['label_en'] ?? '') ?>" placeholder="Vacío: se muestra en español" lang="en"></label>
          </div>
          <div class="row2">
            <label>Tipo<select name="<?= $n ?>[type]" data-qtype><?= $typeSelect($t) ?></select></label>
            <label>Ayuda bajo la pregunta (opcional)<input name="<?= $n ?>[help]" value="<?= e($c['help'] ?? '') ?>" maxlength="500" placeholder="Por ejemplo: piense en su última visita"></label>
          </div>
          <label class="en" data-hide="xx">Ayuda en inglés<input name="<?= $n ?>[help_en]" value="<?= e($c['help_en'] ?? '') ?>" maxlength="500" lang="en"></label>
          <div class="row2" data-show="radio checkbox select rank matrix">
            <label><span data-opts-label>Opciones (una por línea)</span><textarea name="<?= $n ?>[options]" rows="4"><?= e($q['options']) ?></textarea></label>
            <label class="en">En inglés (mismo orden y número de líneas)<textarea name="<?= $n ?>[options_en]" rows="4" lang="en"><?= e($q['options_en'] ?? '') ?></textarea></label>
          </div>
          <div class="row2" data-show="matrix">
            <label>Filas: lo que se valora (una por línea)<textarea name="<?= $n ?>[rows]" rows="4" placeholder="Precio&#10;Calidad&#10;Plazo de entrega"><?= e($c['rows'] ?? '') ?></textarea></label>
            <label class="en">Filas en inglés<textarea name="<?= $n ?>[rows_en]" rows="4" lang="en"><?= e($c['rows_en'] ?? '') ?></textarea></label>
          </div>
          <div class="qgrid" data-show="scale">
            <label>Desde<select name="<?= $n ?>[s_min]"><?php foreach ([0, 1] as $v): ?><option<?= (int)($sc['min'] ?? 1) === $v ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?></select></label>
            <label>Hasta<select name="<?= $n ?>[s_max]"><?php for ($v = 3; $v <= 10; $v++): ?><option<?= (int)($sc['max'] ?? 5) === $v ? ' selected' : '' ?>><?= $v ?></option><?php endfor ?></select></label>
            <label>Texto del extremo bajo<input name="<?= $n ?>[s_lo]" value="<?= e($sc['lo'] ?? '') ?>" maxlength="60" placeholder="Muy difícil"></label>
            <label>Texto del extremo alto<input name="<?= $n ?>[s_hi]" value="<?= e($sc['hi'] ?? '') ?>" maxlength="60" placeholder="Muy fácil"></label>
            <label class="en">Bajo en inglés<input name="<?= $n ?>[s_lo_en]" value="<?= e($sc['lo_en'] ?? '') ?>" maxlength="60" lang="en"></label>
            <label class="en">Alto en inglés<input name="<?= $n ?>[s_hi_en]" value="<?= e($sc['hi_en'] ?? '') ?>" maxlength="60" lang="en"></label>
          </div>
          <div class="qgrid" data-show="slider">
            <label>Mínimo<input type="number" name="<?= $n ?>[d_min]" value="<?= (int)($sl['min'] ?? 0) ?>"></label>
            <label>Máximo<input type="number" name="<?= $n ?>[d_max]" value="<?= (int)($sl['max'] ?? 100) ?>"></label>
            <label>Unidad (opcional)<input name="<?= $n ?>[d_unit]" value="<?= e($sl['unit'] ?? '') ?>" maxlength="10" placeholder="%"></label>
            <label>Texto del extremo bajo<input name="<?= $n ?>[d_lo]" value="<?= e($sl['lo'] ?? '') ?>" maxlength="60"></label>
            <label>Texto del extremo alto<input name="<?= $n ?>[d_hi]" value="<?= e($sl['hi'] ?? '') ?>" maxlength="60"></label>
          </div>
          <label class="check" data-hide="section"><input type="checkbox" name="<?= $n ?>[required]" data-qrequired<?= !empty($q['is_required']) ? ' checked' : '' ?>> Obligatoria</label>
          <div class="qcond" data-hide="section">
            <label class="check"><input type="checkbox" data-cond-on<?= $ref !== '' ? ' checked' : '' ?>> Mostrar solo si…</label>
            <span class="qcond-box" data-cond-box<?= $ref !== '' ? '' : ' hidden' ?>>
              <select name="<?= $n ?>[cond_ref]" data-cond-ref data-cur="<?= e($ref) ?>"><option value="">la pregunta…</option></select>
              <select name="<?= $n ?>[cond_op]"><?php foreach (['eq' => 'es', 'ne' => 'no es', 'le' => 'es igual o menor que', 'ge' => 'es igual o mayor que'] as $k => $l): ?><option value="<?= $k ?>"<?= ($c['cond_op'] ?? 'eq') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
              <input name="<?= $n ?>[cond_v]" value="<?= e($c['cond_v'] ?? '') ?>" placeholder="valor (6, Sí, una opción…)" maxlength="200">
            </span>
          </div>
          <div class="qtools"><button type="button" class="btn small" data-up title="Subir">↑</button><button type="button" class="btn small" data-down title="Bajar">↓</button><button type="button" class="btn small" data-dup>Duplicar</button><button type="button" class="btn small danger" data-del>Quitar</button></div>
        </div>
      </details>
    <?php return ob_get_clean(); };
    $mailOpts = all("SELECT id, name, lang FROM emails WHERE name NOT LIKE 'NPS-Q%' ORDER BY name");
    $opt = function ($sel) use ($mailOpts) { $o = '<option value="">No enviar</option>';
        foreach ($mailOpts as $m) $o .= '<option value="' . (int)$m['id'] . '"' . ((int)$sel === (int)$m['id'] ? ' selected' : '') . '>' . e($m['name']) . ($m['lang'] === 'en' ? ' (EN)' : '') . '</option>';
        return $o; };
    layout_start($s['id'] ? 'Editar encuesta' : 'Nueva encuesta', 'surveys'); ?>
    <form method="post" action="index.php?p=surveys&a=save" class="sf-editor" data-sf-editor data-preview="index.php?p=surveys&a=livepreview">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="pv_lang" value="es" data-pv-lang>
    <div class="sf-bar">
      <input class="sf-title" name="title" value="<?= e($s['title']) ?>" required placeholder="Título de la encuesta" aria-label="Título de la encuesta">
      <span class="pill <?= $s['active'] ? 'subscribed' : 'unsubscribed' ?>" data-status-pill><?= $s['active'] ? 'Abierta' : 'Cerrada' ?></span>
      <span class="sf-sp"></span>
      <?php if ($s['id']): ?><a class="btn" href="index.php?p=surveys&a=results&id=<?= $s['id'] ?>">Resultados <span class="sf-count"><?= $nResp ?></span></a><a class="btn" target="_blank" href="s.php?s=<?= e($s['slug']) ?>&preview=<?= sign('preview|' . $s['id']) ?>">Vista previa ↗</a><?php endif ?>
      <a class="btn" href="index.php?p=surveys">Volver</a>
      <button class="btn primary">Guardar</button>
    </div>
    <nav class="sf-tabs" role="tablist"><button type="button" class="on" data-tab="q" role="tab">Preguntas <i data-qcount><?= count($qs) ?></i></button><button type="button" data-tab="t" role="tab">Textos e idiomas</button><button type="button" data-tab="a" role="tab">Avisos y agradecimiento</button><button type="button" data-tab="o" role="tab">Opciones</button></nav>

    <section class="sf-panel" data-panel="q">
      <div class="sf-ed">
        <div>
          <div id="questions"><?php foreach ($qs as $i => $q) echo $qrow($i, $q, count($qs) === 1); ?></div>
          <div class="sf-add"><h3>+ Añadir pregunta</h3><div class="sf-types">
            <?php $g = ''; foreach ($types as $k => $t): if ($t[2] !== $g): $g = $t[2]; ?><span class="sf-tg"><?= e($g) ?></span><?php endif ?>
              <button type="button" class="sf-tb" data-add="<?= $k ?>"><span class="ic"><?= e($t[1]) ?></span><span><?= e($t[0]) ?><?php if (sf_type_hint($k) !== ''): ?><small><?= e(sf_type_hint($k)) ?></small><?php endif ?></span><?php if (in_array($k, SF_NEW, true)): ?><em>nuevo</em><?php endif ?></button>
            <?php endforeach ?>
          </div></div>
          <p class="muted">Las respuestas en español y en inglés se guardan juntas. El correo se pide siempre al principio y se rellena solo cuando el contacto llega desde un correo. En el correo se muestran las notas de la primera pregunta de escala (0–10 o estrellas).</p>
        </div>
        <aside class="sf-pv">
          <div class="sf-pv-cap"><span>Vista previa en directo</span><span class="seg"><a href="#" class="on" data-pv="es">ES</a><a href="#" data-pv="en">EN</a></span></div>
          <div class="sf-phone"><iframe name="sfprev" title="Vista previa de la encuesta" data-pv-frame></iframe></div>
        </aside>
      </div>
    </section>

    <section class="sf-panel" data-panel="t">
      <div class="card form">
        <div class="row2">
          <label>Identificador en la dirección web<input name="slug" value="<?= e($s['slug']) ?>" placeholder="se genera a partir del título"></label>
          <label class="en">Título en inglés<input name="title_en" value="<?= e($s['title_en'] ?? '') ?>" lang="en"></label>
        </div>
        <div class="row2">
          <label>Texto de introducción (opcional)<textarea name="description" rows="3"><?= e($s['description']) ?></textarea></label>
          <label class="en">Introducción en inglés<textarea name="description_en" rows="3" lang="en"><?= e($s['description_en'] ?? '') ?></textarea></label>
        </div>
        <div class="row2">
          <label>Mensaje de agradecimiento<textarea name="thanks" rows="3"><?= e($s['thanks']) ?></textarea></label>
          <label class="en">Agradecimiento en inglés<textarea name="thanks_en" rows="3" lang="en"><?= e($s['thanks_en'] ?? '') ?></textarea></label>
        </div>
        <p class="muted">Los textos en inglés se muestran cuando el contacto llega desde un correo en inglés. Si se dejan vacíos, se muestran en español.</p>
      </div>
    </section>

    <section class="sf-panel" data-panel="a">
      <div class="card form">
      <div class="notifybox">
        <h3>Aviso por correo de las respuestas</h3>
        <div class="row3 n3">
          <label>Enviar un aviso
            <select name="notify_mode" data-notifymode>
              <option value="">No avisar</option>
              <option value="all" <?= ($s['notify_mode'] ?? '') === 'all' ? 'selected' : '' ?>>En cada respuesta</option>
              <option value="low" <?= ($s['notify_mode'] ?? '') === 'low' ? 'selected' : '' ?>>Solo si la nota es igual o menor que…</option>
            </select></label>
          <label data-notifymax>Nota máxima que avisa<input type="number" min="0" max="10" name="notify_max" value="<?= (int)($s['notify_max'] ?? 6) ?>"></label>
        </div>
        <div class="row2" data-notifyto>
          <label>Avisar a · respuestas en español<input name="notify_es" value="<?= e($s['notify_es'] ?? '') ?>" placeholder="correo@empresa.com"></label>
          <label class="en">Avisar a · respuestas en inglés<input name="notify_en" value="<?= e($s['notify_en'] ?? '') ?>" placeholder="correo@empresa.com"></label>
        </div>
        <p class="muted" data-notifyto>Varias direcciones separadas por comas. Si la de un idioma se deja vacía, sus avisos van a la del otro. La nota es la de la primera pregunta de escala (NPS o 1–5). El aviso incluye todas las respuestas y permite contestar directamente al cliente.<?= setting('notify_last_error') !== '' ? ' <b style="color:#a12626">Último aviso fallido: ' . e(setting('notify_last_error')) . '</b>' : '' ?></p>
      </div>
      <div class="notifybox">
        <h3>Correo de agradecimiento al cliente</h3>
        <div class="row3 n3">
          <label>Correo · respuestas en español<select name="thank_es"><?= $opt($s['thank_es'] ?? null) ?></select></label>
          <label class="en">Correo · respuestas en inglés<select name="thank_en"><?= $opt($s['thank_en'] ?? null) ?></select></label>
          <label>Enviar si la nota es al menos<select name="thank_min">
            <option value="0">Cualquier nota</option>
            <?php for ($i = 1; $i <= 10; $i++): ?><option value="<?= $i ?>" <?= (int)($s['thank_min'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?><?= $i === 9 ? ' (promotores)' : '' ?></option><?php endfor ?>
          </select></label>
        </div>
        <p class="muted">Se envía en el momento, al responder desde el enlace personal de un correo (no desde el formulario público, donde el correo lo escribe quien responde). Un envío por contacto. Para pedir reseñas solo a los clientes satisfechos, elija «9 (promotores)». El botón de Google usa el enlace de <a href="index.php?p=settings">Ajustes → Reseñas en Google</a><?= setting('google_review_url') === '' ? ' <b style="color:#a12626">(todavía vacío: mientras tanto no se envía)</b>' : '' ?>.<?= setting('thanks_last_error') !== '' ? ' <b style="color:#a12626">' . e(setting('thanks_last_error')) . '</b>' : '' ?></p>
      </div>
      </div>
    </section>

    <section class="sf-panel" data-panel="o">
      <div class="card form">
        <div class="row2">
          <label>Cómo se muestran las preguntas<select name="steps">
            <option value="">Todas en una página</option>
            <option value="question"<?= ($scfg['steps'] ?? '') === 'question' ? ' selected' : '' ?>>Por pasos: una pregunta por pantalla</option>
            <option value="section"<?= ($scfg['steps'] ?? '') === 'section' ? ' selected' : '' ?>>Por pasos: una sección por pantalla (con «Título de sección»)</option>
          </select><small class="muted">Por pasos se ve una barra de progreso y los botones Atrás y Siguiente.</small></label>
          <label>Máximo de respuestas por contacto (0 = sin límite)<input type="number" min="0" name="max_per_contact" value="<?= (int)$s['max_per_contact'] ?>"></label>
        </div>
        <label class="check"><input type="checkbox" name="active" data-active <?= $s['active'] ? 'checked' : '' ?>> Encuesta abierta (admite respuestas)</label>
        <label class="check"><input type="checkbox" name="is_public" <?= $s['is_public'] ? 'checked' : '' ?>> Encuesta pública: permitir responder sin enlace personal (escribiendo el correo)</label>
        <p class="muted" style="margin:-.4rem 0 0 1.6rem">Esas respuestas quedan marcadas «sin verificar»: el correo lo escribe quien responde y puede no ser suyo. Desactívelo cuando importe saber quién responde (por ejemplo, el NPS).</p>
      </div>
      <?php if ($s['id']): ?>
      <div class="card"><button class="btn danger small" form="sf-delete">Eliminar encuesta</button> <span class="muted">Borra también todas sus respuestas.</span></div>
      <?php endif ?>
    </section>
    <template id="qtpl"><?= $qrow('__I__', ['id' => 0, 'type' => 'text', 'label' => '', 'options' => '', 'is_required' => 0, 'config' => ''], true) ?></template>
    </form>
    <?php if ($s['id']): ?>
    <form method="post" id="sf-delete" action="index.php?p=surveys&a=delete&id=<?= (int)$s['id'] ?>" onsubmit="return confirm('¿Eliminar la encuesta y todas sus respuestas?')"><?= csrf_field() ?></form>
    <?php endif ?>
    <?php layout_end(); return;
}

/* ---------- Listado ---------- */
$rows = all('SELECT s.*, (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) n FROM surveys s ORDER BY s.id DESC');
$vista = in_array($_GET['vista'] ?? '', ['cuadricula', 'lista'], true) ? $_GET['vista'] : (string)($_SESSION['surveys_view'] ?? 'cuadricula');
$_SESSION['surveys_view'] = $vista;
layout_start('Encuestas', 'surveys'); ?>
<div class="head"><h1>Encuestas</h1><a class="btn primary" href="index.php?p=surveys&a=edit">+ Nueva encuesta</a></div>
<div class="vbar"><span class="muted"><?= count($rows) ?> encuesta(s)</span><?= view_switch('index.php?p=surveys', $vista) ?></div>
<?php if ($vista === 'cuadricula'): ?>
  <?php if (!$rows): ?><div class="card"><p class="empty">Todavía no hay encuestas.</p></div><?php endif ?>
  <div class="co-grid"><?php foreach ($rows as $r):
      $nps = null;
      foreach (survey_stats($r) as $qq) if ($qq['type'] === 'nps') { $nps = $qq['nps']; break; }
      $nq = (int)val("SELECT COUNT(*) FROM questions WHERE survey_id = ? AND type <> 'section'", [$r['id']]);
      $last = val('SELECT MAX(created_at) FROM responses WHERE survey_id = ?', [$r['id']]); ?>
    <article class="co-tile ml-tile">
      <div class="co-tile-top"><span class="ml-ic" aria-hidden="true">📊</span><div><h2 class="co-name"><a href="index.php?p=surveys&a=edit&id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a></h2>
        <span class="pill <?= $r['active'] ? 'subscribed' : 'unsubscribed' ?>"><?= $r['active'] ? 'Abierta' : 'Cerrada' ?></span></div></div>
      <p class="co-tile-sub"><?= $nq ?> pregunta(s) · <?= $r['max_per_contact'] ? 'máx. ' . (int)$r['max_per_contact'] . ' por contacto' : 'sin límite por contacto' ?><br><?= $r['is_public'] ? 'Pública' : 'Solo con enlace personal del correo' ?></p>
      <div class="co-tile-kpi"><div><small>Respuestas</small><b><?= (int)$r['n'] ?></b></div><div><small>Última</small><b><?= $last ? e(substr(fecha($last), 0, 10)) : '—' ?></b></div><div><small>NPS</small><b><?= $nps === null ? '—' : ($nps > 0 ? '+' : '') . $nps ?></b></div></div>
      <div class="ml-acts"><a class="btn small primary" href="index.php?p=surveys&a=results&id=<?= (int)$r['id'] ?>">Resultados</a> <a class="btn small" href="index.php?p=surveys&a=edit&id=<?= (int)$r['id'] ?>">Editar</a><?php if ($r['is_public']): ?> <a class="btn small" href="s.php?s=<?= e($r['slug']) ?>" target="_blank" rel="noopener">Abrir ↗</a><?php endif ?></div>
    </article>
  <?php endforeach ?></div>
<?php layout_end(); return; endif ?>
<div class="card table"><table>
  <thead><tr><th>Título</th><th>Estado</th><th>Límite por contacto</th><th>Respuestas</th><th>NPS</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $nps = null;
      foreach (survey_stats($r) as $qq) if ($qq['type'] === 'nps') { $nps = $qq['nps']; break; } ?>
    <tr>
      <td><a href="index.php?p=surveys&a=edit&id=<?= $r['id'] ?>"><b><?= e($r['title']) ?></b></a><br><span class="muted"><?= $r['is_public'] ? 'Pública: ' . e(base_url() . '/s.php?s=' . $r['slug']) : 'Solo con enlace personal del correo' ?></span></td>
      <td><span class="pill <?= $r['active'] ? 'subscribed' : 'unsubscribed' ?>"><?= $r['active'] ? 'Abierta' : 'Cerrada' ?></span></td>
      <td><?= $r['max_per_contact'] ? (int)$r['max_per_contact'] : 'Sin límite' ?></td>
      <td><?= $r['n'] ?></td>
      <td><b><?= $nps === null ? '—' : ($nps > 0 ? '+' : '') . $nps ?></b></td>
      <td class="nowrap"><a class="btn small primary" href="index.php?p=surveys&a=results&id=<?= $r['id'] ?>">Resultados</a></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">Todavía no hay encuestas.</td></tr><?php endif ?>
  </tbody>
</table></div>
<?php layout_end();
