<?php
/* Asistente de instalación: se muestra solo mientras no existe data/config.php */
$err = '';
$dataDir = ROOT . '/data';
$in = $_POST + ['email' => '', 'driver' => 'sqlite', 'host' => 'localhost', 'name' => '', 'user' => '', 'dbpass' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $email = norm_email($in['email']);
        $pass = (string)($in['pass'] ?? '');
        if (!valid_email($email)) throw new RuntimeException('El correo del administrador no es válido.');
        if (strlen($pass) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        if (!is_writable($dataDir)) throw new RuntimeException('La carpeta "data" no tiene permisos de escritura.');
        $c = ['secret' => token(24)];
        if ($in['driver'] === 'mysql') {
            $c += ['driver' => 'mysql', 'host' => trim($in['host']), 'name' => trim($in['name']), 'user' => trim($in['user']), 'pass' => (string)$in['dbpass']];
        } else {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('Este servidor no tiene SQLite; elija MySQL.');
            $c += ['driver' => 'sqlite', 'file' => 'bd-' . token(8) . '.sqlite'];
        }
        $pdo = db(db_connect($c));
        foreach (schema_sql($c['driver']) as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) { if (!str_starts_with($sql, 'CREATE INDEX')) throw $e; }
        }
        if (!val('SELECT COUNT(*) FROM users')) {
            q('INSERT INTO users (email, pass_hash) VALUES (?, ?)', [$email, password_hash($pass, PASSWORD_DEFAULT)]);
            require_once ROOT . '/lib/mailer.php';
            require_once ROOT . '/lib/media.php';
            require_once ROOT . '/lib/auto.php';
            require ROOT . '/lib/seed.php';
            seed_defaults($email);
        }
        file_put_contents($dataDir . '/config.php', "<?php\nreturn " . var_export($c, true) . ";\n", LOCK_EX);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($dataDir . '/config.php', true);
        $_SESSION['uid'] = (int)val('SELECT id FROM users WHERE email = ?', [$email]);
        flash('Instalación completada. Configure ahora el servidor de envío.');
        redirect('index.php?p=settings');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalación · Bezares Mailing</title><link rel="icon" href="assets/favicon.png"><link rel="stylesheet" href="assets/app.css"></head>
<body class="center">
<form method="post" class="card narrow">
  <img class="biglogo" src="assets/logo-bezares-mailing.png" alt="Bezares Mailing">
  <h1>Instalación</h1>
  <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif ?>
  <?php if (!is_writable($dataDir)): ?><div class="flash err">Dé permisos de escritura a la carpeta <code>data</code> (y a <code>uploads</code>).</div><?php endif ?>
  <?= csrf_field() ?>
  <h3>Usuario administrador</h3>
  <label>Correo electrónico<input type="email" name="email" value="<?= e($in['email']) ?>" required></label>
  <label>Contraseña (mínimo 8 caracteres)<input type="password" name="pass" id="pass" minlength="8" required></label>
  <label class="check showpass"><input type="checkbox" data-showpass="#pass"> Mostrar contraseña</label>
  <h3>Base de datos</h3>
  <label>Tipo
    <select name="driver" onchange="document.getElementById('my').hidden=this.value!=='mysql'">
      <option value="sqlite" <?= $in['driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite (sin configuración, recomendado)</option>
      <option value="mysql" <?= $in['driver'] === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
    </select></label>
  <div id="my" <?= $in['driver'] === 'mysql' ? '' : 'hidden' ?>>
    <label>Servidor<input name="host" value="<?= e($in['host']) ?>"></label>
    <label>Nombre de la base de datos<input name="name" value="<?= e($in['name']) ?>"></label>
    <label>Usuario<input name="user" value="<?= e($in['user']) ?>"></label>
    <label>Contraseña<input type="password" name="dbpass" id="dbpass"></label>
    <label class="check showpass"><input type="checkbox" data-showpass="#dbpass"> Mostrar contraseña</label>
  </div>
  <button class="btn primary wide">Instalar</button>
</form>
<script src="assets/app.js"></script>
</body></html>
