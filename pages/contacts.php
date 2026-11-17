<?php
$STATUS = ['subscribed' => 'Suscrito', 'unsubscribed' => 'Baja', 'bounced' => 'Rebotado', 'pending' => 'Pendiente de confirmar'];

function contact_where(): array
{
    $w = ['1=1']; $p = [];
    $s = trim((string)($_GET['q'] ?? ''));
    if ($s !== '') {
        $w[] = '(email LIKE ? OR nombre LIKE ? OR apellidos LIKE ? OR empresa LIKE ?)';
        array_push($p, "%$s%", "%$s%", "%$s%", "%$s%");
    }
    if (($t = trim((string)($_GET['tag'] ?? ''))) !== '') { $w[] = 'tags LIKE ?'; $p[] = '%,' . mb_strtolower($t) . ',%'; }
    if (($st = (string)($_GET['status'] ?? '')) !== '') { $w[] = 'status = ?'; $p[] = $st; }
    return [implode(' AND ', $w), $p];
}
function norm_header(string $h): string
{
    $h = mb_strtolower(trim($h));
    $h = strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    return preg_replace('/[^a-z]/', '', $h);
}

/* ---------- Exportar ---------- */
if ($a === 'export') {
    [$w, $p] = contact_where();
    $rows = [['email', 'nombre', 'apellidos', 'empresa', 'etiquetas', 'estado', 'alta']];
    foreach (all("SELECT * FROM contacts WHERE $w ORDER BY email", $p) as $c)
        $rows[] = [$c['email'], $c['nombre'], $c['apellidos'], $c['empresa'], implode(', ', tags_list($c['tags'])), $STATUS[$c['status']] ?? $c['status'], $c['created_at']];
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtr(mb_strtolower((string)($_GET['tag'] ?? '')), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'])), '-');
    download_table($rows, 'contactos-' . ($slug !== '' ? $slug . '-' : '') . date('Ymd'), ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv');
}

/* ---------- Importar (2 pasos: subir archivo → revisar columnas → importar) ---------- */
const IMPORT_FIELDS = ['' => '— No importar —', 'email' => 'Correo electrónico', 'nombre' => 'Nombre', 'apellidos' => 'Apellidos',
    'empresa' => 'Empresa', 'tags' => 'Etiquetas', 'dnc' => 'No enviar correos (baja)', 'status' => 'Estado'];

/** Reconoce cabeceras propias, de Mautic (alias y etiquetas en inglés/español) y de Excel habituales. */
function detect_field(string $header): string
{
    $n = norm_header($header);
    $alias = [
        'email' => ['email', 'correo', 'mail', 'correoelectronico', 'emailaddress', 'direcciondecorreo', 'correoe'],
        'nombre' => ['nombre', 'name', 'firstname', 'nombrepila', 'nombredepila', 'givenname'],
        'apellidos' => ['apellidos', 'apellido', 'lastname', 'surname', 'familyname'],
        'empresa' => ['empresa', 'company', 'companyname', 'primarycompany', 'compania', 'organizacion', 'organization', 'empresaprincipal'],
        'tags' => ['etiquetas', 'etiqueta', 'tags', 'tag', 'segmento', 'segmentos', 'segments'],
        'dnc' => ['donotsendemails', 'nonotsendemails', 'donotsendemail', 'donotcontact', 'dnc', 'donotemail', 'donotcontactemail', 'emaildonotcontact',
            'dncemail', 'emaildnc', 'unsubscribed', 'unsubscribe', 'optout', 'optedout', 'emailoptout', 'noenviarcorreos', 'noenviaremails',
            'noenviarcorreo', 'nocontactar', 'baja', 'bajas', 'dncreason', 'donotcontactreason', 'bounced'],
        'status' => ['estado', 'status'],
    ];
    foreach ($alias as $field => $names) if (in_array($n, $names, true)) return $field;
    if (str_contains($n, 'donotcontact') || str_contains($n, 'donotsend') || str_contains($n, 'noenviar')) return 'dnc';
    return '';
}
/** Interpreta el valor de "no enviar correos": '' (se puede enviar), 'unsubscribed' o 'bounced'. */
function parse_dnc(string $v): string
{
    $v = mb_strtolower(trim($v));
    if (in_array($v, ['', '0', 'false', 'no', 'n', 'none', 'null', '-', 'contactable', 'is contactable', 'iscontactable', 'falso'], true)) return '';
    if ($v === '2' || str_contains($v, 'bounc') || str_contains($v, 'rebot')) return 'bounced';
    return 'unsubscribed'; // 1, 3, true, yes, sí, x, unsubscribed, manual, email…
}
function import_file(): ?string
{
    $f = $_SESSION['import']['file'] ?? '';
    $path = data_dir() . '/' . basename($f);
    return $f !== '' && preg_match('/^import-[a-f0-9]+\.(csv|xlsx)$/', $f) && is_file($path) ? $path : null;
}
/** Primeras $n filas (para la vista previa), sin leer el resto del archivo. */
function import_head(string $path, int $n): array
{
    $out = [];
    foreach (table_rows($path) as $r) { $out[] = $r; if (count($out) >= $n) break; }
    return $out;
}
function import_clear(): void
{
    if ($p = import_file()) @unlink($p);
    unset($_SESSION['import']);
}

if ($a === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No se ha recibido el archivo (puede que supere el tamaño máximo que admite el servidor).');
        if ($f['size'] > IMPORT_MAX_BYTES) throw new RuntimeException('El archivo supera los 20 MB. Divídalo en varios archivos.');
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext === 'xls') throw new RuntimeException('El formato .xls antiguo no está soportado: guarde el archivo como .xlsx o .csv.');
        $ext = $ext === 'xlsx' ? 'xlsx' : 'csv';
        import_clear();
        foreach (glob(data_dir() . '/import-*') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
        $name = 'import-' . token(8) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], data_dir() . '/' . $name)) throw new RuntimeException('No se pudo guardar el archivo (permisos de la carpeta de datos).');
        $_SESSION['import'] = ['file' => $name, 'name' => (string)$f['name']];
        // Recorrido completo (fila a fila, sin guardar nada) para validar el archivo y contar filas antes de seguir
        @set_time_limit(120);
        $n = 0;
        foreach (table_rows(data_dir() . '/' . $name) as $_) $n++;
        if (!$n) throw new RuntimeException('El archivo está vacío.');
        $_SESSION['import']['rows'] = $n;
        $_SESSION['import']['tag'] = trim(tags_pack((string)($_POST['preset_tag'] ?? '')), ',');
        redirect('index.php?p=contacts&a=map');
    } catch (Throwable $e) {
        import_clear();
        flash($e->getMessage(), 'err');
        redirect(($_POST['from'] ?? '') === 'tags' ? 'index.php?p=tags' : 'index.php?p=contacts');
    }
}
if ($a === 'cancelimport') { import_clear(); redirect('index.php?p=contacts'); }

