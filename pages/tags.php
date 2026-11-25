<?php
/* Pestaña Etiquetas: todas las etiquetas con cuántos contactos tiene cada una.
   Al pulsar una etiqueta se abre Contactos filtrado por ella. */
$stats = [];
foreach (all("SELECT tags, status, unsub_tags FROM contacts WHERE tags <> ''") as $r) {
    $off = optouts($r);
    foreach (tags_list($r['tags']) as $t) {
        $stats[$t] ??= ['total' => 0, 'subscribed' => 0, 'unsubscribed' => 0, 'other' => 0];
        $stats[$t]['total']++;
        // Quien se dio de baja de ESTE listado cuenta como baja aunque siga recibiendo otros
        $k = $r['status'] === 'subscribed' && in_array((string)$t, $off, true) ? 'unsubscribed'
            : (in_array($r['status'], ['subscribed', 'unsubscribed'], true) ? $r['status'] : 'other');
        $stats[$t][$k]++;
    }
}
$untagged = (int)val("SELECT COUNT(*) FROM contacts WHERE tags = '' OR tags IS NULL");
$q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
if ($q !== '') $stats = array_filter($stats, fn($t) => str_contains((string)$t, $q), ARRAY_FILTER_USE_KEY);
$sort = in_array($_GET['sort'] ?? '', ['name', 'total', 'subscribed'], true) ? $_GET['sort'] : 'name';
uksort($stats, function ($a, $b) use ($stats, $sort) {
    if ($sort === 'name') return strnatcasecmp((string)$a, (string)$b);
    return [$stats[$b][$sort], (string)$a] <=> [$stats[$a][$sort], (string)$b];
});
$sortLink = fn(string $s, string $label) => '<a href="index.php?p=tags&sort=' . $s . ($q !== '' ? '&q=' . rawurlencode($q) : '') . '"'
    . ($sort === $s ? ' class="cur"' : '') . '>' . $label . ($sort === $s ? ($s === 'name' ? ' ↑' : ' ↓') : '') . '</a>';
$max = max([1, ...array_values(array_map(fn($s) => $s['total'], $stats))]);

layout_start('Etiquetas', 'tags'); ?>
<div class="head">
  <h1>Etiquetas <small><?= count($stats) ?> etiqueta(s)<?= $q !== '' ? ' con «' . e($q) . '»' : '' ?></small></h1>
  <form class="filters" method="get" style="margin:0">
    <input type="hidden" name="p" value="tags"><input type="hidden" name="sort" value="<?= e($sort) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar etiqueta">
    <button class="btn">Buscar</button>
    <?php if ($q !== ''): ?><a href="index.php?p=tags&sort=<?= e($sort) ?>">Ver todas</a><?php endif ?>
    <button type="button" class="btn primary" data-import-tag="">Importar a una etiqueta nueva</button>
  </form>
</div>
<div class="card table tagtable"><table>
  <thead><tr>
    <th><?= $sortLink('name', 'Etiqueta') ?></th>
    <th><?= $sortLink('total', 'Contactos') ?></th>
    <th><?= $sortLink('subscribed', 'Suscritos') ?></th>
    <th>Bajas</th>
    <th>Otros</th>
    <th class="barcol"></th>
    <th>Importar / exportar</th>
  </tr></thead>
  <tbody>
  <?php foreach ($stats as $t => $s): $url = 'index.php?p=contacts&tag=' . rawurlencode((string)$t); ?>
    <tr data-href="<?= e($url) ?>">
      <td><a href="<?= e($url) ?>"><span class="tag big"><?= e((string)$t) ?></span></a></td>
      <td><a href="<?= e($url) ?>"><b><?= $s['total'] ?></b></a></td>
      <td><?= $s['subscribed'] ? '<a href="' . e($url . '&status=subscribed') . '">' . $s['subscribed'] . '</a>' : '<span class="muted">0</span>' ?></td>
      <td><?= $s['unsubscribed'] ? '<a href="' . e($url . '&status=unsubscribed') . '">' . $s['unsubscribed'] . '</a>' : '<span class="muted">0</span>' ?></td>
      <td><?= $s['other'] ?: '<span class="muted">0</span>' ?></td>
      <td class="barcol"><span class="track"><i style="width:<?= round($s['total'] / $max * 100) ?>%"></i></span></td>
      <td class="nowrap tagactions">
        <button type="button" class="btn small" data-import-tag="<?= e((string)$t) ?>" title="Importar contactos a esta etiqueta">Importar</button>
        <a class="btn small" href="<?= e('index.php?p=contacts&a=export&format=csv&tag=' . rawurlencode((string)$t)) ?>" title="Exportar los contactos de esta etiqueta en CSV">CSV</a>
        <a class="btn small" href="<?= e('index.php?p=contacts&a=export&format=xlsx&tag=' . rawurlencode((string)$t)) ?>" title="Exportar los contactos de esta etiqueta en Excel">Excel</a>
      </td>
    </tr>
  <?php endforeach ?>
  <?php if (!$stats): ?><tr><td colspan="7" class="empty"><?= $q !== '' ? 'Ninguna etiqueta contiene «' . e($q) . '».' : 'Todavía no hay etiquetas. Se añaden al importar contactos, al editarlos o desde la barra de acciones del listado de contactos.' ?></td></tr><?php endif ?>
  </tbody>
</table></div>
<p class="muted">Pulse una etiqueta para ver sus contactos. «Bajas» incluye a quien se dio de baja de todo y a quien se dio de baja solo de ese listado; «Otros» son los pendientes de confirmar y los rebotados.<?= $untagged ? " Hay <b>$untagged</b> contacto(s) sin ninguna etiqueta." : '' ?></p>
<dialog id="dlg-tagimport">
  <form method="post" action="index.php?p=contacts&a=import" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?><input type="hidden" name="from" value="tags">
    <h2 data-import-title>Importar contactos</h2>
    <p class="muted">Archivo <b>.csv</b> o <b>.xlsx</b> (sirve la exportación de Mautic). Todos los contactos del archivo recibirán la etiqueta; después podrá revisar las columnas antes de importar.</p>
    <label>Etiqueta<input name="preset_tag" required list="tagnames" placeholder="nombre de la etiqueta"></label>
    <datalist id="tagnames"><?php foreach (array_keys($stats) as $t): ?><option value="<?= e((string)$t) ?>"><?php endforeach ?></datalist>
    <label>Archivo<input type="file" name="file" accept=".csv,.xlsx,.txt" required></label>
    <div class="actions"><button class="btn primary">Continuar</button><button type="button" class="btn" data-close>Cancelar</button></div>
  </form>
</dialog>
<?php layout_end();
