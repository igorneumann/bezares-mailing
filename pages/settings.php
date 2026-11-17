<?php
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$fields = ['company', 'address', 'color', 'base_url', 'unsub_text', 'from_name', 'from_email', 'reply_to', 'driver',
    'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'batch', 'hourly_limit',
    'social_web', 'social_linkedin', 'social_x', 'social_facebook', 'social_youtube'];

if ($a === 'save' && $isPost) {
    foreach ($fields as $f) if (isset($_POST[$f])) set_setting($f, trim((string)$_POST[$f]));
    if (isset($_POST['smtp_pass'])) set_setting('smtp_pass', (string)$_POST['smtp_pass']);
    $msg = 'Ajustes guardados.';
    $f = $_FILES['logo'] ?? null;
    if ($f && $f['error'] === UPLOAD_ERR_OK) {
        $info = @getimagesize($f['tmp_name']);
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif'];
        if ($info && isset($types[$info[2]])) {
            $name = 'logo-' . token(3) . '.' . $types[$info[2]];
            if (move_uploaded_file($f['tmp_name'], ROOT . '/uploads/' . $name)) set_setting('logo', $name);
            else $msg = 'Ajustes guardados, pero no se pudo guardar el logo (permisos de la carpeta uploads).';
        } else $msg = 'Ajustes guardados, pero el logo debe ser PNG, JPG o GIF.';
    }
    if (($_POST['newpass'] ?? '') !== '') {
        if (strlen($_POST['newpass']) < 8) $msg .= ' La contraseña no se cambió: mínimo 8 caracteres.';
        else { q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($_POST['newpass'], PASSWORD_DEFAULT), $_SESSION['uid']]); if (isset($_COOKIE[REMEMBER_COOKIE])) remember_set(one('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']])); $msg .= ' Contraseña cambiada.'; }
    }
    flash($msg);
    redirect('index.php?p=settings');
}
if ($a === 'test' && $isPost) {
    [$list, $bad] = test_recipients((string)($_POST['to'] ?? ''));
    $html = '<p>Este es un correo de prueba de Bezares Mailing. Si lo ha recibido, la configuración de envío es correcta.</p>';
    $ok = []; $fail = [];
    $smtp = null;
    foreach ($list as $to) {
        try { deliver($to, ['subject' => 'Prueba de configuración', 'html' => $html, 'text' => html_to_text($html)], $smtp); $ok[] = $to; }
        catch (Throwable $e) { $fail[] = "$to (" . $e->getMessage() . ')'; $smtp = null; }
    }
    if ($smtp) $smtp->close();
    if ($ok) flash('Correo de prueba enviado a ' . implode(', ', $ok) . '.');
    if ($fail) flash('Fallo en el envío a: ' . implode(' · ', $fail), 'err');
    if ($bad) flash('Direcciones no válidas, omitidas: ' . implode(', ', $bad), 'err');
    if (!$list && !$bad) flash('Indique al menos un correo.', 'err');
    $_SESSION['test_to'] = implode(', ', $list);
    redirect('index.php?p=settings');
}