if ($a === 'map') {
    $path = import_file();
    if (!$path) redirect('index.php?p=contacts');
    $rows = import_head($path, 201);
    $total = (int)($_SESSION['import']['rows'] ?? count($rows));
    $cols = max(array_map('count', array_slice($rows, 0, 50)));
    $map = [];
    $used = [];
    for ($i = 0; $i < $cols; $i++) {
        $fld = detect_field((string)($rows[0][$i] ?? ''));
        if ($fld !== '' && $fld !== 'tags' && isset($used[$fld])) $fld = '';
        $used[$fld] = true;
        $map[$i] = $fld;
    }
    $header = in_array('email', $map, true);
    if (!$header) { // sin fila de cabeceras: la columna con @ es el correo
        $map = array_fill(0, $cols, '');
        foreach ($rows[0] as $i => $v) if (str_contains($v, '@')) { $map[$i] = 'email'; break; }
    }
    $data = $header ? array_slice($rows, 1) : $rows;
    $dataTotal = $header ? $total - 1 : $total;
    $hasDnc = in_array('dnc', $map, true);
    $dncCount = 0;
    if ($hasDnc) {
        // Recuento de bajas sobre todo el archivo, fila a fila
        $di = array_search('dnc', $map, true);
        $first = true;
        foreach (table_rows($path) as $r) { if ($first && $header) { $first = false; continue; } $first = false; if (parse_dnc((string)($r[$di] ?? '')) !== '') $dncCount++; }
    }
    layout_start('Importar contactos', 'contacts'); ?>
    <form method="post" action="index.php?p=contacts&a=doimport">
    <?= csrf_field() ?><input type="hidden" name="header" value="<?= $header ? 1 : 0 ?>">
    <div class="head"><h1>Importar contactos <small><?= e($_SESSION['import']['name']) ?> · <?= $dataTotal ?> filas</small></h1>
      <div class="actions"><button class="btn primary">Importar <?= $dataTotal ?> filas</button><a class="btn" href="index.php?p=contacts&a=cancelimport">Cancelar</a></div></div>
    <?php if (!in_array('email', $map, true)): ?><div class="flash err">No se ha reconocido la columna del correo electrónico: indíquela en la tabla.</div><?php endif ?>
    <?php if ($hasDnc): ?><div class="flash">Se ha detectado la columna de «no enviar correos»: <b><?= $dncCount ?></b> contacto(s) se importarán como <b>baja</b> y no recibirán ningún envío.</div>
    <?php else: ?><div class="flash warn">No se ha detectado ninguna columna de «no enviar correos / Do Not Contact». Si el archivo la tiene, asígnele «No enviar correos (baja)» en la tabla.</div><?php endif ?>
    <p class="muted">Compruebe qué se importa de cada columna. Las columnas de Mautic (<code>email</code>, <code>firstname</code>, <code>lastname</code>, <code>company</code>, <code>tags</code>…) se reconocen solas; el resto se ignora.</p>
    <div class="card table"><table>
      <thead><tr><th>Columna del archivo</th><th>Ejemplos</th><th>Se importa como</th></tr></thead>
      <tbody>
      <?php for ($i = 0; $i < $cols; $i++):
          $samples = [];
          foreach ($data as $r) { $v = trim((string)($r[$i] ?? '')); if ($v !== '' && !in_array($v, $samples, true)) $samples[] = mb_strimwidth($v, 0, 40, '…'); if (count($samples) >= 3) break; } ?>
        <tr class="<?= $map[$i] === '' ? 'dim' : '' ?>">
          <td><b><?= $header ? e($rows[0][$i] ?? '') : 'Columna ' . ($i + 1) ?></b></td>
          <td class="muted"><?= e(implode(' · ', $samples)) ?></td>
          <td><select name="map[<?= $i ?>]" onchange="this.closest('tr').className=this.value?'':'dim'"><?php foreach (IMPORT_FIELDS as $k => $l): ?><option value="<?= $k ?>" <?= $map[$i] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></td>
        </tr>
      <?php endfor ?>
      </tbody>
    </table></div>
    <div class="card form">
      <label>Añadir estas etiquetas a todos los importados<input name="tags" placeholder="mautic, clientes" value="<?= e(implode(', ', tags_list(',' . ($_SESSION['import']['tag'] ?? '') . ','))) ?>"></label>
      <label class="check"><input type="checkbox" name="update" checked> Actualizar nombre, empresa y etiquetas de los contactos que ya existan</label>
      <p class="muted">Una baja nunca se reactiva al importar. Y si el archivo marca «no enviar correos» a un contacto que ya existe, se le da de baja también aquí.</p>
    </div>
    </form>
    <?php layout_end(); return;
}

