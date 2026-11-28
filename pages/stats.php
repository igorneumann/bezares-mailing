<?php
/* Pestaña Estadísticas: resultados de los envíos en un periodo, en general y por etiqueta (listado al que se envió).
   - Enviados / fallidos: mensajes de la cola enviados o que fallaron en el periodo.
   - Abiertos: se cargó el píxel de apertura o se hizo clic (muchos programas bloquean las imágenes).
   - Clics: se pulsó algún enlace del correo (los enlaces pasan por c.php desde la versión 2.18).
   - Conversiones: hizo clic en algún enlace o respondió la encuesta del correo.
   - Bajas: se dio de baja desde el enlace de ese correo. */

$periods = ['lastmonth' => 'Mes pasado', 'thismonth' => 'Este mes', '7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días',
    '365' => 'Últimos 12 meses', 'ytd' => 'Este año', 'all' => 'Todo', 'custom' => 'Personalizado'];
$period = isset($periods[$_GET['period'] ?? '']) ? (string)$_GET['period'] : 'lastmonth';
$today = date('Y-m-d');
$valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : '';
if ($period === 'custom') {
    $from = $valid($_GET['from'] ?? '') ?: date('Y-m-d', strtotime('-30 days'));
    $to = $valid($_GET['to'] ?? '') ?: $today;
    if ($from > $to) [$from, $to] = [$to, $from];
} elseif ($period === 'lastmonth') { $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('first day of last month')); }
elseif ($period === 'thismonth') { $from = date('Y-m-01'); $to = $today; }
elseif ($period === 'ytd') { $from = date('Y-01-01'); $to = $today; }
elseif ($period === 'all') {
    $first = (string)val("SELECT MIN(COALESCE(q.sent_at, s.created_at)) FROM queue q JOIN sends s ON s.id = q.send_id WHERE q.status IN ('sent','failed')");
    $from = $first !== '' ? substr($first, 0, 10) : $today; $to = $today;
} else { $from = date('Y-m-d', strtotime('-' . ((int)$period - 1) . ' days')); $to = $today; }
$fromTs = $from . ' 00:00:00'; $toTs = $to . ' 23:59:59';

/* Periodo de comparación: el anterior equivalente (por defecto: el mes anterior al mes pasado), el mismo del año
   anterior, unas fechas a elegir o ninguno. */
$compares = ['prev' => 'Periodo anterior', 'year' => 'Mismo periodo del año anterior', 'custom' => 'Fechas a elegir', 'none' => 'Sin comparar'];
$compare = isset($compares[$_GET['compare'] ?? '']) ? (string)$_GET['compare'] : 'prev';
$shiftMonth = function (string $d, int $n): string { // misma fecha n meses antes, sin pasarse del último día del mes
    $t = strtotime(substr($d, 0, 7) . '-01 -' . $n . ' month');
    return date('Y-m-', $t) . sprintf('%02d', min((int)substr($d, 8, 2), (int)date('t', $t)));
};
$cfrom = $cto = '';
if ($compare === 'prev') {
    if ($period === 'lastmonth' || $period === 'thismonth') {
        $cfrom = date('Y-m-01', strtotime($from . ' -1 month'));
        $cto = $period === 'lastmonth' ? date('Y-m-t', strtotime($cfrom)) : $shiftMonth($to, 1);
    } elseif ($period === 'ytd') { $cfrom = date('Y-m-d', strtotime($from . ' -1 year')); $cto = $shiftMonth($to, 12); }
    else {
        $len = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $cto = date('Y-m-d', strtotime($from . ' -1 day')); $cfrom = date('Y-m-d', strtotime($cto . ' -' . ($len - 1) . ' days'));
    }
} elseif ($compare === 'year') { $cfrom = $shiftMonth($from, 12); $cto = $shiftMonth($to, 12); }
elseif ($compare === 'custom') {
    $cfrom = $valid($_GET['cfrom'] ?? '') ?: date('Y-m-01', strtotime($from . ' -1 month'));
    $cto = $valid($_GET['cto'] ?? '') ?: date('Y-m-t', strtotime($cfrom));
    if ($cfrom > $cto) [$cfrom, $cto] = [$cto, $cfrom];
}
$cmp = $cfrom !== '';
// Etiquetas (listados) que tienen envíos; '_' = envíos a todos los suscritos, sin etiqueta
$tags = array_map('strval', array_column(all("SELECT DISTINCT tag FROM sends WHERE tag <> '' ORDER BY tag"), 'tag'));
$hasUntagged = (bool)val("SELECT 1 FROM sends WHERE tag = '' LIMIT 1");
$tag = (string)($_GET['tag'] ?? '');
if ($tag !== '' && $tag !== '_' && !in_array($tag, $tags, true)) $tag = '';
$tagName = fn(string $t) => $t === '' ? 'Todos los suscritos (sin etiqueta)' : $t;

