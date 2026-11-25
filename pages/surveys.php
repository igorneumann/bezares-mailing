<?php
$TYPES = ['nps' => 'Escala 0–10 (NPS)', 'rating' => 'Valoración 1–5', 'text' => 'Texto corto', 'textarea' => 'Texto largo',
    'radio' => 'Opción única', 'checkbox' => 'Opción múltiple'];
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
    $keep = [];
    $pos = 0;
    foreach ((array)($_POST['q'] ?? []) as $row) {
        $label = trim($row['label'] ?? '');
        $type = isset($TYPES[$row['type'] ?? '']) ? $row['type'] : 'text';
        if ($label === '') continue;
        $qd = [++$pos, $type, $label, trim($row['options'] ?? ''), empty($row['required']) ? 0 : 1];
        $qid = (int)($row['id'] ?? 0);
        if ($qid && one('SELECT id FROM questions WHERE id = ? AND survey_id = ?', [$qid, $id]))
            q('UPDATE questions SET position=?, type=?, label=?, options=?, is_required=? WHERE id=?', [...$qd, $qid]);
        else $qid = insert('INSERT INTO questions (position, type, label, options, is_required, survey_id) VALUES (?,?,?,?,?,?)', [...$qd, $id]);
        q('UPDATE questions SET label_en = ?, options_en = ? WHERE id = ?', [trim($row['label_en'] ?? ''), trim($row['options_en'] ?? ''), $qid]);
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
                $row[] = in_array($qq['type'], ['nps', 'rating'], true) && $v !== '' ? (int)$v : $v;
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
          <?php if ($qq['type'] === 'rating'): ?><p class="muted">Nota media: <b><?= $qq['avg'] === null ? '—' : number_format($qq['avg'], 2, ',', '') ?></b></p><?php endif ?>
          <div class="dist">
          <?php foreach ($qq['dist'] as $k => $v):
              $cls = $qq['type'] === 'nps' ? ($k <= 6 ? 'd' : ($k <= 8 ? 'n' : 'p')) : ''; ?>
            <div class="drow"><span class="lab"><?= e((string)$k) ?></span><span class="track"><i class="<?= $cls ?>" style="width:<?= $v / $mx * 100 ?>%"></i></span><span class="num"><?= $v ?></span></div>
          <?php endforeach ?>
          </div>
        <?php else: ?>
          <?php if (!$qq['texts']): ?><p class="muted">Sin respuestas todavía.</p><?php endif ?>
          <ul class="texts"><?php foreach (array_slice(array_reverse($qq['texts']), 0, 100) as $t): ?><li><?= nl2br(e($t)) ?></li><?php endforeach ?></ul>
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
    $s = $survey ?? ['id' => 0, 'slug' => '', 'title' => '', 'description' => '', 'thanks' => '¡Gracias por su respuesta!', 'max_per_contact' => 1, 'active' => 1, 'is_public' => 0];
    $qs = $s['id'] ? all('SELECT * FROM questions WHERE survey_id = ? ORDER BY position, id', [$s['id']]) : [];
    if (!$qs) $qs = [['id' => 0, 'type' => 'nps', 'label' => '', 'options' => '', 'is_required' => 1]];
    $qrow = function ($i, $q) use ($TYPES) { ob_start(); ?>
      <div class="qrow" data-q>
        <input type="hidden" name="q[<?= $i ?>][id]" value="<?= (int)$q['id'] ?>">
        <div class="row3">
          <label>Pregunta<input name="q[<?= $i ?>][label]" value="<?= e($q['label']) ?>"></label>
          <label>Tipo<select name="q[<?= $i ?>][type]" data-qtype><?php foreach ($TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $q['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></label>
          <label class="check"><input type="checkbox" name="q[<?= $i ?>][required]" <?= $q['is_required'] ? 'checked' : '' ?>> Obligatoria</label>
        </div>
        <label class="en">Pregunta en inglés<input name="q[<?= $i ?>][label_en]" value="<?= e($q['label_en'] ?? '') ?>" placeholder="Si se deja vacío se muestra en español" lang="en"></label>
        <div class="row2" data-qopts>
          <label>Opciones (una por línea)<textarea name="q[<?= $i ?>][options]" rows="3"><?= e($q['options']) ?></textarea></label>
          <label class="en">Opciones en inglés (mismo orden y número de líneas)<textarea name="q[<?= $i ?>][options_en]" rows="3" lang="en"><?= e($q['options_en'] ?? '') ?></textarea></label>
        </div>
        <div class="qtools"><button type="button" class="btn small" data-up>↑</button><button type="button" class="btn small" data-down>↓</button><button type="button" class="btn small danger" data-del>Quitar</button></div>
      </div>
    <?php return ob_get_clean(); };
    layout_start($s['id'] ? 'Editar encuesta' : 'Nueva encuesta', 'surveys'); ?>
    <form method="post" action="index.php?p=surveys&a=save">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
    <div class="head"><h1><?= $s['id'] ? 'Editar encuesta' : 'Nueva encuesta' ?></h1>
      <div class="actions"><button class="btn primary">Guardar</button>
        <?php if ($s['id']): ?><a class="btn" href="index.php?p=surveys&a=results&id=<?= $s['id'] ?>">Resultados</a><a class="btn" target="_blank" href="s.php?s=<?= e($s['slug']) ?>&preview=<?= sign('preview|' . $s['id']) ?>">Vista previa</a><a class="btn" target="_blank" href="s.php?s=<?= e($s['slug']) ?>&lang=en&preview=<?= sign('preview|' . $s['id']) ?>">En inglés</a><?php endif ?>
        <a class="btn" href="index.php?p=surveys">Volver</a></div></div>
    <div class="card form">
      <div class="row2">
        <label>Título<input name="title" value="<?= e($s['title']) ?>" required></label>
        <label>Identificador en la dirección web<input name="slug" value="<?= e($s['slug']) ?>" placeholder="se genera a partir del título"></label>
      </div>
      <label>Texto de introducción (opcional)<textarea name="description" rows="2"><?= e($s['description']) ?></textarea></label>
      <label>Mensaje de agradecimiento<textarea name="thanks" rows="2"><?= e($s['thanks']) ?></textarea></label>
      <details class="enbox" <?= trim(($s['title_en'] ?? '') . ($s['description_en'] ?? '') . ($s['thanks_en'] ?? '')) !== '' ? 'open' : '' ?>>
        <summary>Traducción al inglés (se muestra cuando el contacto llega desde un correo en inglés)</summary>
        <label>Título en inglés<input name="title_en" value="<?= e($s['title_en'] ?? '') ?>" lang="en"></label>
        <label>Texto de introducción en inglés<textarea name="description_en" rows="2" lang="en"><?= e($s['description_en'] ?? '') ?></textarea></label>
        <label>Mensaje de agradecimiento en inglés<textarea name="thanks_en" rows="2" lang="en"><?= e($s['thanks_en'] ?? '') ?></textarea></label>
      </details>
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
      <?php $mailOpts = all("SELECT id, name, lang FROM emails WHERE name NOT LIKE 'NPS-Q%' ORDER BY name");
        $opt = function ($sel) use ($mailOpts) { $o = '<option value="">No enviar</option>';
          foreach ($mailOpts as $m) $o .= '<option value="' . (int)$m['id'] . '"' . ((int)$sel === (int)$m['id'] ? ' selected' : '') . '>' . e($m['name']) . ($m['lang'] === 'en' ? ' (EN)' : '') . '</option>';
          return $o; }; ?>
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
      <div class="row2">
        <label>Máximo de respuestas por contacto (0 = sin límite)<input type="number" min="0" name="max_per_contact" value="<?= (int)$s['max_per_contact'] ?>"></label>
        <div>
          <label class="check"><input type="checkbox" name="active" <?= $s['active'] ? 'checked' : '' ?>> Encuesta abierta (admite respuestas)</label>
          <label class="check"><input type="checkbox" name="is_public" <?= $s['is_public'] ? 'checked' : '' ?>> Encuesta pública: permitir responder sin enlace personal (escribiendo el correo)</label>
          <p class="muted" style="margin:-.4rem 0 0 1.6rem">Esas respuestas quedan marcadas «sin verificar»: el correo lo escribe quien responde y puede no ser suyo. Desactívelo cuando importe saber quién responde (por ejemplo, el NPS).</p>
        </div>
      </div>
    </div>
    <h2>Preguntas</h2>
    <p class="muted">La encuesta es una sola en los dos idiomas: las respuestas en español y en inglés se guardan juntas y el resultado (y el NPS) se calcula con todas. El correo electrónico se pide siempre al principio y se rellena solo cuando el contacto llega desde un correo. En el correo se muestran las notas de la primera pregunta de tipo escala.</p>
    <div id="questions"><?php foreach ($qs as $i => $q) echo $qrow($i, $q); ?></div>
    <template id="qtpl"><?= $qrow('__I__', ['id' => 0, 'type' => 'text', 'label' => '', 'options' => '', 'is_required' => 0]) ?></template>
    <p><button type="button" class="btn" id="addq">+ Añadir pregunta</button></p>
    </form>
    <?php if ($s['id']): ?>
    <form method="post" action="index.php?p=surveys&a=delete&id=<?= (int)$s['id'] ?>" onsubmit="return confirm('¿Eliminar la encuesta y todas sus respuestas?')" class="foot"><?= csrf_field() ?><button class="btn danger small">Eliminar encuesta</button></form>
    <?php endif ?>
    <?php layout_end(); return;
}

/* ---------- Listado ---------- */
$rows = all('SELECT s.*, (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) n FROM surveys s ORDER BY s.id DESC');
layout_start('Encuestas', 'surveys'); ?>
<div class="head"><h1>Encuestas</h1><a class="btn primary" href="index.php?p=surveys&a=edit">+ Nueva encuesta</a></div>
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