$s = fn($k, $d = '') => e(setting($k, $d));
$sel = fn($k, $v, $d = '') => setting($k, $d) === $v ? 'selected' : '';
$me = (string)val('SELECT email FROM users WHERE id = ?', [$_SESSION['uid']]);
$cron = base_url() . '/cron.php?key=' . setting('cron_key');
layout_start('Ajustes', 'settings'); ?>
<div class="head"><h1>Ajustes</h1></div>
<form method="post" action="index.php?p=settings&a=save" enctype="multipart/form-data">
<?= csrf_field() ?>
<div class="cols">
  <div class="card form">
    <h2>Empresa y diseño</h2>
    <label>Nombre de la empresa<input name="company" value="<?= $s('company') ?>"></label>
    <label>Dirección (aparece en el pie de los correos)<textarea name="address" rows="2"><?= $s('address') ?></textarea></label>
    <div class="row2">
      <label>Color corporativo<input type="color" name="color" value="<?= brand_color() ?>"></label>
      <label>Logo (PNG/JPG)<input type="file" name="logo" accept="image/png,image/jpeg,image/gif"></label>
    </div>
    <?php if (logo_url()): ?><p><img src="<?= e(logo_url()) ?>" alt="Logo actual" style="max-height:60px"></p><?php endif ?>
    <label>Texto de baja (el texto entre [corchetes] será el enlace)<input name="unsub_text" value="<?= $s('unsub_text') ?>"></label>
    <label>Dirección web de esta aplicación<input name="base_url" value="<?= e(base_url()) ?>"></label>
    <h3>Redes sociales (temas Newsletter y Destacado)</h3>
    <div class="row2">
      <label>Web<input name="social_web" value="<?= $s('social_web') ?>"></label>
      <label>LinkedIn<input name="social_linkedin" value="<?= $s('social_linkedin') ?>"></label>
      <label>X / Twitter<input name="social_x" value="<?= $s('social_x') ?>"></label>
      <label>Facebook<input name="social_facebook" value="<?= $s('social_facebook') ?>"></label>
      <label>YouTube<input name="social_youtube" value="<?= $s('social_youtube') ?>"></label>
    </div>
  </div>
  <div class="card form">
    <h2>Envío de correo</h2>
    <div class="row2">
      <label>Nombre del remitente<input name="from_name" value="<?= $s('from_name') ?>"></label>
      <label>Correo del remitente *<input type="email" name="from_email" value="<?= $s('from_email') ?>" placeholder="mailing@sudominio.com"></label>
    </div>
    <label>Responder a (opcional)<input type="email" name="reply_to" value="<?= $s('reply_to') ?>"></label>
    <label>Método
      <select name="driver">
        <option value="smtp" <?= $sel('driver', 'smtp', 'smtp') ?>>SMTP (recomendado)</option>
        <option value="mail" <?= $sel('driver', 'mail') ?>>Función mail() del hosting</option>
      </select></label>
    <div class="row2">
      <label>Servidor SMTP<input name="smtp_host" value="<?= $s('smtp_host') ?>" placeholder="smtp.sudominio.com"></label>
      <label>Puerto<input type="number" name="smtp_port" value="<?= $s('smtp_port', '587') ?>"></label>
      <label>Seguridad
        <select name="smtp_secure">
          <option value="tls" <?= $sel('smtp_secure', 'tls', 'tls') ?>>STARTTLS (puerto 587)</option>
          <option value="ssl" <?= $sel('smtp_secure', 'ssl') ?>>SSL/TLS (puerto 465)</option>
          <option value="" <?= $sel('smtp_secure', '') ?>>Ninguna</option>
        </select></label>
      <label>Usuario<input name="smtp_user" value="<?= $s('smtp_user') ?>" autocomplete="off"></label>
    </div>
    <label>Contraseña SMTP<input type="password" name="smtp_pass" id="smtp_pass" value="<?= $s('smtp_pass') ?>" autocomplete="new-password"></label>
    <label class="check showpass"><input type="checkbox" data-showpass="#smtp_pass"> Mostrar contraseña</label>
    <div class="row2">
      <label>Correos por lote<input type="number" min="1" max="200" name="batch" value="<?= $s('batch', '20') ?>"></label>
      <label>Límite por hora (0 = sin límite)<input type="number" min="0" name="hourly_limit" value="<?= $s('hourly_limit', '0') ?>"></label>
    </div>
    <p class="muted">Muchos hostings compartidos limitan los envíos por hora (por ejemplo 100–500). Indique aquí ese límite para no superarlo; lo que quede en cola se enviará en las horas siguientes.</p>
    <h3>Tarea programada (cron)</h3>
    <p class="muted">Para que la cola se envíe sola aunque cierre el navegador, cree en el panel del hosting una tarea cada 5 minutos con esta dirección:</p>
    <pre class="code">wget -q -O /dev/null "<?= e($cron) ?>"</pre>
    <h3>Cambiar contraseña de acceso</h3>
    <label>Nueva contraseña (<?= e($me) ?>)<input type="password" name="newpass" id="newpass" minlength="8" autocomplete="new-password"></label>
    <label class="check showpass"><input type="checkbox" data-showpass="#newpass"> Mostrar contraseña</label>
  </div>
</div>
<p><button class="btn primary">Guardar ajustes</button></p>
</form>
<form method="post" action="index.php?p=settings&a=test" class="card form inlineform">
  <?= csrf_field() ?>
  <h2>Probar el envío</h2>
  <p class="muted">Guarde los ajustes antes de probar.</p>
  <div class="row2"><label>Enviar un correo de prueba a (uno o varios correos separados por comas)<input type="text" name="to" value="<?= e(($_SESSION['test_to'] ?? '') ?: $me) ?>" placeholder="uno@empresa.com, otro@empresa.com" required></label><div class="end"><button class="btn">Enviar prueba</button></div></div>
</form>
<?php layout_end();
