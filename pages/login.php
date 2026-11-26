<?php
/* Acceso al panel. Límites contra fuerza bruta:
   - por cuenta (la dirección escrita, exista o no): tras 5 fallos en una hora, bloqueo progresivo 1, 2, 4, 8… hasta 60 minutos;
   - por IP: 20 fallos en 15 minutos o 100 en un día bloquean esa IP.
   La contraseña se comprueba siempre con el mismo coste para no revelar qué cuentas existen. */
$err = '';
$ip = client_ip();
$lockMsg = fn(int $s) => 'Demasiados intentos fallidos. Espere ' . max(1, (int)ceil($s / 60)) . ' minuto(s) e inténtelo de nuevo.';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = norm_email((string)($_POST['email'] ?? ''));
    $acct = 'login-acct:' . hash('sha256', $email);
    $ipKey = 'login-ip:' . $ip;
    $fails = throttle_count($acct, 3600);
    $wait = 0;
    if ($fails >= 5) $wait = max(0, throttle_last($acct) + min(3600, 60 * (2 ** min(6, $fails - 5))) - time());
    if (!throttle_ok($ipKey, 20, 900) || !throttle_ok($ipKey, 100, 86400)) $wait = max($wait, 900);
    if ($wait > 0) {
        $err = $lockMsg($wait);
    } else {
        $u = $email !== '' ? one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        // Mismo trabajo exista o no la cuenta
        $ok = password_verify((string)($_POST['pass'] ?? ''), $u['pass_hash'] ?? '$2y$10$ye7XXaUNHN2gv2f57GZNE.xDbMpSdEjyziXmn2dIf4A3mVyECrY8y');
        if ($u && $ok) {
            throttle_clear($acct);
            login_user($u);
            if (!empty($_POST['remember'])) remember_set($u); else remember_clear();
            redirect('index.php');
        }
        throttle_hit($acct);
        throttle_hit($ipKey);
        usleep(random_int(300000, 800000));
        $err = 'Correo o contraseña incorrectos.';
    }
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
