<?php
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$fields = ['company', 'address', 'color', 'unsub_text', 'from_name', 'from_email', 'reply_to', 'driver',
    'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'batch', 'hourly_limit',
    'social_web', 'social_linkedin', 'social_x', 'social_facebook', 'social_youtube'];

if ($a === 'save' && $isPost) {
    foreach ($fields as $f) if (isset($_POST[$f])) set_setting($f, trim((string)$_POST[$f]));
    if (isset($_POST['smtp_pass'])) set_setting('smtp_pass', (string)$_POST['smtp_pass']);
    $msg = 'Ajustes guardados.';
    // Dirección de la aplicación: base de todos los enlaces y de las cookies. Se valida antes de guardarla.
    if (isset($_POST['base_url'])) {
        $base = rtrim(trim((string)$_POST['base_url']), '/');
        $bp = parse_url($base);
        if (filter_var($base, FILTER_VALIDATE_URL) && in_array(strtolower($bp['scheme'] ?? ''), ['http', 'https'], true) && !isset($bp['query']) && !isset($bp['fragment']) && !isset($bp['user'])) {
            set_setting('base_url', $base);
        } else $msg .= ' La dirección de la aplicación no se cambió porque no es válida.';
    }
    if (isset(ADMIN_TABS[$_POST['default_tab'] ?? ''])) set_setting('default_tab', (string)$_POST['default_tab']);
    if (isset($_POST['contacts_per_page']) && in_array((int)$_POST['contacts_per_page'], PER_PAGE_OPTIONS, true)) set_setting('contacts_per_page', (string)(int)$_POST['contacts_per_page']);
    $https = str_starts_with((string)setting('base_url'), 'https://');
    set_setting('force_https', $https && !empty($_POST['force_https']) ? '1' : '0');
    // URLs de confianza que pueden incrustar la aplicación (Nextcloud, intranet…)
    if (isset($_POST['frame_origins'])) {
        $ok = []; $bad = [];
        foreach (preg_split('/[\r\n,]+/', (string)$_POST['frame_origins'], -1, PREG_SPLIT_NO_EMPTY) as $o) {
            if (trim($o) === '') continue;
            $n = normalize_origin($o);
            if ($n !== '') $ok[$n] = true; else $bad[] = $o;
        }
        set_setting('frame_origins', implode("\n", array_keys($ok)));
        if ($bad) $msg .= ' Se ignoraron estas URLs de confianza no válidas (una por línea, por ejemplo https://cloud.bezares.com): ' . implode(', ', $bad) . '.';
        if ($ok && cross_site_embedding() && !$https) $msg .= ' Para incrustar desde otro dominio la aplicación tiene que usar https://.';
    }
    if (!empty($_POST['force_https']) && !$https) $msg .= ' «Forzar HTTPS» requiere que la dirección empiece por https://.';
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
if ($a === 'selfcheck' && $isPost) {
    $problems = exposure_check();
    set_setting('exposure', json_encode(['at' => now(), 'problems' => $problems], JSON_UNESCAPED_UNICODE));
    flash($problems ? 'Se han encontrado problemas de seguridad en el servidor: revise «Seguridad del servidor».' : 'Comprobación superada: las carpetas internas no son accesibles desde Internet.', $problems ? 'err' : 'ok');
    redirect('index.php?p=settings#seguridad');
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
    <label>Dirección web de esta aplicación<input type="url" name="base_url" value="<?= e(base_url()) ?>" required></label>
    <label class="check"><input type="checkbox" name="force_https" <?= setting('force_https') === '1' ? 'checked' : '' ?> <?= str_starts_with(base_url(), 'https://') ? '' : 'disabled' ?>> Forzar HTTPS (redirige http → https y activa HSTS)</label>
    <label>URLs de confianza que pueden incrustar la aplicación (una por línea)
      <textarea name="frame_origins" rows="2" placeholder="https://cloud.bezares.com"><?= e(implode("\n", frame_origins())) ?></textarea></label>
    <p class="muted" style="margin-top:-.5rem">Por ejemplo, la dirección de su Nextcloud para mostrar la aplicación con la app «External sites». Puede pegar la dirección completa: se guarda solo el dominio. Admite <code>https://*.bezares.com</code>. Las demás webs no pueden incrustarla.<?= frame_origins() && cookie_samesite() === 'None' ? ' Como están en otro dominio, las cookies de sesión se envían en modo «SameSite=None; Partitioned» para que el inicio de sesión funcione dentro del marco.' : '' ?></p>
    <?php if (!str_starts_with(base_url(), 'https://')): ?><p class="muted" style="color:#a12626">La dirección usa http: las contraseñas y las cookies viajan sin cifrar. Active un certificado en el hosting y cambie la dirección a https://.</p><?php endif ?>
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
    <h3>Panel</h3>
    <div class="row2">
      <label>Pestaña que se abre al entrar
        <select name="default_tab"><?php foreach (ADMIN_TABS as $k => $l): ?><option value="<?= $k ?>" <?= default_tab() === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></label>
      <label>Contactos visibles a la vez en el listado
        <select name="contacts_per_page"><?php $cur = (int)setting('contacts_per_page', '50'); foreach (PER_PAGE_OPTIONS as $n): ?><option value="<?= $n ?>" <?= $cur === $n ? 'selected' : '' ?>><?= per_page_label($n) ?></option><?php endforeach ?></select></label>
    </div>
    <p class="muted">«Todos» muestra el listado completo en una sola página; con muchos miles de contactos puede tardar en cargar.</p>
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

<?php
$exp = json_decode((string)setting('exposure'), true) ?: null;
$dataOutside = !str_starts_with((realpath(data_dir()) ?: data_dir()) . DIRECTORY_SEPARATOR, (realpath(ROOT) ?: ROOT) . DIRECTORY_SEPARATOR);
?>
<form method="post" action="index.php?p=settings&a=selfcheck" class="card form" id="seguridad">
  <?= csrf_field() ?>
  <h2>Seguridad del servidor</h2>
  <ul class="secstat">
    <li class="<?= $dataOutside ? 'ok' : 'warn' ?>">Carpeta de datos: <code><?= e(data_dir()) ?></code> <?= $dataOutside ? '(fuera de la carpeta pública)' : '(dentro de la carpeta pública: depende de que el servidor la bloquee)' ?></li>
    <li class="<?= str_starts_with(base_url(), 'https://') ? 'ok' : 'bad' ?>">Dirección: <code><?= e(base_url()) ?></code><?= setting('force_https') === '1' ? ' · HTTPS forzado' : '' ?></li>
    <li class="<?= is_https() ? 'ok' : 'warn' ?>">Esta petición ha llegado por <?= is_https() ? 'HTTPS' : 'HTTP' ?><?= trusted_proxies() ? ' · proxies de confianza: ' . e(implode(', ', trusted_proxies())) : '' ?></li>
    <?php if ($exp): ?>
      <li class="<?= $exp['problems'] ? 'bad' : 'ok' ?>">Última comprobación desde Internet (<?= fecha($exp['at']) ?>): <?= $exp['problems'] ? 'se encontraron problemas' : 'carpetas internas protegidas' ?>
        <?php if ($exp['problems']): ?><ul><?php foreach ($exp['problems'] as $pr): ?><li><?= e($pr) ?></li><?php endforeach ?></ul><?php endif ?></li>
    <?php else: ?><li class="warn">Todavía no se ha comprobado si las carpetas internas son accesibles desde Internet.</li><?php endif ?>
  </ul>
  <p class="muted">La comprobación pide por HTTP, a la dirección de la aplicación, un archivo de prueba creado en la carpeta de datos y una plantilla interna. En Nginx hay que añadir las reglas de <code>nginx.conf.example</code>. Detrás de un proxy inverso, indique su IP en <code>data/config.php</code> (<code>'trusted_proxies' => ['IP']</code>) para que se reconozcan el HTTPS y la IP real de los visitantes.</p>
  <button class="btn">Comprobar ahora</button>
</form>
<?php layout_end();
