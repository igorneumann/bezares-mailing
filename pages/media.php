<?php
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

/* Lista en JSON para el selector del editor */
if ($a === 'json') {
    header('Content-Type: application/json');
    echo json_encode(media_list(in_array($_GET['kind'] ?? '', ['image', 'file'], true) ? $_GET['kind'] : ''));
    return;
}

if ($a === 'upload' && $isPost) {
    $ok = 0; $errs = [];
    $files = $_FILES['images'] ?? null;
    foreach ((array)($files['name'] ?? []) as $i => $n) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        try {
            media_store_upload(['name' => $n, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i]]);
            $ok++;
        } catch (Throwable $e) { $errs[] = "$n: " . $e->getMessage(); }
    }
    if ($ok) flash("$ok archivo(s) añadido(s) a la biblioteca.");
    if ($errs) flash(implode(' · ', $errs), 'err');
    if (!$ok && !$errs) flash('No se ha seleccionado ningún archivo.', 'err');
    redirect('index.php?p=media');
}
if ($a === 'fetch' && $isPost) {
    try {
        $name = media_fetch_url(trim((string)($_POST['url'] ?? '')));
        flash("Imagen copiada a la biblioteca como $name.");
    } catch (Throwable $e) { flash('No se pudo importar: ' . $e->getMessage(), 'err'); }
    redirect('index.php?p=media');
}
if ($a === 'localize' && $isPost) {
    $urls = array_intersect(array_map('strval', (array)($_POST['urls'] ?? [])), array_keys(media_external_urls()));
    $r = media_localize($urls);
    if ($r['ok']) flash("{$r['ok']} imagen(es) copiada(s) a la biblioteca; los correos ya usan la copia propia.");
    if ($r['errors']) flash('No se pudieron copiar: ' . implode(' · ', $r['errors']), 'err');
    redirect('index.php?p=media');
}
/* Imágenes que ya están en la biblioteca pero los correos las piden a la dirección antigua (cambio de dominio):
   solo se cambia la dirección, sin copiar nada. */
if ($a === 'rebase' && $isPost) {
    $ok = 0; $mails = 0;
    foreach (array_intersect(array_map('strval', (array)($_POST['urls'] ?? [])), array_keys(media_external_urls())) as $u) {
        $name = media_relocatable($u);
        if ($name === '') continue;
        $mails += media_replace_url($u, media_url($name));
        $ok++;
    }
    flash($ok ? "Dirección actualizada en $ok imagen(es) ($mails cambio(s) en correos): ahora se cargan desde " . base_url() . '.' : 'No había nada que actualizar.');
    redirect('index.php?p=media');
}
/* Marcar o desmarcar una imagen como cabecera de las newsletters */
if ($a === 'header' && $isPost) {
    $name = basename((string)($_POST['name'] ?? ''));
    $on = !empty($_POST['on']);
    if (!in_array($name, array_column(media_list('image'), 'name'), true)) flash('Esa imagen no está en la biblioteca.', 'err');
    elseif ($name === NEWSLETTER_HEADER) flash('Es la cabecera por defecto: siempre aparece en el generador.', 'err');
    else {
        header_set($name, $on);
        flash($on ? "«{$name}» aparecerá como cabecera en el generador de newsletter." : "«{$name}» ya no aparecerá como cabecera.");
    }
    redirect('index.php?p=media' . (($_POST['f'] ?? '') === 'headers' ? '&f=headers' : ''));
}
if ($a === 'delete' && $isPost) {
    $name = basename((string)($_POST['name'] ?? ''));
    $path = media_dir() . '/' . $name;
    if ($name === setting('logo')) flash('Esa imagen es el logo actual: cámbielo antes en Ajustes.', 'err');
    elseif (media_usage($name) && empty($_POST['force'])) flash('El archivo se usa en algún correo. Quítelo de esos correos antes de eliminarlo.', 'err');
    elseif (preg_match('/\.(png|jpe?g|gif|webp|pdf|docx?|xlsx?)$/i', $name) && is_file($path) && @unlink($path)) {
        if (in_array($name, header_names(), true)) header_set($name, false);
        flash('Archivo eliminado.');
    }
    else flash('No se pudo eliminar el archivo.', 'err');
    redirect('index.php?p=media');
}

