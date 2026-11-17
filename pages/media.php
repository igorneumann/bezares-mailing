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
if ($a === 'delete' && $isPost) {
    $name = basename((string)($_POST['name'] ?? ''));
    $path = media_dir() . '/' . $name;
    if ($name === setting('logo')) flash('Esa imagen es el logo actual: cámbielo antes en Ajustes.', 'err');
    elseif (media_usage($name) && empty($_POST['force'])) flash('El archivo se usa en algún correo. Quítelo de esos correos antes de eliminarlo.', 'err');
    elseif (preg_match('/\.(png|jpe?g|gif|webp|pdf|docx?|xlsx?)$/i', $name) && is_file($path) && @unlink($path)) flash('Archivo eliminado.');
    else flash('No se pudo eliminar el archivo.', 'err');
    redirect('index.php?p=media');
}

$items = media_list();
$external = media_external_urls();
$writable = is_writable(media_dir());
layout_start('Biblioteca', 'media'); ?>
<div class="head"><h1>Biblioteca <small><?= count($items) ?> archivo(s): imágenes y documentos para adjuntar</small></h1></div>
<?php if (!$writable): ?><div class="flash err">La carpeta <code>uploads</code> no tiene permisos de escritura.</div><?php endif ?>

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

<div class="mediagrid">
<?php foreach ($items as $m): $used = media_usage($m['name']); $isLogo = $m['name'] === setting('logo'); ?>
  <div class="card mediaitem">
    <a class="thumb" href="<?= e($m['url']) ?>" target="_blank"><?php if ($m['kind'] === 'image'): ?><img src="<?= e($m['url']) ?>" alt="" loading="lazy"><?php else: ?><span class="docicon <?= e($m['ext']) ?>"><?= e(strtoupper($m['ext'])) ?></span><?php endif ?></a>
    <div class="meta">
      <b title="<?= e($m['name']) ?>"><?= e($m['name']) ?></b>
      <span class="muted"><?= $m['kind'] === 'image' ? $m['w'] . '×' . $m['h'] : 'Documento ' . e($m['label']) ?> · <?= media_size($m['size']) ?></span>
      <span class="muted"><?= $isLogo ? 'Logo actual' : ($used ? 'En uso: ' . e(implode(', ', $used)) : 'Sin usar') ?></span>
    </div>
    <div class="actions">
      <button type="button" class="btn small" data-copy="<?= e($m['url']) ?>">Copiar dirección</button>
      <?php if (!$isLogo && !$used): ?>
      <form method="post" action="index.php?p=media&a=delete" class="inline" onsubmit="return confirm('¿Eliminar este archivo?')"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($m['name']) ?>"><button class="btn small danger">Eliminar</button></form>
      <?php endif ?>
    </div>
  </div>
<?php endforeach ?>
</div>
<?php if (!$items): ?><div class="card"><p class="empty">La biblioteca está vacía. Suba imágenes para usarlas en los correos, o documentos para adjuntarlos.</p></div><?php endif ?>
<?php layout_end();
