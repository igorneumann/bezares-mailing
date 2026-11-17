<?php
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$c = nps_config();
$tags = all_tags();

/* ---------- NPS automático: guardar configuración ---------- */
if ($a === 'npssave' && $isPost) {
    $dates = nps_parse_dates((string)($_POST['nps_dates'] ?? ''));
    if (!$dates) { flash('Indique al menos una fecha de envío con el formato día/mes (por ejemplo 15/01).', 'err'); redirect('index.php?p=auto'); }
    set_setting('nps_dates', implode(', ', array_map(fn($d) => sprintf('%02d/%02d', $d[0], $d[1]), $dates)));
    set_setting('nps_hour', preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', (string)($_POST['nps_hour'] ?? '')) ? $_POST['nps_hour'] : '09:00');
    set_setting('nps_enabled', empty($_POST['nps_enabled']) ? '0' : '1');
    set_setting('nps_tag', trim((string)($_POST['nps_tag'] ?? '')));
    set_setting('nps_tag_en', trim((string)($_POST['nps_tag_en'] ?? '')));
    set_setting('nps_template_en', (string)(int)($_POST['nps_template_en'] ?? 0));
    set_setting('nps_reminder_prefix_en', (string)($_POST['nps_reminder_prefix_en'] ?? ''));
    set_setting('nps_template', (string)(int)($_POST['nps_template'] ?? 0));
    set_setting('nps_reminder_days', (string)max(0, (int)($_POST['nps_reminder_days'] ?? 7)));
    set_setting('nps_results_days', (string)max(1, (int)($_POST['nps_results_days'] ?? 7)));
    set_setting('nps_reminder_prefix', (string)($_POST['nps_reminder_prefix'] ?? ''));
    set_setting('nps_admins', trim((string)($_POST['nps_admins'] ?? '')));
    flash('Configuración del NPS automático guardada.');
    redirect('index.php?p=auto');
}
/* ---------- Lanzar ahora el periodo actual ---------- */
if ($a === 'npsnow' && $isPost) {
    try {
        $label = trim((string)($_POST['label'] ?? ''));
        if (!preg_match('/^NPS-Q\d{1,2}-\d{4}$/', $label)) throw new RuntimeException('Nombre de campaña no válido (ejemplo: NPS-Q1-2027).');
        $r = nps_launch($label);
        flash("Campaña {$r['label']} creada: encuesta y correos generados; en cola {$r['es']} envío(s) en español y {$r['en']} en inglés.");
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php?p=auto');
}
/* ---------- Cambiar fechas o cancelar pasos de una campaña ---------- */
if ($a === 'npsrun' && $isPost) {
    $run = one('SELECT * FROM nps_runs WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    if ($run) {
        $dt = function ($v) { $t = strtotime(str_replace('T', ' ', (string)$v)); return $t ? date('Y-m-d H:i:s', $t) : null; };
        if (($_POST['do'] ?? '') === 'cancel') {
            q('UPDATE nps_runs SET reminder_done = CASE WHEN reminder_done = 0 THEN 2 ELSE reminder_done END, results_done = CASE WHEN results_done = 0 THEN 2 ELSE results_done END, note = ? WHERE id = ?',
                ['Pasos pendientes cancelados', $run['id']]);
            flash('Pasos pendientes cancelados.');
        } elseif (($_POST['do'] ?? '') === 'results') {
            try { $n = nps_send_results($run); flash("Resultados enviados a $n administrador(es)."); }
            catch (Throwable $e) { flash('No se pudieron enviar los resultados: ' . $e->getMessage(), 'err'); }
        } else {
            if (!$run['reminder_done'] && ($x = $dt($_POST['reminder_at'] ?? ''))) q('UPDATE nps_runs SET reminder_at = ? WHERE id = ?', [$x, $run['id']]);
            if (!$run['results_done'] && ($x = $dt($_POST['results_at'] ?? ''))) q('UPDATE nps_runs SET results_at = ? WHERE id = ?', [$x, $run['id']]);
            flash('Fechas de la campaña actualizadas.');
        }
    }
    redirect('index.php?p=auto');
}
/* ---------- Formulario de suscripción ---------- */
if ($a === 'subsave' && $isPost) {
    set_setting('sub_tag_es', trim((string)($_POST['sub_tag_es'] ?? '')));
    set_setting('sub_tag_en', trim((string)($_POST['sub_tag_en'] ?? '')));
    set_setting('sub_double', empty($_POST['sub_double']) ? '0' : '1');
    set_setting('sub_fields', empty($_POST['sub_fields']) ? '0' : '1');
    set_setting('sub_privacy_es', trim((string)($_POST['sub_privacy_es'] ?? '')));
    set_setting('sub_privacy_en', trim((string)($_POST['sub_privacy_en'] ?? '')));
    flash('Formulario de suscripción guardado.');
    redirect('index.php?p=auto#suscripcion');
}
/* ---------- Generador de newsletter ---------- */
if ($a === 'newsletter' && $isPost) {
    @set_time_limit(180);
    set_setting('wp_lang', lang_of($_POST['lang'] ?? 'en'));
    foreach (['wp_url' => 'url', 'wp_count' => 'count', 'wp_button' => 'button', 'wp_subject' => 'subject'] as $k => $f) set_setting($k, trim((string)($_POST[$f] ?? '')));
    try {
        [$id, $n, $warn] = newsletter_generate([
            'url' => (string)($_POST['url'] ?? ''), 'count' => (int)($_POST['count'] ?? 4), 'name' => (string)($_POST['name'] ?? ''),
            'subject' => (string)($_POST['subject'] ?? ''), 'button' => trim((string)($_POST['button'] ?? '')),
            'header_image' => (string)($_POST['header_image'] ?? ''), 'copy_images' => !empty($_POST['copy_images']), 'lang' => lang_of($_POST['lang'] ?? 'en'),
        ]);
        $tag = trim((string)($_POST['tag'] ?? ''));
        $_SESSION['presel'][$id] = $tag;
        flash("Newsletter generada con $n entrada(s). Revísela y pulse «Guardar y enviar…»: el listado " . ($tag !== '' ? "«{$tag}»" : 'de todos los suscritos') . ' quedará ya seleccionado.');
        foreach ($warn as $w) flash($w, 'err');
        redirect('index.php?p=emails&a=edit&id=' . $id);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        redirect('index.php?p=auto#newsletter');
    }
}

$templates = all("SELECT e.id, e.name, e.lang, e.survey_id, s.title FROM emails e JOIN surveys s ON s.id = e.survey_id WHERE e.name NOT LIKE 'NPS-Q%' ORDER BY e.id");
$tpl = nps_template_email('es');
$tplEn = nps_template_email('en');
$aud = nps_audiences();
$runs = all('SELECT r.*, (SELECT COUNT(*) FROM responses x WHERE x.survey_id = r.survey_id) responses,
    (SELECT COUNT(*) FROM queue q WHERE q.send_id IN (r.send_id, r.send_en_id) AND q.status = \'sent\') sent,
    (SELECT COUNT(*) FROM queue q WHERE q.send_id = r.send_en_id AND q.status = \'sent\') sent_en,
    (SELECT COUNT(*) FROM queue q WHERE q.send_id IN (r.send_id, r.send_en_id) AND q.status IN (\'pending\',\'sending\')) pending
    FROM nps_runs r ORDER BY r.id DESC');
$cronLast = setting('cron_last');
$cronOk = $cronLast !== '' && strtotime($cronLast) > time() - 3600;
$upcoming = nps_upcoming(4);
// Periodo en curso para "lanzar ahora": la última fecha programada ya pasada
$current = '';
foreach ([(int)date('Y') - 1, (int)date('Y')] as $y) foreach (nps_schedule($y) as $s) if ($s['at'] <= time()) $current = $s['label'];
if ($current === '' && $upcoming) $current = $upcoming[0]['label'];
$headers = array_values(array_filter(media_list('image'), fn($m) => $m['name'] !== setting('logo')));
$lastHeader = (string)val("SELECT header_image FROM emails WHERE theme = 'newsletter' AND header_image <> '' ORDER BY id DESC LIMIT 1");
$step = function (int $done, ?string $at, string $label) {
    if ($done === 1) return '<span class="step done">✓ ' . $label . '</span>';
    if ($done === 2 || !$at) return '<span class="step off">' . $label . '</span>';
    return '<span class="step wait">' . $label . ': ' . date('d/m/Y H:i', strtotime($at)) . '</span>';
};

layout_start('Automatización', 'auto'); ?>
<div class="head"><h1>Automatización</h1></div>
<?php if (!$cronOk): ?><div class="flash err">La tarea programada (cron) <?= $cronLast ? 'no se ejecuta desde el ' . fecha($cronLast) : 'no se ha ejecutado nunca' ?>.
  El NPS automático y el envío de la cola dependen de ella: configúrela en el panel del hosting con la dirección que aparece en <a href="index.php?p=settings">Ajustes</a> (cada 5 minutos).</div><?php endif ?>
<?php if (setting('nps_last_error') !== ''): ?><div class="flash warn">Último error del NPS automático: <?= e(setting('nps_last_error')) ?></div><?php endif ?>

<div class="cols">
<form method="post" action="index.php?p=auto&a=npssave" class="card form">
  <?= csrf_field() ?>
  <h2>NPS automático</h2>
  <p class="muted">En cada fecha, el sistema crea una encuesta y un correo por idioma llamados <b>NPS-Q1-<?= date('Y') + 1 ?></b> (periodo y año correspondientes) a partir de la plantilla, los envía, manda un recordatorio a quien no haya contestado y después envía los resultados a los administradores.</p>
  <label class="check"><input type="checkbox" name="nps_enabled" <?= $c['enabled'] ? 'checked' : '' ?>> <b>Activar el envío automático</b></label>
  <div class="row2">
    <label>Fechas de envío (día/mes, separadas por comas)<input name="nps_dates" value="<?= e($c['dates']) ?>" placeholder="15/01, 15/04, 15/07, 15/10" required></label>
    <label>Hora de envío<input type="time" name="nps_hour" value="<?= e($c['hour']) ?>"></label>
  </div>
  <p class="muted">Cada fecha es un periodo: la primera del año es Q1, la segunda Q2, etc. Con cuatro fechas el envío es trimestral; con tres, cuatrimestral.</p>
  <div class="row2">
    <label>Listado en español
      <select name="nps_tag"><option value="">Todos los suscritos</option><?php foreach ($tags as $t => $n): ?><option value="<?= e($t) ?>" <?= $c['tag'] === (string)$t ? 'selected' : '' ?>>Etiqueta: <?= e($t) ?> (<?= $n ?>)</option><?php endforeach ?></select></label>
    <label>Listado en inglés
      <select name="nps_tag_en"><option value="">— No enviar en inglés —</option><?php foreach ($tags as $t => $n): ?><option value="<?= e($t) ?>" <?= $c['tag_en'] === (string)$t ? 'selected' : '' ?>>Etiqueta: <?= e($t) ?> (<?= $n ?>)</option><?php endforeach ?></select></label>
  </div>
  <p class="muted">Ahora mismo: <b><?= count($aud['es']) ?></b> contacto(s) recibirían el correo en español y <b><?= count($aud['en']) ?></b> en inglés. Quien esté en el listado inglés recibe solo el inglés.</p>
  <div class="row2">
    <label>Plantilla en español
      <select name="nps_template"><?php foreach ($templates as $t): if ($t['lang'] === 'en') continue; ?><option value="<?= $t['id'] ?>" <?= $tpl && (int)$tpl['id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?> — <?= e($t['title']) ?></option><?php endforeach ?></select></label>
    <label>Plantilla en inglés
      <select name="nps_template_en"><?php foreach ($templates as $t): if ($t['lang'] !== 'en') continue; ?><option value="<?= $t['id'] ?>" <?= $tplEn && (int)$tplEn['id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?> — <?= e($t['title']) ?></option><?php endforeach ?></select></label>
  </div>
  <p class="muted">Las dos plantillas deben estar enlazadas a la <b>misma encuesta</b>: así cada periodo tiene una sola encuesta (con su traducción) y el NPS se calcula con las respuestas de los dos idiomas juntas.<?= $c['tag_en'] !== '' && !$tplEn ? ' <b style="color:#a12626">Falta una plantilla en inglés enlazada a la encuesta de la plantilla en español.</b>' : '' ?></p>
  <div class="row2">
    <label>Recordatorio: días después del envío (0 = sin recordatorio)<input type="number" min="0" max="90" name="nps_reminder_days" value="<?= $c['reminder_days'] ?>"></label>
    <label>Resultados: días después del recordatorio<input type="number" min="1" max="180" name="nps_results_days" value="<?= $c['results_days'] ?>"></label>
  </div>
  <div class="row2">
    <label>Texto delante del asunto del recordatorio (español)<input name="nps_reminder_prefix" value="<?= e($c['reminder_prefix']) ?>"></label>
    <label>Texto delante del asunto del recordatorio (inglés)<input name="nps_reminder_prefix_en" value="<?= e($c['reminder_prefix_en']) ?>"></label>
  </div>
  <label>Administradores que reciben los resultados<input name="nps_admins" value="<?= e($c['admins']) ?>" placeholder="<?= e(implode(', ', nps_admin_emails())) ?>"></label>
  <p class="muted">Correos separados por comas. Si se deja vacío, los resultados van a los usuarios del panel.</p>
  <button class="btn primary">Guardar configuración</button>
</form>

<div>
  <div class="card">
    <h2 style="margin-top:0">Próximos envíos <?= $c['enabled'] ? '' : '<small>(automatización desactivada)</small>' ?></h2>
    <ul class="upcoming">
    <?php foreach ($upcoming as $s): ?>
      <li><b><?= e($s['label']) ?></b> <?= date('d/m/Y H:i', $s['at']) ?>
        <span class="muted">· recordatorio <?= $c['reminder_days'] ? date('d/m', $s['at'] + $c['reminder_days'] * 86400) : '—' ?> · resultados <?= date('d/m', $s['at'] + ($c['reminder_days'] + $c['results_days']) * 86400) ?></span></li>
    <?php endforeach ?>
    </ul>
    <form method="post" action="index.php?p=auto&a=npsnow" class="form inl" style="margin-top:1rem" onsubmit="return confirm('Se creará la campaña y se enviará ahora a todo el listado. ¿Continuar?')">
      <?= csrf_field() ?>
      <input name="label" value="<?= e($current) ?>" pattern="NPS-Q\d{1,2}-\d{4}" style="width:150px" aria-label="Nombre de la campaña">
      <button class="btn">Lanzar esta campaña ahora</button>
    </form>
    <p class="muted">Para lanzar un periodo sin esperar a la fecha (por ejemplo, para la primera prueba).</p>
  </div>

  <form method="post" action="index.php?p=auto&a=newsletter" class="card form" id="newsletter">
    <?= csrf_field() ?>
    <h2>Generador de newsletter</h2>
    <p class="muted">Crea una newsletter con las últimas entradas de un WordPress, como el correo de ejemplo: las 2 primeras en grande (imagen, titular con enlace y los dos primeros párrafos) y las siguientes en pequeño, de dos en dos lado a lado (imagen, titular y un resumen corto).</p>
    <label>Dirección del WordPress<input name="url" value="<?= e(setting('wp_url') ?: 'https://bezares.com') ?>" placeholder="https://bezares.com" required></label>
    <div class="row2">
      <label>Número de entradas<input type="number" name="count" min="1" max="20" value="<?= e(setting('wp_count') ?: '4') ?>" required></label>
      <label>Listado al que se enviará
        <select name="tag"><option value="">Todos los suscritos</option><?php foreach ($tags as $t => $n): ?><option value="<?= e($t) ?>">Etiqueta: <?= e($t) ?> (<?= $n ?>)</option><?php endforeach ?></select></label>
    </div>
    <div class="row2">
      <label>Asunto del correo<input name="subject" value="<?= e(setting('wp_subject') ?: 'Latest news from Bezares SA') ?>"></label>
      <label>Texto del botón de cada noticia<input name="button" value="<?= e(setting('wp_button') ?: 'More info >') ?>"></label>
    </div>
    <div class="row2">
      <label>Nombre interno<input name="name" value="Newsletter <?= date('d/m/Y') ?>"></label>
      <label>Imagen de cabecera
        <select name="header_image">
          <option value="default" selected><?= NEWSLETTER_HEADER ?> (por defecto)<?= newsletter_default_header() === '' ? ' — aún no está en la biblioteca' : '' ?></option>
          <?php foreach ($headers as $m): if ($m['name'] === NEWSLETTER_HEADER) continue; ?><option value="<?= e($m['url']) ?>"><?= e($m['name']) ?></option><?php endforeach ?>
          <option value="">Solo el logo</option>
        </select></label>
    </div>
    <label>Idioma de los textos fijos del correo («ver en el navegador», página de baja)<select name="lang"><option value="en">English</option><option value="es" <?= setting('wp_lang', 'en') === 'es' ? 'selected' : '' ?>>Español</option></select></label>
    <label class="check"><input type="checkbox" name="copy_images" checked> Copiar las imágenes de las noticias a la biblioteca</label>
    <button class="btn primary">Generar newsletter</button>
    <p class="muted">Se abrirá en el editor para revisarla; no se envía hasta que usted lo confirme.</p>
  </form>
</div>
</div>

<?php
$subUrl = base_url() . '/subscribe.php';
$embed = fn(string $lg) => '<iframe src="' . $subUrl . ($lg === 'en' ? '?lang=en' : '') . '" title="Newsletter" style="width:100%;max-width:580px;height:' . (setting('sub_fields', '1') === '1' ? 330 : 190) . 'px;border:0;" loading="lazy"></iframe>';
$pend = (int)val("SELECT COUNT(*) FROM contacts WHERE status = 'pending'");
?>
<div class="card form" id="suscripcion">
  <h2>Formulario de suscripción a la newsletter</h2>
  <p class="muted">Formulario público en español e inglés, sin cabecera ni marco, para incrustarlo en su web con un iframe. Copie el código del idioma que necesite.<?= $pend ? " Hay <b>$pend</b> contacto(s) pendientes de confirmar." : '' ?></p>
  <div class="cols">
    <div>
      <label>Código para incrustar · Español <a href="<?= e($subUrl) ?>" target="_blank">(ver)</a>
        <textarea rows="3" readonly onclick="this.select()" class="codebox"><?= e($embed('es')) ?></textarea></label>
      <button type="button" class="btn small" data-copy="<?= e($embed('es')) ?>">Copiar código</button>
      <label style="margin-top:1rem">Código para incrustar · English <a href="<?= e($subUrl) ?>?lang=en" target="_blank">(ver)</a>
        <textarea rows="3" readonly onclick="this.select()" class="codebox"><?= e($embed('en')) ?></textarea></label>
      <button type="button" class="btn small" data-copy="<?= e($embed('en')) ?>">Copiar código</button>
      <p class="muted" style="margin-top:.8rem">El formulario comunica su altura a la página (mensaje <code>bezaresMailingHeight</code>) por si quiere ajustar el iframe automáticamente.</p>
    </div>
    <form method="post" action="index.php?p=auto&a=subsave">
      <?= csrf_field() ?>
      <div class="row2">
        <label>Etiquetas para altas en español<input name="sub_tag_es" value="<?= e(setting('sub_tag_es', 'newsletter-es')) ?>"></label>
        <label>Etiquetas para altas en inglés<input name="sub_tag_en" value="<?= e(setting('sub_tag_en', 'newsletter-en')) ?>"></label>
      </div>
      <div class="row2">
        <label>Política de privacidad (URL, español)<input name="sub_privacy_es" value="<?= e(setting('sub_privacy_es')) ?>" placeholder="https://…"></label>
        <label>Política de privacidad (URL, inglés)<input name="sub_privacy_en" value="<?= e(setting('sub_privacy_en')) ?>" placeholder="https://…"></label>
      </div>
      <label class="check"><input type="checkbox" name="sub_double" <?= setting('sub_double', '1') === '1' ? 'checked' : '' ?>> Doble confirmación: enviar un correo con un enlace para confirmar el alta (recomendado)</label>
      <label class="check"><input type="checkbox" name="sub_fields" <?= setting('sub_fields', '1') === '1' ? 'checked' : '' ?>> Pedir también nombre, apellidos y empresa (si no, solo el correo)</label>
      <button class="btn primary">Guardar</button>
    </form>
  </div>
</div>

<h2>Campañas NPS</h2>
<div class="card table"><table>
  <thead><tr><th>Campaña</th><th>Enviado</th><th>Respuestas</th><th>Pasos</th><th>Cambiar fechas</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): $pend = !$r['reminder_done'] || !$r['results_done']; ?>
    <tr>
      <td><b><?= e($r['label']) ?></b><br><span class="muted"><?= e($r['tag'] !== '' ? 'Listados: ' . $r['tag'] : 'Todos los suscritos') ?></span></td>
      <td><?= fecha($r['sent_at']) ?><br><span class="muted"><?= $r['sent'] ?> enviados<?= $r['email_en_id'] ? ' (' . $r['sent_en'] . ' en inglés)' : '' ?><?= $r['pending'] ? ' · ' . $r['pending'] . ' en cola' : '' ?></span></td>
      <td><?= $r['responses'] ?><?= $r['sent'] ? ' <span class="muted">(' . round(min(100, $r['responses'] / $r['sent'] * 100)) . '%)</span>' : '' ?></td>
      <td><div class="steps"><span class="step done">✓ Envío</span><?= $step((int)$r['reminder_done'], $r['reminder_at'], 'Recordatorio') ?><?= $step((int)$r['results_done'], $r['results_at'], 'Resultados') ?></div>
        <?php if ($r['note']): ?><span class="muted"><?= e($r['note']) ?></span><?php endif ?></td>
      <td><?php if ($pend): ?>
        <form method="post" action="index.php?p=auto&a=npsrun" class="inl form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <?php if (!$r['reminder_done'] && $r['reminder_at']): ?><input type="datetime-local" name="reminder_at" value="<?= e(str_replace(' ', 'T', substr($r['reminder_at'], 0, 16))) ?>" title="Recordatorio"><?php endif ?>
          <?php if (!$r['results_done']): ?><input type="datetime-local" name="results_at" value="<?= e(str_replace(' ', 'T', substr((string)$r['results_at'], 0, 16))) ?>" title="Resultados"><?php endif ?>
          <button class="btn small">Guardar</button></form>
      <?php else: ?><span class="muted">—</span><?php endif ?></td>
      <td class="nowrap">
        <a class="btn small" href="index.php?p=emails&a=stats&id=<?= (int)$r['email_id'] ?>">Envío</a>
        <?php if ($r['email_en_id']): ?><a class="btn small" href="index.php?p=emails&a=stats&id=<?= (int)$r['email_en_id'] ?>">Envío (en)</a><?php endif ?>
        <a class="btn small primary" href="index.php?p=surveys&a=results&id=<?= (int)$r['survey_id'] ?>">Resultados</a>
        <form method="post" action="index.php?p=auto&a=npsrun" class="inline" onsubmit="return confirm('¿Enviar ahora los resultados a los administradores?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="do" value="results"><button class="btn small">Enviar resultados ya</button></form>
        <?php if ($pend): ?><form method="post" action="index.php?p=auto&a=npsrun" class="inline" onsubmit="return confirm('¿Cancelar el recordatorio y el envío de resultados pendientes?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="do" value="cancel"><button class="btn small danger">Cancelar pendientes</button></form><?php endif ?>
      </td>
    </tr>
  <?php endforeach ?>
  <?php if (!$runs): ?><tr><td colspan="6" class="empty">Todavía no se ha lanzado ninguna campaña NPS.</td></tr><?php endif ?>
  </tbody>
</table></div>
<?php layout_end();