$all = media_list();
$onlyHeaders = ($_GET['f'] ?? '') === 'headers';
$nHeaders = count(array_filter($all, fn($m) => $m['kind'] === 'image' && is_header_image($m['name'])));
$items = $onlyHeaders ? array_values(array_filter($all, fn($m) => $m['kind'] === 'image' && is_header_image($m['name']))) : $all;
$external = media_external_urls();
// Separar las que ya están en la biblioteca (solo hay que cambiar la dirección) de las de otras webs
$relocate = [];
foreach ($external as $u => $names) if (media_relocatable($u) !== '') { $relocate[$u] = $names; unset($external[$u]); }
$writable = is_writable(media_dir());
layout_start('Biblioteca', 'media'); ?>
<div class="head"><h1>Biblioteca <small><?= count($all) ?> archivo(s): imágenes y documentos para adjuntar</small></h1></div>
<?php if (!$writable): ?><div class="flash err">La carpeta <code>uploads</code> no tiene permisos de escritura.</div><?php endif ?>

<?php if ($relocate): $oldHosts = array_unique(array_map(fn($u) => (string)parse_url($u, PHP_URL_HOST), array_keys($relocate))); ?>
<form method="post" action="index.php?p=media&a=rebase" class="card warnbox">
  <?= csrf_field() ?>
  <h2>Imágenes con la dirección antigua</h2>
  <p class="muted">Estas imágenes ya están en su biblioteca, pero los correos las cargan desde <b><?= e(implode(', ', $oldHosts)) ?></b>. Actualice la dirección para que se carguen desde <b><?= e((string)parse_url(base_url(), PHP_URL_HOST)) ?></b>; no se copia ningún archivo.</p>
  <?php foreach ($relocate as $u => $names): ?>
    <label class="check ext"><input type="checkbox" name="urls[]" value="<?= e($u) ?>" checked>
      <span><b><?= e((string)parse_url($u, PHP_URL_HOST)) ?></b><?= e((string)parse_url($u, PHP_URL_PATH)) ?> → <?= e(media_relocatable($u)) ?> <span class="muted">— <?= e(implode(', ', array_unique($names))) ?></span></span></label>
  <?php endforeach ?>
  <p><button class="btn primary">Actualizar la dirección</button></p>
</form>
<?php endif ?>

<?php if ($external): ?>
<form method="post" action="index.php?p=media&a=localize" class="card warnbox">
  <?= csrf_field() ?>
  <h2>Imágenes alojadas en otros servidores</h2>
  <p class="muted">Estos correos cargan imágenes desde otras webs. Si esa web desaparece, la imagen dejará de verse. Marque las que quiera copiar a su biblioteca: los correos se actualizarán solos.</p>
  <?php foreach ($external as $u => $names): $host = (string)parse_url($u, PHP_URL_HOST); ?>
    <label class="check ext"><input type="checkbox" name="urls[]" value="<?= e($u) ?>" <?= str_contains($host, 'mailing.bezares.com') ? 'checked' : '' ?>>
      <span><b><?= e($host) ?></b><?= e((string)parse_url($u, PHP_URL_PATH)) ?> <span class="muted">— <?= e(implode(', ', array_unique($names))) ?></span></span></label>
  <?php endforeach ?>
  <p><button class="btn primary">Copiar las marcadas a mi biblioteca</button>
     <button type="button" class="btn" onclick="this.form.querySelectorAll('[name=\'urls[]\']').forEach(c=>c.checked=true)">Marcar todas</button></p>
</form>
<?php endif ?>