if ($a === 'doimport' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $path = import_file();
        if (!$path) throw new RuntimeException('El archivo de importación ha caducado: súbalo de nuevo.');
        @set_time_limit(0);
        $skipHeader = !empty($_POST['header']);
        $map = ['tags' => []];
        foreach ((array)($_POST['map'] ?? []) as $i => $fld) {
            if (!isset(IMPORT_FIELDS[$fld]) || $fld === '') continue;
            if ($fld === 'tags') $map['tags'][] = (int)$i; else $map[$fld] = (int)$i;
        }
        if (!isset($map['email'])) throw new RuntimeException('Indique qué columna contiene el correo electrónico.');
        $extra = tags_list(tags_pack($_POST['tags'] ?? ''));
        $update = !empty($_POST['update']);
        $statusIn = array_change_key_case(array_flip($STATUS)) + ['subscribed' => 'subscribed', 'unsubscribed' => 'unsubscribed', 'bounced' => 'bounced'];
        $n = ['new' => 0, 'upd' => 0, 'bad' => 0, 'same' => 0, 'dnc' => 0, 'dup' => 0];
        $get = fn($r, $k) => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';
        $seen = [];
        db()->beginTransaction();
        foreach (table_rows($path) as $r) {   // fila a fila: memoria constante aunque el archivo sea grande
            if ($skipHeader) { $skipHeader = false; continue; }
            $email = norm_email($get($r, 'email'));
            if ($email === '') continue;
            if (!valid_email($email)) { $n['bad']++; continue; }
            if (isset($seen[$email])) { $n['dup']++; continue; }
            $seen[$email] = true;
            $tags = $extra;
            foreach ($map['tags'] as $ti) $tags = array_merge($tags, tags_list(tags_pack((string)($r[$ti] ?? ''))));
            $dnc = parse_dnc($get($r, 'dnc'));
            $ex = one('SELECT * FROM contacts WHERE email = ?', [$email]);
            if (!$ex) {
                $st = $dnc ?: ($statusIn[mb_strtolower($get($r, 'status'))] ?? 'subscribed');
                q('INSERT INTO contacts (email, nombre, apellidos, empresa, tags, status, token, created_at, unsub_at) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$email, $get($r, 'nombre'), $get($r, 'apellidos'), $get($r, 'empresa'), tags_pack($tags), $st, token(), now(), $st === 'subscribed' ? null : now()]);
                $n['new']++;
                if ($st !== 'subscribed') $n['dnc']++;
                continue;
            }
            if ($dnc && $ex['status'] === 'subscribed') {
                q('UPDATE contacts SET status = ?, unsub_at = ? WHERE id = ?', [$dnc, now(), $ex['id']]);
                q('DELETE FROM confirmations WHERE contact_id = ?', [$ex['id']]);
                q("UPDATE queue SET status = 'skipped', error = 'Baja' WHERE contact_id = ? AND status = 'pending'", [$ex['id']]);
                $n['dnc']++;
            }
            if ($update) {
                q('UPDATE contacts SET nombre = ?, apellidos = ?, empresa = ?, tags = ? WHERE id = ?', [
                    $get($r, 'nombre') ?: $ex['nombre'], $get($r, 'apellidos') ?: $ex['apellidos'], $get($r, 'empresa') ?: $ex['empresa'],
                    tags_pack(array_merge(tags_list($ex['tags']), $tags)), $ex['id']]);
                $n['upd']++;
            } else $n['same']++;
        }
        db()->commit();
        $preset = tags_list(',' . ($_SESSION['import']['tag'] ?? '') . ',');
        import_clear();
        flash("Importación terminada: {$n['new']} nuevos, {$n['upd']} actualizados, {$n['same']} ya existentes sin cambios, {$n['dnc']} marcados como baja (no enviar correos), "
            . "{$n['dup']} repetidos en el archivo, {$n['bad']} con correo no válido.");
        // Si se importó desde una etiqueta, se muestran los contactos de esa etiqueta
        redirect('index.php?p=contacts' . ($preset ? '&tag=' . rawurlencode($preset[0]) : ''));
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash($e->getMessage(), 'err');
        redirect('index.php?p=contacts' . (import_file() ? '&a=map' : ''));
    }
}

