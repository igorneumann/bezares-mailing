<?php
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = one('SELECT * FROM users WHERE email = ?', [norm_email($_POST['email'] ?? '')]);
    if ($u && password_verify((string)($_POST['pass'] ?? ''), $u['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        if (!empty($_POST['remember'])) remember_set($u); else remember_clear();
        redirect('index.php');
    }
    sleep(1);
    $err = 'Correo o contraseña incorrectos.';
}
$logo = logo_url();
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acceso · Bezares Mailing</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css">
<style>:root{--brand:<?= brand_color() ?>}</style></head>
<body class="center">
<form method="post" class="card narrow">
  <img class="biglogo" src="<?= e($logo ?: 'assets/logo-bezares-mailing.png') ?>" alt="Bezares Mailing">
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif ?>
  <?= csrf_field() ?>
  <label>Correo electrónico<input type="email" name="email" required autofocus></label>
  <label>Contraseña<input type="password" name="pass" id="pass" required></label>
  <label class="check showpass"><input type="checkbox" data-showpass="#pass"> Mostrar contraseña</label>
  <label class="check remember"><input type="checkbox" name="remember" value="1"> Mantener la sesión iniciada en este equipo (30 días)</label>
  <button class="btn primary wide">Entrar</button>
</form>
<script src="assets/app.js"></script>
</body></html>