<div class="cols">
  <form method="post" action="index.php?p=media&a=upload" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?>
    <h2>Subir desde el equipo</h2>
    <label>Imágenes (PNG, JPG, GIF, WebP; máx. 8 MB) o documentos PDF, Word y Excel (máx. 10 MB)<input type="file" name="images[]" accept="image/png,image/jpeg,image/gif,image/webp,.pdf,.doc,.docx,.xls,.xlsx" multiple required></label>
    <button class="btn primary">Subir</button>
  </form>
  <form method="post" action="index.php?p=media&a=fetch" class="card form">
    <?= csrf_field() ?>
    <h2>Copiar una imagen desde una dirección web</h2>
    <label>Dirección (URL) de la imagen<input type="url" name="url" placeholder="https://…/imagen.png" required></label>
    <button class="btn">Copiar a la biblioteca</button>
  </form>
</div>

<div class="mediabar">
  <div class="seg"><a class="<?= $onlyHeaders ? '' : 'on' ?>" href="index.php?p=media">Todos <i><?= count($all) ?></i></a><a class="<?= $onlyHeaders ? 'on' : '' ?>" href="index.php?p=media&f=headers">Cabeceras <i><?= $nHeaders ?></i></a></div>
  <span class="muted">Las imágenes marcadas como <b>cabecera</b> son las que se ofrecen en el generador de newsletter.</span>
</div>
<div class="mediagrid">
<?php foreach ($items as $m): $used = media_usage($m['name']); $isLogo = $m['name'] === setting('logo'); $isHeader = $m['kind'] === 'image' && is_header_image($m['name']); ?>
  <div class="card mediaitem<?= $isHeader ? ' isheader' : '' ?>">
    <a class="thumb" href="<?= e($m['url']) ?>" target="_blank"><?php if ($m['kind'] === 'image'): ?><img src="<?= e($m['url']) ?>" alt="" loading="lazy"><?php else: ?><span class="docicon <?= e($m['ext']) ?>"><?= e(strtoupper($m['ext'])) ?></span><?php endif ?></a>
    <div class="meta">
      <b title="<?= e($m['name']) ?>"><?= e($m['name']) ?></b>
      <?php if ($isHeader): ?><span><span class="pill header"><?= $m['name'] === NEWSLETTER_HEADER ? 'Cabecera por defecto' : 'Cabecera' ?></span></span><?php endif ?>
      <span class="muted"><?= $m['kind'] === 'image' ? $m['w'] . '×' . $m['h'] : 'Documento ' . e($m['label']) ?> · <?= media_size($m['size']) ?></span>
      <span class="muted"><?= $isLogo ? 'Logo actual' : ($used ? 'En uso: ' . e(implode(', ', $used)) : 'Sin usar') ?></span>
    </div>
    <div class="actions">
      <button type="button" class="btn small" data-copy="<?= e($m['url']) ?>">Copiar dirección</button>
      <?php if ($m['kind'] === 'image' && !$isLogo && $m['name'] !== NEWSLETTER_HEADER): ?>
      <form method="post" action="index.php?p=media&a=header" class="inline"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($m['name']) ?>"><input type="hidden" name="on" value="<?= $isHeader ? '' : '1' ?>"><input type="hidden" name="f" value="<?= $onlyHeaders ? 'headers' : '' ?>"><button class="btn small"><?= $isHeader ? 'Quitar de cabeceras' : 'Usar como cabecera' ?></button></form>
      <?php endif ?>
      <?php if (!$isLogo && !$used): ?>
      <form method="post" action="index.php?p=media&a=delete" class="inline" onsubmit="return confirm('¿Eliminar este archivo?')"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($m['name']) ?>"><button class="btn small danger">Eliminar</button></form>
      <?php endif ?>
    </div>
  </div>
<?php endforeach ?>
</div>
<?php if (!$items && $onlyHeaders): ?><div class="card"><p class="empty">Todavía no hay imágenes marcadas como cabecera. En «Todos», pulse «Usar como cabecera» en las que quiera ofrecer en el generador de newsletter.</p></div>
<?php elseif (!$items): ?><div class="card"><p class="empty">La biblioteca está vacía. Suba imágenes para usarlas en los correos, o documentos para adjuntarlos.</p></div><?php endif ?>
<?php layout_end();