/* ---------- Guardar / borrar ---------- */
if ($a === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $email = norm_email($_POST['email'] ?? '');
    $st = isset($STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'subscribed';
    $dup = one('SELECT id FROM contacts WHERE email = ? AND id <> ?', [$email, $id]);
    if (!valid_email($email)) flash('El correo electrónico no es válido.', 'err');
    elseif ($dup) flash('Ya existe un contacto con ese correo.', 'err');
    else {
        $d = [$email, trim($_POST['nombre'] ?? ''), trim($_POST['apellidos'] ?? ''), trim($_POST['empresa'] ?? ''), tags_pack($_POST['tags'] ?? ''), $st];
        if ($id) {
            $prev = (string)val('SELECT status FROM contacts WHERE id = ?', [$id]);
            q('UPDATE contacts SET email=?, nombre=?, apellidos=?, empresa=?, tags=?, status=? WHERE id=?', [...$d, $id]);
            if ($st !== 'subscribed' && $st !== 'pending' && $prev !== $st) {
                // Baja o rebote marcados a mano: se anotan y se anulan los enlaces de confirmación pendientes
                q('UPDATE contacts SET unsub_at = ? WHERE id = ?', [now(), $id]);
                q('DELETE FROM confirmations WHERE contact_id = ?', [$id]);
            }
        }
        else q('INSERT INTO contacts (email, nombre, apellidos, empresa, tags, status, token, created_at) VALUES (?,?,?,?,?,?,?,?)', [...$d, token(), now()]);
        flash('Contacto guardado.');
        redirect('index.php?p=contacts');
    }
    redirect('index.php?p=contacts&a=edit&id=' . $id);
}
/** Contactos a los que se aplica una acción masiva: los marcados o, si se pide, todos los del filtro actual. */
function bulk_ids(): array
{
    if (!empty($_POST['all_filtered'])) {
        $_GET = array_intersect_key($_POST, ['q' => 1, 'tag' => 1, 'status' => 1]) + $_GET;
        [$w, $p] = contact_where();
        return array_map('intval', array_column(all("SELECT id FROM contacts WHERE $w", $p), 'id'));
    }
    return array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
}
function back_to_list(): string
{
    $qs = http_build_query(array_filter(array_intersect_key($_POST, ['q' => 1, 'tag' => 1, 'status' => 1, 'per' => 1, 'page' => 1]), fn($v) => $v !== ''));
    return 'index.php?p=contacts' . ($qs ? '&' . $qs : '');
}

if ($a === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = bulk_ids();
    db()->beginTransaction();
    foreach ($ids as $id) { q('DELETE FROM contacts WHERE id = ?', [$id]); q('DELETE FROM confirmations WHERE contact_id = ?', [$id]); }
    db()->commit();
    flash($ids ? count($ids) . ' contacto(s) eliminado(s).' : 'No se ha seleccionado ningún contacto.', $ids ? 'ok' : 'err');
    redirect(back_to_list());
}

/* ---------- Añadir o quitar etiquetas a varios contactos ---------- */
if (($a === 'tagadd' || $a === 'tagdel') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = bulk_ids();
    $list = tags_list(tags_pack((string)($_POST['bulk_tags'] ?? '')));
    if (!$ids) flash('No se ha seleccionado ningún contacto.', 'err');
    elseif (!$list) flash('Escriba la etiqueta (o varias, separadas por comas).', 'err');
    else {
        $changed = 0;
        db()->beginTransaction();
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (all("SELECT id, tags FROM contacts WHERE id IN ($in)", $chunk) as $c) {
                $cur = tags_list($c['tags']);
                $new = $a === 'tagadd' ? array_merge($cur, $list) : array_diff($cur, $list);
                $packed = tags_pack($new);
                if ($packed !== $c['tags']) { q('UPDATE contacts SET tags = ? WHERE id = ?', [$packed, $c['id']]); $changed++; }
            }
        }
        db()->commit();
        $what = '«' . implode('», «', $list) . '»';
        flash($a === 'tagadd'
            ? "Etiqueta $what añadida a $changed contacto(s)" . ($changed < count($ids) ? ' (' . (count($ids) - $changed) . ' ya la tenían)' : '') . '.'
            : "Etiqueta $what quitada de $changed contacto(s)" . ($changed < count($ids) ? ' (' . (count($ids) - $changed) . ' no la tenían)' : '') . '.');
    }
    redirect(back_to_list());
}