/* ---------- Consultas ---------- */
$D = 'COALESCE(q.sent_at, s.created_at)';
$RESP = 'EXISTS (SELECT 1 FROM responses r JOIN emails e2 ON e2.survey_id = r.survey_id
    WHERE e2.id = q.email_id AND r.contact_id = q.contact_id AND r.created_at >= q.sent_at)';
$AGG = "COUNT(*) AS tried,
    SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END) AS sent,
    SUM(CASE WHEN q.status = 'failed' THEN 1 ELSE 0 END) AS failed,
    SUM(CASE WHEN q.status = 'sent' AND (q.opened_at IS NOT NULL OR q.clicked_at IS NOT NULL) THEN 1 ELSE 0 END) AS opened,
    SUM(CASE WHEN q.status = 'sent' AND q.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
    SUM(CASE WHEN q.status = 'sent' THEN COALESCE(q.clicks, 0) ELSE 0 END) AS clicks,
    SUM(CASE WHEN q.status = 'sent' AND $RESP THEN 1 ELSE 0 END) AS responded,
    SUM(CASE WHEN q.status = 'sent' AND (q.clicked_at IS NOT NULL OR $RESP) THEN 1 ELSE 0 END) AS converted,
    SUM(CASE WHEN q.unsub_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubs,
    COUNT(DISTINCT CASE WHEN q.status = 'sent' THEN q.contact_id END) AS people";
$FROM = "FROM queue q JOIN sends s ON s.id = q.send_id WHERE q.status IN ('sent','failed') AND $D BETWEEN ? AND ?";
$P = [$fromTs, $toTs];
if ($tag !== '') { $FROM .= ' AND s.tag = ?'; $P[] = $tag === '_' ? '' : $tag; }

$zero = ['tried' => 0, 'sent' => 0, 'failed' => 0, 'opened' => 0, 'clicked' => 0, 'clicks' => 0, 'responded' => 0, 'converted' => 0, 'unsubs' => 0, 'people' => 0];
$norm = fn(?array $r) => array_map('intval', array_intersect_key(($r ?? []) + $zero, $zero));
$tot = $norm(one("SELECT $AGG $FROM", $P));
$byTag = [];
foreach (all("SELECT s.tag AS g, $AGG $FROM GROUP BY s.tag", $P) as $r) $byTag[(string)$r['g']] = $norm($r);
// Las mismas cifras en el periodo de comparación
$ctot = $zero; $cbyTag = [];
if ($cmp) {
    $CP = $P; $CP[0] = $cfrom . ' 00:00:00'; $CP[1] = $cto . ' 23:59:59';
    $ctot = $norm(one("SELECT $AGG $FROM", $CP));
    foreach (all("SELECT s.tag AS g, $AGG $FROM GROUP BY s.tag", $CP) as $r) $cbyTag[(string)$r['g']] = $norm($r);
}
uasort($byTag, fn($a, $b) => $b['sent'] <=> $a['sent']);
$byEmail = [];
foreach (all("SELECT q.email_id AS g, $AGG $FROM GROUP BY q.email_id", $P) as $r) $byEmail[(int)$r['g']] = $norm($r);
uasort($byEmail, fn($a, $b) => $b['sent'] <=> $a['sent']);
$byEmail = array_slice($byEmail, 0, 30, true);
$names = [];
if ($byEmail) foreach (all('SELECT id, name, subject FROM emails WHERE id IN (' . implode(',', array_map('intval', array_keys($byEmail))) . ')') as $e) $names[(int)$e['id']] = $e;

// Evolución: por días hasta ~2 meses, por meses si el periodo es más largo
$days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
$monthly = $days > 62;
$len = $monthly ? 7 : 10;
$series = [];
foreach (all("SELECT SUBSTR($D, 1, $len) AS g, $AGG $FROM GROUP BY SUBSTR($D, 1, $len)", $P) as $r) $series[(string)$r['g']] = $norm($r);
$buckets = [];
for ($t = strtotime($monthly ? substr($from, 0, 7) . '-01' : $from); $t <= strtotime($to); $t = strtotime($monthly ? '+1 month' : '+1 day', $t)) {
    $k = date($monthly ? 'Y-m' : 'Y-m-d', $t);
    $buckets[$k] = $series[$k] ?? $zero;
}
$chartMax = max(1, ...array_column($buckets, 'sent'));
// Comparación en el gráfico: los enviados del otro periodo, tramo a tramo (el primer día con el primer día…)
$cSent = [];
if ($cmp) {
    $cs = [];
    foreach (all("SELECT SUBSTR($D, 1, $len) AS g, $AGG $FROM GROUP BY SUBSTR($D, 1, $len)", $CP) as $r) $cs[(string)$r['g']] = $norm($r);
    for ($t = strtotime($monthly ? substr($cfrom, 0, 7) . '-01' : $cfrom); $t <= strtotime($cto) && count($cSent) < count($buckets); $t = strtotime($monthly ? '+1 month' : '+1 day', $t)) {
        $cSent[] = ($cs[date($monthly ? 'Y-m' : 'Y-m-d', $t)] ?? $zero)['sent'];
    }
    $cSent = array_pad($cSent, count($buckets), 0);
}
/* Gráfico que dibuja assets/charts.js: line, bar, stack o spark. */
$chart = function (string $type, array $labels, array $series, string $label, array $opt = []): string {
    $d = ['type' => $type, 'labels' => array_values($labels), 'series' => array_values($series), 'label' => $label] + array_intersect_key($opt, ['fmt' => 1, 'ratio' => 1]);
    return '<div class="chx-box' . ($type === 'spark' ? ' chx-spark' : '') . '" data-chart="' . e(json_encode($d, JSON_UNESCAPED_UNICODE)) . '"' . (isset($opt['h']) ? ' data-h="' . (int)$opt['h'] . '"' : '') . '></div>';
};

// Enlaces más pulsados en el periodo (las direcciones de encuesta llevan datos personales: se agrupan por encuesta)
$linkLabel = function (string $u): string {
    $p = parse_url($u);
    if (str_ends_with((string)($p['path'] ?? ''), '/s.php')) {
        parse_str((string)($p['query'] ?? ''), $qs);
        return 'Encuesta «' . (string)($qs['s'] ?? '') . '»';
    }
    return $u;
};
$links = [];
$LP = [$fromTs, $toTs];
$LW = '';
if ($tag !== '') { $LW = ' AND s.tag = ?'; $LP[] = $tag === '_' ? '' : $tag; }
foreach (all("SELECT c.url, c.queue_id FROM clicks c JOIN queue q ON q.id = c.queue_id JOIN sends s ON s.id = q.send_id
    WHERE c.at BETWEEN ? AND ?$LW ORDER BY c.id DESC LIMIT 20000", $LP) as $r) {
    $k = $linkLabel((string)$r['url']);
    $links[$k] ??= ['clicks' => 0, 'people' => []];
    $links[$k]['clicks']++;
    $links[$k]['people'][(int)$r['queue_id']] = true;
}
uasort($links, fn($a, $b) => count($b['people']) <=> count($a['people']) ?: $b['clicks'] <=> $a['clicks']);
$links = array_slice($links, 0, 12, true);

$clickSince = (string)val('SELECT MIN(at) FROM clicks');
$pending = (int)val("SELECT COUNT(*) FROM queue WHERE status IN ('pending','sending')");

/* ---------- Exportar ---------- */
$pct = fn($x, $of) => $of ? round($x / $of * 100, 1) . '%' : '—';
if (($_GET['export'] ?? '') !== '') {
    $out = [['Periodo', 'Etiqueta', 'Enviados', 'Fallidos', 'Abiertos', '% apertura', 'Clic', '% clic', 'Clics totales', 'Respondieron encuesta', 'Conversiones', '% conversión', 'Bajas']];
    $line = fn(string $per, string $label, array $n) => [$per, $label, $n['sent'], $n['failed'], $n['opened'], $pct($n['opened'], $n['sent']), $n['clicked'], $pct($n['clicked'], $n['sent']),
        $n['clicks'], $n['responded'], $n['converted'], $pct($n['converted'], $n['sent']), $n['unsubs']];
    $pa = date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to));
    $out[] = $line($pa, 'TOTAL', $tot);
    foreach ($byTag as $g => $n) $out[] = $line($pa, $tagName((string)$g), $n);
    if ($cmp) {
        $pb = date('d/m/Y', strtotime($cfrom)) . ' - ' . date('d/m/Y', strtotime($cto));
        $out[] = $line($pb, 'TOTAL', $ctot);
        foreach ($cbyTag as $g => $n) $out[] = $line($pb, $tagName((string)$g), $n);
    }
    download_table($out, 'estadisticas-' . $from . '-a-' . $to, $_GET['export'] === 'xlsx' ? 'xlsx' : 'csv');
}

$q = fn(array $o = []) => 'index.php?' . e(http_build_query(array_filter($o + ['p' => 'stats', 'period' => $period,
    'from' => $period === 'custom' ? $from : null, 'to' => $period === 'custom' ? $to : null, 'tag' => $tag,
    'compare' => $compare, 'cfrom' => $compare === 'custom' ? $cfrom : null, 'cto' => $compare === 'custom' ? $cto : null], fn($x) => $x !== '' && $x !== null)));
$fmt = fn(string $d) => date('d/m/Y', strtotime($d));
$bucketLabel = function (string $k) use ($monthly): string {
    if ($monthly) { $m = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']; return $m[(int)substr($k, 5, 2) - 1] . ' ' . substr($k, 2, 2); }
    return date('d/m', strtotime($k));
};
/* Variación respecto al periodo de comparación. Recuentos: % de cambio; tasas: puntos porcentuales.
   $upGood = false para fallidos y bajas, donde subir es malo. */
$delta = function (float $now, float $before, bool $upGood = true, bool $points = false) use ($cmp): string {
    if (!$cmp) return '';
    if ($points) { $d = ($now - $before) * 100; $txt = ($d > 0 ? '+' : '') . number_format($d, 1, ',', '.') . ' pp'; }
    elseif ($before == 0) { $d = $now; $txt = $now == 0 ? '=' : 'nuevo'; }
    else { $d = ($now - $before) / $before * 100; $txt = ($d > 0 ? '+' : '') . number_format($d, round(abs($d)) >= 10 ? 0 : 1, ',', '.') . ' %'; }
    $cls = abs($d) < 0.05 ? 'eq' : (($d > 0) === $upGood ? 'up' : 'down');
    $arrow = $cls === 'eq' ? '' : ($d > 0 ? '▲ ' : '▼ ');
    return '<span class="delta ' . $cls . '">' . $arrow . $txt . '</span>';
};
$kpi = function (string $label, int $value, string $sub = '', ?float $ratio = null, string $cls = '', string $dl = '', ?int $before = null, string $spark = '') use ($buckets, $chart) {
    $sp = $spark !== '' ? array_column($buckets, $spark) : [];
    ?><div class="card kpi stat <?= $cls ?>"><span><?= e($label) ?></span><b><?= number_format($value, 0, ',', '.') ?></b>
      <?php if ($sub !== ''): ?><em><?= $sub ?></em><?php endif ?>
      <?php if ($dl !== ''): ?><div class="cmpline"><?= $dl ?> <small>antes <?= number_format((int)$before, 0, ',', '.') ?></small></div><?php endif ?>
      <?php if ($ratio !== null): ?><div class="bar"><div class="fill" style="width:<?= min(100, max(0, $ratio * 100)) ?>%"></div></div><?php endif ?>
      <?php if (count($sp) > 1 && array_sum($sp)): ?><?= $chart('spark', array_fill(0, count($sp), ''), [['name' => $label, 'data' => $sp]], 'Evolución de ' . mb_strtolower($label), ['h' => 30]) ?><?php endif ?></div><?php
};
$rate = fn($x, $of) => $of ? $x / $of : 0.0;

layout_start('Estadísticas', 'stats'); ?>
<div class="head">
  <h1>Estadísticas <small><?= $fmt($from) ?> – <?= $fmt($to) ?><?= $cmp ? ' <span class="vs">vs</span> ' . $fmt($cfrom) . ' – ' . $fmt($cto) : '' ?><?= $tag !== '' ? ' · ' . e($tagName($tag === '_' ? '' : $tag)) : '' ?></small></h1>
  <div class="actions">
    <a class="btn" href="<?= $q(['export' => 'csv']) ?>">Exportar CSV</a>
    <a class="btn" href="<?= $q(['export' => 'xlsx']) ?>">Exportar Excel</a>
  </div>
</div>

<form class="filters card statfilters" method="get">
  <input type="hidden" name="p" value="stats">
  <label>Periodo <select name="period" onchange="this.form.querySelectorAll('.custom').forEach(x => x.hidden = this.value !== 'custom'); if (this.value !== 'custom') this.form.submit()">
    <?php foreach ($periods as $k => $l): ?><option value="<?= $k ?>" <?= (string)$k === $period ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></label>
  <label class="custom" <?= $period === 'custom' ? '' : 'hidden' ?>>Desde <input type="date" name="from" value="<?= e($from) ?>" max="<?= $today ?>"></label>
  <label class="custom" <?= $period === 'custom' ? '' : 'hidden' ?>>Hasta <input type="date" name="to" value="<?= e($to) ?>" max="<?= $today ?>"></label>
  <label>Comparar con <select name="compare" onchange="this.form.querySelectorAll('.ccustom').forEach(x => x.hidden = this.value !== 'custom'); if (this.value !== 'custom') this.form.submit()">
    <?php foreach ($compares as $k => $l): ?><option value="<?= $k ?>" <?= $k === $compare ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></label>
  <label class="ccustom" <?= $compare === 'custom' ? '' : 'hidden' ?>>Comparar desde <input type="date" name="cfrom" value="<?= e($cfrom) ?>" max="<?= $today ?>"></label>
  <label class="ccustom" <?= $compare === 'custom' ? '' : 'hidden' ?>>Comparar hasta <input type="date" name="cto" value="<?= e($cto) ?>" max="<?= $today ?>"></label>
  <label>Etiqueta <select name="tag" onchange="this.form.submit()">
    <option value="">Todas</option>
    <?php foreach ($tags as $t): ?><option value="<?= e($t) ?>" <?= $t === $tag ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach ?>
    <?php if ($hasUntagged): ?><option value="_" <?= $tag === '_' ? 'selected' : '' ?>><?= e($tagName('')) ?></option><?php endif ?>
  </select></label>
  <button class="btn primary">Ver</button>
</form>

<div class="kpis statkpis">
  <?php $c = $ctot; $rd = fn($k, $of = 'sent', $good = true) => $delta($rate($tot[$k], $tot[$of]), $rate($c[$k], $c[$of]), $good, true); ?>
  <?php $kpi('Enviados', $tot['sent'], number_format($tot['people'], 0, ',', '.') . ' contacto(s) distintos' . ($pending ? ' · ' . $pending . ' en cola ahora' : ''), null, '', $delta($tot['sent'], $c['sent']), $c['sent'], 'sent') ?>
  <?php $kpi('Fallidos', $tot['failed'], $pct($tot['failed'], $tot['tried']) . ' de los intentos', $rate($tot['failed'], $tot['tried']), 'bad', $delta($tot['failed'], $c['failed'], false) . ' ' . $rd('failed', 'tried', false), $c['failed'], 'failed') ?>
  <?php $kpi('Abiertos', $tot['opened'], $pct($tot['opened'], $tot['sent']) . ' de apertura', $rate($tot['opened'], $tot['sent']), 'ok', $delta($tot['opened'], $c['opened']) . ' ' . $rd('opened'), $c['opened'], 'opened') ?>
  <?php $kpi('Hicieron clic', $tot['clicked'], $pct($tot['clicked'], $tot['sent']) . ' de clic · ' . number_format($tot['clicks'], 0, ',', '.') . ' clics', $rate($tot['clicked'], $tot['sent']), '', $delta($tot['clicked'], $c['clicked']) . ' ' . $rd('clicked'), $c['clicked'], 'clicked') ?>
  <?php $kpi('Respondieron la encuesta', $tot['responded'], $pct($tot['responded'], $tot['sent']) . ' de los enviados', $rate($tot['responded'], $tot['sent']), '', $delta($tot['responded'], $c['responded']) . ' ' . $rd('responded'), $c['responded'], 'responded') ?>
  <?php $kpi('Conversiones', $tot['converted'], $pct($tot['converted'], $tot['sent']) . ' · clic o respuesta', $rate($tot['converted'], $tot['sent']), 'conv', $delta($tot['converted'], $c['converted']) . ' ' . $rd('converted'), $c['converted'], 'converted') ?>
  <?php $kpi('Clic sobre abiertos', $tot['clicked'], $pct($tot['clicked'], $tot['opened']) . ' de los que abrieron', $rate($tot['clicked'], $tot['opened']), '', $rd('clicked', 'opened'), $c['clicked']) ?>
  <?php $kpi('Bajas desde el correo', $tot['unsubs'], $pct($tot['unsubs'], $tot['sent']) . ' de los enviados', null, 'warnk', $delta($tot['unsubs'], $c['unsubs'], false), $c['unsubs'], 'unsubs') ?>
</div>

<?php if ($cmp): $rows = [
    ['Enviados', 'sent', null, true], ['Fallidos', 'failed', null, false], ['Tasa de fallo', 'failed', 'tried', false],
    ['Abiertos', 'opened', null, true], ['Tasa de apertura', 'opened', 'sent', true],
    ['Hicieron clic', 'clicked', null, true], ['Tasa de clic', 'clicked', 'sent', true], ['Clics totales', 'clicks', null, true],
    ['Respondieron la encuesta', 'responded', null, true], ['Conversiones', 'converted', null, true], ['Tasa de conversión', 'converted', 'sent', true],
    ['Bajas desde el correo', 'unsubs', null, false], ['Tasa de bajas', 'unsubs', 'sent', false]]; ?>
<div class="card table cmptable">
  <table class="stattable">
    <thead><tr><th>Comparación</th><th><?= $fmt($from) ?> – <?= $fmt($to) ?></th><th><?= $fmt($cfrom) ?> – <?= $fmt($cto) ?></th><th>Cambio</th></tr></thead>
    <tbody>
    <?php foreach ($rows as [$label, $k, $of, $good]): ?>
      <tr class="<?= $of ? 'ratio' : '' ?>"><td><?= $label ?></td>
        <?php if ($of): ?>
        <td class="num"><?= $pct($tot[$k], $tot[$of]) ?></td><td class="num"><?= $pct($ctot[$k], $ctot[$of]) ?></td>
        <td class="num"><?= $delta($rate($tot[$k], $tot[$of]), $rate($ctot[$k], $ctot[$of]), $good, true) ?></td>
        <?php else: ?>
        <td class="num"><b><?= number_format($tot[$k], 0, ',', '.') ?></b></td><td class="num"><?= number_format($ctot[$k], 0, ',', '.') ?></td>
        <td class="num"><?= $delta($tot[$k], $ctot[$k], $good) ?> <small><?= ($tot[$k] - $ctot[$k] > 0 ? '+' : '') . number_format($tot[$k] - $ctot[$k], 0, ',', '.') ?></small></td>
        <?php endif ?>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
<?php endif ?>

<div class="card">
  <h2 style="margin:0 0 4px">Evolución <small class="muted">por <?= $monthly ? 'mes' : 'día' ?></small></h2>
  <?php if (!$tot['tried']): ?><p class="empty">No hay envíos en este periodo.</p><?php else:
    $labels = array_map($bucketLabel, array_keys($buckets));
    $ser = [['name' => 'Enviados', 'data' => array_column($buckets, 'sent'), 'color' => 1], ['name' => 'Abiertos', 'data' => array_column($buckets, 'opened'), 'color' => 3],
        ['name' => 'Conversiones', 'data' => array_column($buckets, 'converted'), 'color' => 2], ['name' => 'Fallidos', 'data' => array_column($buckets, 'failed'), 'color' => 8]];
    if ($cmp) $ser[] = ['name' => 'Enviados ' . $fmt($cfrom) . ' – ' . $fmt($cto), 'data' => $cSent, 'color' => 'muted', 'dash' => true]; ?>
  <?= $chart('line', $labels, $ser, 'Evolución de los envíos', ['h' => 240, 'ratio' => [1, 0, 'Apertura']]) ?>
  <p class="muted small-note">Pase el ratón por el gráfico para ver las cifras de cada <?= $monthly ? 'mes' : 'día' ?>.<?= $cmp ? ' La línea discontinua son los enviados del periodo comparado.' : '' ?></p>
  <?php endif ?>
</div>

<h2>Por etiqueta <small class="muted">listado al que se envió</small></h2>
<div class="card table">
  <table class="stattable">
    <thead><tr><th>Etiqueta</th><th>Enviados</th><th>Fallidos</th><th>Abiertos</th><th>Clic</th><th>Encuesta</th><th>Conversiones</th><th>Bajas</th></tr></thead>
    <tbody>
    <?php if (!$byTag): ?><tr><td colspan="8" class="empty">No hay envíos en este periodo.</td></tr><?php endif ?>
    <?php foreach ($byTag as $g => $n): $g = (string)$g; ?>
      <tr data-href="<?= $q(['tag' => $g === '' ? '_' : $g]) ?>">
        <td><a href="<?= $q(['tag' => $g === '' ? '_' : $g]) ?>"><?= $g === '' ? '<span class="muted">' . e($tagName('')) . '</span>' : '<span class="tag big">' . e($g) . '</span>' ?></a></td>
        <?php $cn = $cbyTag[$g] ?? $zero; ?>
        <td class="num"><?= $n['sent'] ?><?= $cmp ? '<br>' . $delta($n['sent'], $cn['sent']) : '' ?></td>
        <td class="num"><?= $n['failed'] ?: '<span class="muted">0</span>' ?> <small><?= $n['failed'] ? $pct($n['failed'], $n['tried']) : '' ?></small></td>
        <td class="num"><?= $n['opened'] ?> <small><?= $pct($n['opened'], $n['sent']) ?></small><?= $cmp ? '<br>' . $delta($rate($n['opened'], $n['sent']), $rate($cn['opened'], $cn['sent']), true, true) : '' ?></td>
        <td class="num"><?= $n['clicked'] ?> <small><?= $pct($n['clicked'], $n['sent']) ?></small><?= $cmp ? '<br>' . $delta($rate($n['clicked'], $n['sent']), $rate($cn['clicked'], $cn['sent']), true, true) : '' ?></td>
        <td class="num"><?= $n['responded'] ?> <small><?= $pct($n['responded'], $n['sent']) ?></small></td>
        <td class="num"><b><?= $n['converted'] ?></b> <small><?= $pct($n['converted'], $n['sent']) ?></small><?= $cmp ? '<br>' . $delta($rate($n['converted'], $n['sent']), $rate($cn['converted'], $cn['sent']), true, true) : '' ?></td>
        <td class="num"><?= $n['unsubs'] ?: '<span class="muted">0</span>' ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<div class="cols">
  <div>
    <h2>Por correo</h2>
    <div class="card table">
      <table class="stattable">
        <thead><tr><th>Correo</th><th>Enviados</th><th>Abiertos</th><th>Conversiones</th></tr></thead>
        <tbody>
        <?php if (!$byEmail): ?><tr><td colspan="4" class="empty">Sin envíos.</td></tr><?php endif ?>
        <?php foreach ($byEmail as $id => $n): $em = $names[$id] ?? null; ?>
          <tr>
            <td><?php if ($em): ?><a href="index.php?p=emails&a=stats&id=<?= $id ?>"><?= e($em['name']) ?></a><?php else: ?><span class="muted">(correo eliminado)</span><?php endif ?></td>
            <td class="num"><?= $n['sent'] ?><?= $n['failed'] ? ' <small class="badtxt">+' . $n['failed'] . ' fallidos</small>' : '' ?></td>
            <td class="num"><?= $n['opened'] ?> <small><?= $pct($n['opened'], $n['sent']) ?></small></td>
            <td class="num"><?= $n['converted'] ?> <small><?= $pct($n['converted'], $n['sent']) ?></small></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
  <div>
    <h2>Enlaces más pulsados</h2>
    <div class="card table">
      <table class="stattable">
        <thead><tr><th>Enlace</th><th>Personas</th><th>Clics</th></tr></thead>
        <tbody>
        <?php if (!$links): ?><tr><td colspan="3" class="empty">Sin clics en este periodo.</td></tr><?php endif ?>
        <?php foreach ($links as $u => $l): ?>
          <tr><td class="linkcell"><?php if (preg_match('#^https?://#', $u)): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('#^https?://(www\.)?#', '', $u)) ?></a><?php else: ?><?= e($u) ?><?php endif ?></td>
            <td class="num"><?= count($l['people']) ?></td><td class="num"><?= $l['clicks'] ?></td></tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<p class="muted small-note">
  <b>Abiertos</b> cuenta a quien cargó las imágenes del correo o hizo clic en él: algunos programas de correo bloquean las imágenes y otros (Apple Mail) las cargan solos, así que la apertura es orientativa.
  <b>Conversiones</b> son los envíos en los que se hizo clic en algún enlace o se respondió la encuesta del correo.
  <?php if ($clickSince !== ''): ?>Los clics se registran desde el <?= $fmt($clickSince) ?>.<?php else: ?>Los clics se registran en los correos enviados a partir de esta versión; los envíos anteriores solo tienen aperturas y respuestas.<?php endif ?>
  Los antivirus de algunas empresas abren los enlaces para revisarlos, lo que puede sumar algún clic que no hizo una persona.
</p>
<script>document.querySelectorAll('.stattable tr[data-href]').forEach(tr => tr.addEventListener('click', e => { if (!e.target.closest('a')) location.href = tr.dataset.href; }));</script>
<?php layout_end();