/* ---------- Formulario ---------- */
if ($a === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $c = $id ? one('SELECT * FROM contacts WHERE id = ?', [$id]) : null;
    $c ??= ['id' => 0, 'email' => '', 'nombre' => '', 'apellidos' => '', 'empresa' => '', 'tags' => '', 'status' => 'subscribed'];
    layout_start($c['id'] ? 'Editar contacto' : 'Nuevo contacto', 'contacts'); ?>
    <div class="head"><h1><?= $c['id'] ? 'Editar contacto' : 'Nuevo contacto' ?></h1><a class="btn" href="index.php?p=contacts">Volver</a></div>
    <form method="post" action="index.php?p=contacts&a=save" class="card form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <label>Correo electrónico *<input type="email" name="email" value="<?= e($c['email']) ?>" required></label>
      <div class="row2">
        <label>Nombre<input name="nombre" value="<?= e($c['nombre']) ?>"></label>
        <label>Apellidos<input name="apellidos" value="<?= e($c['apellidos']) ?>"></label>
      </div>
      <label>Empresa<input name="empresa" value="<?= e($c['empresa']) ?>"></label>
      <label>Etiquetas (separadas por comas)<input name="tags" value="<?= e(implode(', ', tags_list($c['tags']))) ?>" placeholder="clientes, distribuidores"></label>
      <label>Estado<select name="status"><?php foreach ($STATUS as $k => $l): ?><option value="<?= $k ?>" <?= $c['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></label>
      <button class="btn primary">Guardar</button>
    </form>
    <?php layout_end(); return;
}

/* ---------- Listado ---------- */
[$w, $p] = contact_where();
$total = (int)val("SELECT COUNT(*) FROM contacts WHERE $w", $p);
$per = contacts_per_page();
$pages = $per > 0 ? max(1, (int)ceil($total / $per)) : 1;
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$rows = all("SELECT * FROM contacts WHERE $w ORDER BY id DESC" . ($per > 0 ? " LIMIT $per OFFSET " . (($page - 1) * $per) : ''), $p);
$tags = all_tags();
$qs = http_build_query(array_intersect_key($_GET, ['q' => 1, 'tag' => 1, 'status' => 1]));
$perQs = isset($_GET['per']) ? '&per=' . $per : '';   // conserva la elección de la URL al cambiar de página
$from = $total ? ($per > 0 ? ($page - 1) * $per + 1 : 1) : 0;
$to = $per > 0 ? min($total, $page * $per) : $total;
$counts = ['subscribed' => 0, 'unsubscribed' => 0, 'bounced' => 0, 'pending' => 0];
foreach (all('SELECT status, COUNT(*) n FROM contacts GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];

layout_start('Contactos', 'contacts'); ?>
<div class="head">
  <h1>Contactos <small><?= $counts['subscribed'] ?> suscritos · <?= $counts['unsubscribed'] ?> bajas</small></h1>
  <div class="actions">
    <a class="btn primary" href="index.php?p=contacts&a=edit">+ Nuevo contacto</a>
    <button class="btn" data-open="dlg-import">Importar</button>
    <a class="btn" href="index.php?p=contacts&a=export&format=csv&<?= e($qs) ?>">Exportar CSV</a>
    <a class="btn" href="index.php?p=contacts&a=export&format=xlsx&<?= e($qs) ?>">Exportar Excel</a>
  </div>
</div>

<form class="filters" method="get">
  <input type="hidden" name="p" value="contacts">
  <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Buscar por correo, nombre o empresa">
  <select name="tag"><option value="">Todas las etiquetas</option><?php foreach ($tags as $t => $n): ?><option value="<?= e($t) ?>" <?= ($_GET['tag'] ?? '') === (string)$t ? 'selected' : '' ?>><?= e($t) ?> (<?= $n ?>)</option><?php endforeach ?></select>
  <select name="status"><option value="">Todos los estados</option><?php foreach ($STATUS as $k => $l): ?><option value="<?= $k ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
  <button class="btn">Filtrar</button>
  <?php if ($qs): ?><a href="index.php?p=contacts">Quitar filtros</a><?php endif ?>
  <label class="perpage">Mostrar
    <select name="per" onchange="this.form.submit()"><?php foreach (PER_PAGE_OPTIONS as $n): ?><option value="<?= $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= per_page_label($n) ?></option><?php endforeach ?></select></label>
</form>

<form method="post" action="index.php?p=contacts&a=delete" id="bulkform">
<?= csrf_field() ?>
<?php foreach (['q', 'tag', 'status', 'per', 'page'] as $k): if (($_GET[$k] ?? '') !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)$_GET[$k]) ?>"><?php endif; endforeach ?>
<div class="bulkbar card" data-bulkbar>
  <span class="bulkcount"><b data-selcount>0</b> seleccionado(s)</span>
  <?php if ($total > count($rows)): ?>
    <label class="check"><input type="checkbox" name="all_filtered" value="1" data-allfiltered> Aplicar a los <?= $total ?> contactos <?= $qs ? 'del filtro' : '' ?> (todas las páginas)</label>
  <?php endif ?>
  <input name="bulk_tags" list="taglist" placeholder="Etiqueta (o varias, separadas por comas)" aria-label="Etiquetas">
  <datalist id="taglist"><?php foreach ($tags as $t => $n): ?><option value="<?= e($t) ?>"><?php endforeach ?></datalist>
  <button class="btn small primary" formaction="index.php?p=contacts&a=tagadd" data-bulk="add">+ Añadir etiqueta</button>
  <button class="btn small" formaction="index.php?p=contacts&a=tagdel" data-bulk="del">− Quitar etiqueta</button>
  <button class="btn small danger" formaction="index.php?p=contacts&a=delete" data-bulk="delete">Eliminar</button>
</div>
<div class="card table">
<table>
  <thead><tr><th class="chk"><input type="checkbox" data-checkall></th><th>Correo</th><th>Nombre</th><th>Empresa</th><th>Etiquetas</th><th>Estado</th><th>Alta</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td class="chk"><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>"></td>
      <td><a href="index.php?p=contacts&a=edit&id=<?= $c['id'] ?>"><?= e($c['email']) ?></a></td>
      <td><?= e(trim($c['nombre'] . ' ' . $c['apellidos'])) ?></td>
      <td><?= e($c['empresa']) ?></td>
      <td><?php foreach (tags_list($c['tags']) as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach ?></td>
      <td><span class="pill <?= e($c['status']) ?>"><?= e($STATUS[$c['status']] ?? $c['status']) ?></span></td>
      <td class="muted"><?= e(date('d/m/Y', strtotime($c['created_at']))) ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">No hay contactos<?= $qs ? ' con esos filtros' : '. Añada uno o importe un archivo CSV o Excel' ?>.</td></tr><?php endif ?>
  </tbody>
</table>
</div>
<div class="foot">
  <span class="muted"><?= $total ? "Mostrando {$from}–{$to} de {$total} contacto(s)" : '0 contactos' ?></span>
  <?php if ($pages > 1): $link = fn(int $n) => 'index.php?p=contacts&page=' . $n . $perQs . ($qs ? '&' . e($qs) : ''); ?>
  <span class="pager">
    <?php if ($page > 1): ?><a class="btn small" href="<?= $link($page - 1) ?>">← Anterior</a><?php endif ?>
    <?php $nums = array_unique([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages]); sort($nums); foreach ($nums as $n): if ($n < 1 || $n > $pages) continue; ?>
      <?php if (isset($prevN) && $n > $prevN + 1): ?><span class="muted">…</span><?php endif; $prevN = $n; ?>
      <?php if ($n === $page): ?><span class="btn small on"><?= $n ?></span><?php else: ?><a class="btn small" href="<?= $link($n) ?>"><?= $n ?></a><?php endif ?>
    <?php endforeach ?>
    <?php if ($page < $pages): ?><a class="btn small" href="<?= $link($page + 1) ?>">Siguiente →</a><?php endif ?>
  </span>
  <?php endif ?>
</div>
</form>

<dialog id="dlg-import">
  <form method="post" action="index.php?p=contacts&a=import" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <h2>Importar contactos</h2>
    <p class="muted">Suba un archivo <b>.csv</b> o <b>.xlsx</b>. Sirve tal cual la <b>exportación de contactos de Mautic</b>: las columnas se reconocen solas, incluida la de «no enviar correos» (Do Not Contact), y podrá revisarlas antes de importar.</p>
    <label>Archivo<input type="file" name="file" accept=".csv,.xlsx,.txt" required></label>
    <div class="actions"><button class="btn primary">Continuar</button><button type="button" class="btn" data-close>Cancelar</button></div>
  </form>
</dialog>
<?php layout_end();
