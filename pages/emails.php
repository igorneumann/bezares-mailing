<?php
$themes = themes();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$email = $id ? one('SELECT * FROM emails WHERE id = ?', [$id]) : null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

function audience_sql(array $email, array $o): array
{
    $w = ["c.status = 'subscribed'"]; $p = [];
    if (($o['tag'] ?? '') !== '') { [$tw, $tp] = tag_audience_sql($o['tag'], 'c'); $w[] = $tw; array_push($p, ...$tp); } // sin quienes se dieron de baja de ese listado
    if (!empty($o['skip_sent'])) {
        $w[] = "c.id NOT IN (SELECT contact_id FROM queue WHERE email_id = ? AND status IN ('sent','pending','sending'))";
        $p[] = $email['id'];
    }
    if (!empty($o['skip_answered']) && $email['survey_id']) {
        $w[] = 'c.email NOT IN (SELECT email FROM responses WHERE survey_id = ?)';
        $p[] = $email['survey_id'];
    }
    return [implode(' AND ', $w), $p];
}

/* ---------- Vista previa (HTML tal cual, para el iframe) ---------- */
if ($a === 'preview' && $email) {
    $r = render_email($email, sample_contact());
    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: sandbox allow-popups; script-src 'none'; frame-ancestors " . frame_ancestors());
    echo $r['html'];
    return;
}

/* ---------- Subida de imágenes para el editor ---------- */
if ($a === 'upload' && $isPost) {
    header('Content-Type: application/json');
    try {
        $name = media_store_upload($_FILES['image'] ?? [], ($_POST['kind'] ?? '') === 'file');
        echo json_encode(['url' => media_url($name), 'name' => $name]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

/* ---------- Procesar un lote de la cola (llamado por JavaScript) ---------- */
if ($a === 'process' && $isPost) {
    header('Content-Type: application/json');
    session_write_close();
    $res = process_queue();
    $sendId = (int)($_GET['send'] ?? 0);
    $res['stats'] = [];
    foreach (all('SELECT status, COUNT(*) n FROM queue WHERE send_id = ? GROUP BY status', [$sendId]) as $r) $res['stats'][$r['status']] = (int)$r['n'];
    echo json_encode($res);
    return;
}

/* ---------- Guardar ---------- */
if ($a === 'save' && $isPost) {
    $theme = isset($themes[$_POST['theme'] ?? '']) ? $_POST['theme'] : array_key_first($themes);
    $d = [trim($_POST['name'] ?? '') ?: 'Sin título', trim($_POST['subject'] ?? ''), trim($_POST['preheader'] ?? ''), $theme,
        trim($_POST['header_image'] ?? ''), (string)($_POST['body'] ?? ''), ((int)($_POST['survey_id'] ?? 0)) ?: null, now()];
    if ($email) q('UPDATE emails SET name=?, subject=?, preheader=?, theme=?, header_image=?, body=?, survey_id=?, updated_at=? WHERE id=?', [...$d, $id]);
    else $id = insert('INSERT INTO emails (name, subject, preheader, theme, header_image, body, survey_id, updated_at, created_at) VALUES (?,?,?,?,?,?,?,?,?)', [...$d, now()]);
    $att = email_attachments(['attachments' => json_encode(array_map('strval', (array)($_POST['attachments'] ?? [])))]);
    q('UPDATE emails SET attachments = ?, lang = ? WHERE id = ?', [$att ? json_encode($att) : null, lang_of($_POST['lang'] ?? 'es'), $id]);
    flash('Correo guardado.');
    redirect('index.php?p=emails&a=' . (($_POST['next'] ?? '') === 'send' ? 'send' : 'edit') . '&id=' . $id);
}
if ($a === 'delete' && $isPost && $email) {
    q('DELETE FROM emails WHERE id = ?', [$id]);
    q('DELETE FROM queue WHERE email_id = ?', [$id]);
    q('DELETE FROM sends WHERE email_id = ?', [$id]);
    flash('Correo eliminado.');
    redirect('index.php?p=emails');
}
if ($a === 'copy' && $isPost && $email) {
    $new = insert('INSERT INTO emails (name, subject, preheader, theme, header_image, body, survey_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$email['name'] . ' (copia)', $email['subject'], $email['preheader'], $email['theme'], $email['header_image'], $email['body'], $email['survey_id'], now(), now()]);
    q('UPDATE emails SET attachments = ?, lang = ? WHERE id = ?', [$email['attachments'] ?? null, lang_of($email['lang'] ?? 'es'), $new]);
    redirect('index.php?p=emails&a=edit&id=' . $new);
}

/* ---------- Envío de prueba ---------- */
if ($a === 'test' && $isPost && $email) {
    [$list, $bad] = test_recipients((string)($_POST['to'] ?? ''));
    $ok = []; $fail = [];
    $smtp = null;
    foreach ($list as $to) {
        try {
            // Si la dirección es de un contacto, la prueba lleva sus datos y sus enlaces personales
            $c = one('SELECT * FROM contacts WHERE email = ?', [$to]) ?? (['email' => $to] + sample_contact());
            $r = render_email($email, $c);
            $r['subject'] = '[PRUEBA] ' . $r['subject'];
            deliver($to, $r, $smtp);
            $ok[] = $to;
        } catch (Throwable $e) {
            $fail[] = "$to (" . $e->getMessage() . ')';
            $smtp = null;
        }
    }
    if ($smtp) $smtp->close();
    if ($ok) flash('Prueba enviada a ' . implode(', ', $ok) . '.');
    if ($fail) flash('No se pudo enviar la prueba a: ' . implode(' · ', $fail), 'err');
    if ($bad) flash('Direcciones no válidas, omitidas: ' . implode(', ', $bad), 'err');
    if (!$list && !$bad) flash('Indique al menos un correo para la prueba.', 'err');
    $_SESSION['test_to'] = implode(', ', $list);
    redirect('index.php?p=emails&a=send&id=' . $id);
}

/* ---------- Poner en cola ---------- */
if ($a === 'queue' && $isPost && $email) {
    $o = ['tag' => trim($_POST['tag'] ?? ''), 'skip_sent' => !empty($_POST['skip_sent']), 'skip_answered' => !empty($_POST['skip_answered'])];
    [$w, $p] = audience_sql($email, $o);
    $ids = array_column(all("SELECT c.id FROM contacts c WHERE $w ORDER BY c.id", $p), 'id');
    if (!$ids) { flash('No hay destinatarios para ese envío.', 'err'); redirect('index.php?p=emails&a=send&id=' . $id); }
    db()->beginTransaction();
    $sendId = insert('INSERT INTO sends (email_id, segment, total, created_at, tag) VALUES (?,?,?,?,?)', [$id, $o['tag'] ?: 'Todos los suscritos', count($ids), now(), mb_strtolower($o['tag'])]);
    $st = db()->prepare("INSERT INTO queue (send_id, email_id, contact_id, status) VALUES (?, ?, ?, 'pending')");
    foreach ($ids as $cid) $st->execute([$sendId, $id, $cid]);
    db()->commit();
    redirect('index.php?p=emails&a=progress&send=' . $sendId);
}

/* ---------- Progreso del envío ---------- */
if ($a === 'progress') {
    $send = one('SELECT s.*, e.name FROM sends s LEFT JOIN emails e ON e.id = s.email_id WHERE s.id = ?', [(int)($_GET['send'] ?? 0)]);
    if (!$send) redirect('index.php?p=emails');
    $fails = all("SELECT c.email, q.error FROM queue q LEFT JOIN contacts c ON c.id = q.contact_id WHERE q.send_id = ? AND q.status = 'failed' ORDER BY q.id LIMIT 200", [$send['id']]);
    layout_start('Envío', 'emails'); ?>
    <div class="head"><h1>Envío: <?= e($send['name']) ?> <small><?= e($send['segment']) ?> · <?= fecha($send['created_at']) ?></small></h1><a class="btn" href="index.php?p=emails">Volver a correos</a></div>
    <div class="card" id="progress" data-send="<?= (int)$send['id'] ?>" data-total="<?= (int)$send['total'] ?>">
      <div class="bar"><div class="fill" style="width:0"></div></div>
      <p class="big"><b data-k="sent">0</b> enviados · <b data-k="failed">0</b> fallidos · <b data-k="pending"><?= (int)$send['total'] ?></b> pendientes <span class="muted">de <?= (int)$send['total'] ?></span></p>
      <p id="pmsg" class="muted">Enviando… Mantenga esta página abierta. Si la cierra, el envío continuará con la tarea programada (cron), si la tiene configurada.</p>
    </div>
    <?php if ($fails): ?>
    <h2>Fallidos</h2>
    <div class="card table"><table><thead><tr><th>Correo</th><th>Error</th></tr></thead><tbody>
      <?php foreach ($fails as $f): ?><tr><td><?= e($f['email']) ?></td><td class="muted"><?= e($f['error']) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
    <?php endif ?>
    <?php layout_end(); return;
}

/* ---------- Estadísticas del correo: quién lo ha recibido y quién lo ha abierto ---------- */
if (($a === 'stats' || $a === 'statsexport') && $email) {
    $QS = ['sent' => 'Enviado', 'failed' => 'Fallido', 'pending' => 'En cola', 'sending' => 'En cola', 'skipped' => 'Omitido'];
    $all = all("SELECT q.id, q.status, q.error, q.sent_at, q.opened_at, q.clicked_at, q.clicks, q.send_id, c.email, c.nombre, c.apellidos, c.empresa, c.status cstatus, c.unsub_tags, s.tag stag
        FROM queue q LEFT JOIN contacts c ON c.id = q.contact_id LEFT JOIN sends s ON s.id = q.send_id WHERE q.email_id = ? ORDER BY q.id DESC", [$id]);
    $answered = [];
    if ($email['survey_id']) foreach (all('SELECT email, MIN(created_at) d FROM responses WHERE survey_id = ? GROUP BY email', [$email['survey_id']]) as $r) $answered[$r['email']] = $r['d'];
    $n = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0, 'answered' => 0, 'unsub' => 0];
    foreach ($all as &$r) {
        // Un clic también cuenta como apertura (muchos programas de correo bloquean el píxel de apertura)
        $r['opened'] = $r['status'] === 'sent' && ($r['opened_at'] || $r['clicked_at']);
        if ($r['opened'] && !$r['opened_at']) $r['opened_at'] = $r['clicked_at'];
        $r['clicked'] = $r['status'] === 'sent' && $r['clicked_at'];
        $r['answered'] = $r['status'] === 'sent' && isset($answered[$r['email'] ?? '']) ? $answered[$r['email']] : '';
        // Baja de este envío: general, o solo del listado al que se envió
        $r['listoff'] = $r['cstatus'] === 'subscribed' && (string)$r['stag'] !== '' && in_array((string)$r['stag'], tags_list((string)$r['unsub_tags']), true);
        if ($r['status'] === 'sent') { $n['sent']++; if ($r['opened']) $n['opened']++; if ($r['clicked']) $n['clicked']++; if ($r['answered']) $n['answered']++; if ($r['cstatus'] === 'unsubscribed' || $r['listoff']) $n['unsub']++; }
        elseif ($r['status'] === 'failed') $n['failed']++;
        elseif ($r['status'] === 'skipped') $n['skipped']++;
        else $n['pending']++;
    }
    unset($r);
    $views = ['all' => 'Todos', 'sent' => 'Recibido', 'opened' => 'Abierto', 'clicked' => 'Hizo clic', 'unopened' => 'Sin abrir', 'failed' => 'Fallido', 'pending' => 'En cola'];
    $view = isset($views[$_GET['v'] ?? '']) ? $_GET['v'] : 'all';
    $term = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $rows = array_values(array_filter($all, function ($r) use ($view, $term) {
        $ok = match ($view) {
            'sent' => $r['status'] === 'sent',
            'opened' => $r['opened'],
            'clicked' => $r['clicked'],
            'unopened' => $r['status'] === 'sent' && !$r['opened'],
            'failed' => $r['status'] === 'failed',
            'pending' => in_array($r['status'], ['pending', 'sending'], true),
            default => true,
        };
        if ($ok && $term !== '') $ok = str_contains(mb_strtolower(($r['email'] ?? '') . ' ' . $r['nombre'] . ' ' . $r['apellidos'] . ' ' . $r['empresa']), $term);
        return $ok;
    }));
    $count = ['all' => count($all), 'sent' => $n['sent'], 'opened' => $n['opened'], 'clicked' => $n['clicked'], 'unopened' => $n['sent'] - $n['opened'], 'failed' => $n['failed'], 'pending' => $n['pending']];

    if ($a === 'statsexport') {
        $out = [array_merge(['email', 'nombre', 'empresa', 'estado', 'enviado', 'abierto', 'fecha de apertura'], $email['survey_id'] ? ['respondió encuesta'] : [], ['error'])];
        foreach ($rows as $r) $out[] = array_merge([(string)($r['email'] ?? '(contacto eliminado)'), trim($r['nombre'] . ' ' . $r['apellidos']), (string)$r['empresa'],
            $QS[$r['status']] ?? $r['status'], $r['status'] === 'sent' ? (string)$r['sent_at'] : '', $r['status'] === 'sent' ? ($r['opened'] ? 'Sí' : 'No') : '', $r['opened'] ? (string)$r['opened_at'] : ''],
            $email['survey_id'] ? [$r['answered'] ? 'Sí' : ($r['status'] === 'sent' ? 'No' : '')] : [], [(string)$r['error']]);
        download_table($out, 'estadisticas-correo-' . $id . '-' . date('Ymd'), ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv');
    }

    $per = 100;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = count($rows);
    $rows = array_slice($rows, ($page - 1) * $per, $per);
    $pct = fn($x, $of) => $of ? round($x / $of * 100) . '%' : '—';
    $link = fn(array $o = []) => 'index.php?' . e(http_build_query(array_filter($o + ['p' => 'emails', 'a' => 'stats', 'id' => $id, 'v' => $view, 'q' => $term], fn($x) => $x !== '' && $x !== null)));
    layout_start('Estadísticas', 'emails'); ?>
    <div class="head"><h1>Estadísticas: <?= e($email['name']) ?> <small><?= e($email['subject']) ?></small></h1>
      <div class="actions">
        <a class="btn" href="<?= $link(['a' => 'statsexport', 'format' => 'csv']) ?>">Exportar CSV</a>
        <a class="btn" href="<?= $link(['a' => 'statsexport', 'format' => 'xlsx']) ?>">Exportar Excel</a>
        <a class="btn" href="index.php?p=emails&a=send&id=<?= $id ?>">Enviar</a>
        <a class="btn" href="index.php?p=emails">Volver</a>
      </div></div>
    <div class="kpis k4">
      <div class="card kpi"><span>Recibido (enviado correctamente)</span><b><?= $n['sent'] ?></b></div>
      <div class="card kpi"><span>Abierto</span><b><?= $n['opened'] ?> <small><?= $pct($n['opened'], $n['sent']) ?></small></b>
        <div class="bar"><div class="fill ok" style="width:<?= $n['sent'] ? $n['opened'] / $n['sent'] * 100 : 0 ?>%"></div></div></div>
      <div class="card kpi"><span>Hizo clic en un enlace</span><b><?= $n['clicked'] ?> <small><?= $pct($n['clicked'], $n['sent']) ?></small></b>
        <div class="bar"><div class="fill" style="width:<?= $n['sent'] ? $n['clicked'] / $n['sent'] * 100 : 0 ?>%"></div></div></div>
      <div class="card kpi"><span>Sin abrir</span><b><?= $n['sent'] - $n['opened'] ?> <small><?= $pct($n['sent'] - $n['opened'], $n['sent']) ?></small></b></div>
      <div class="card kpi"><span>Fallido<?= $n['pending'] ? ' · En cola' : '' ?></span><b><?= $n['failed'] ?><?= $n['pending'] ? ' · ' . $n['pending'] : '' ?></b></div>
      <?php if ($email['survey_id']): ?><div class="card kpi"><span>Respondió la encuesta</span><b><?= $n['answered'] ?> <small><?= $pct($n['answered'], $n['sent']) ?></small></b></div><?php endif ?>
      <div class="card kpi"><span>Se dio de baja después (de todo o de este listado)</span><b><?= $n['unsub'] ?></b></div>
    </div>
    <form class="filters" method="get">
      <input type="hidden" name="p" value="emails"><input type="hidden" name="a" value="stats"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="v" value="<?= e($view) ?>">
      <div class="seg"><?php foreach ($views as $k => $l): ?><a class="<?= $k === $view ? 'on' : '' ?>" href="<?= $link(['v' => $k, 'page' => null]) ?>"><?= $l ?> <i><?= $count[$k] ?></i></a><?php endforeach ?></div>
      <input type="search" name="q" value="<?= e($term) ?>" placeholder="Buscar por correo, nombre o empresa">
      <button class="btn">Buscar</button>
    </form>
    <div class="card table"><table>
      <thead><tr><th>Contacto</th><th>Empresa</th><th>Recibido</th><th>Abierto</th><?php if ($email['survey_id']): ?><th>Encuesta</th><?php endif ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['email'] !== null ? e($r['email']) : '<span class="muted">(contacto eliminado)</span>' ?>
            <?php if (trim($r['nombre'] . $r['apellidos']) !== ''): ?><br><span class="muted"><?= e(trim($r['nombre'] . ' ' . $r['apellidos'])) ?></span><?php endif ?>
            <?php if ($r['cstatus'] === 'unsubscribed'): ?> <span class="pill unsubscribed">Baja</span><?php elseif ($r['listoff']): ?> <span class="pill unsubscribed" title="Se dio de baja solo de este listado">Baja de «<?= e($r['stag']) ?>»</span><?php endif ?></td>
          <td><?= e($r['empresa']) ?></td>
          <td><?php if ($r['status'] === 'sent'): ?><span class="pill subscribed">✓ Recibido</span> <span class="muted nowrap"><?= fecha($r['sent_at']) ?></span>
              <?php elseif ($r['status'] === 'failed'): ?><span class="pill unsubscribed">✗ Fallido</span><br><span class="muted"><?= e($r['error']) ?></span>
              <?php elseif ($r['status'] === 'skipped'): ?><span class="pill">Omitido</span> <span class="muted"><?= e($r['error']) ?></span>
              <?php else: ?><span class="pill pending">En cola</span><?php endif ?></td>
          <td><?php if ($r['opened']): ?><span class="pill subscribed">✓ Abierto</span> <span class="muted nowrap"><?= fecha($r['opened_at']) ?></span>
              <?php elseif ($r['status'] === 'sent'): ?><span class="pill">Sin abrir</span><?php else: ?><span class="muted">—</span><?php endif ?></td>
          <?php if ($email['survey_id']): ?><td><?= $r['answered'] ? '<span class="pill subscribed">✓ Respondió</span> <span class="muted nowrap">' . fecha($r['answered']) . '</span>' : '<span class="muted">—</span>' ?></td><?php endif ?>
        </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty"><?= $all ? 'Ningún destinatario con ese filtro.' : 'Este correo todavía no se ha enviado a nadie.' ?></td></tr><?php endif ?>
      </tbody>
    </table></div>
    <div class="foot"><span class="muted"><?= $total ?> destinatario(s)</span>
      <span class="pager">
        <?php if ($page > 1): ?><a class="btn small" href="<?= $link(['page' => $page - 1]) ?>">← Anterior</a><?php endif ?>
        <?php if ($page * $per < $total): ?><a class="btn small" href="<?= $link(['page' => $page + 1]) ?>">Siguiente →</a><?php endif ?>
      </span></div>
    <p class="muted">«Abierto» se detecta con una imagen invisible: si el programa de correo del destinatario bloquea las imágenes, la apertura no se registra; y algunos (por ejemplo Apple Mail) cargan las imágenes automáticamente y pueden marcar aperturas que no lo son. Tómelo como una cifra orientativa.</p>
    <?php layout_end(); return;
}

/* ---------- Pantalla de envío ---------- */
if ($a === 'send' && $email) {
    $tags = all_tags();
    $all = (int)val("SELECT COUNT(*) FROM contacts WHERE status = 'subscribed'");
    $me = (string)val('SELECT email FROM users WHERE id = ?', [$_SESSION['uid']]);
    $history = all("SELECT s.*, (SELECT COUNT(*) FROM queue q WHERE q.send_id = s.id AND q.status = 'sent') sent,
        (SELECT COUNT(*) FROM queue q WHERE q.send_id = s.id AND q.status = 'failed') failed,
        (SELECT COUNT(*) FROM queue q WHERE q.send_id = s.id AND q.status IN ('pending','sending')) pending,
        (SELECT COUNT(*) FROM queue q WHERE q.send_id = s.id AND q.opened_at IS NOT NULL) opened
        FROM sends s WHERE s.email_id = ? ORDER BY s.id DESC", [$id]);
    $ready = valid_email(setting('from_email')) && (setting('driver') === 'mail' || setting('smtp_host') !== '');
    layout_start('Enviar', 'emails'); ?>
    <div class="head"><h1>Enviar: <?= e($email['name']) ?></h1>
      <div class="actions"><?php if (val('SELECT COUNT(*) FROM sends WHERE email_id = ?', [$id])): ?><a class="btn" href="index.php?p=emails&a=stats&id=<?= $id ?>">Estadísticas</a><?php endif ?><a class="btn" href="index.php?p=emails&a=edit&id=<?= $id ?>">Editar</a><a class="btn" href="index.php?p=emails">Volver</a></div></div>
    <?php if (!$ready): ?><div class="flash err">Antes de enviar, configure el remitente y el servidor de correo en <a href="index.php?p=settings">Ajustes</a>.</div><?php endif ?>
    <div class="split">
      <div>
        <form method="post" action="index.php?p=emails&a=test&id=<?= $id ?>" class="card form">
          <?= csrf_field() ?><h2>1. Enviar una prueba</h2>
          <label>Enviar a (uno o varios correos separados por comas)<input type="text" name="to" value="<?= e(($_SESSION['test_to'] ?? '') ?: $me) ?>" placeholder="uno@empresa.com, otro@empresa.com" required></label>
          <button class="btn">Enviar prueba</button>
        </form>
        <form method="post" action="index.php?p=emails&a=queue&id=<?= $id ?>" class="card form" onsubmit="return confirm('¿Enviar este correo a los destinatarios seleccionados?')">
          <?= csrf_field() ?><h2>2. Enviar a los contactos</h2>
          <label>Destinatarios
            <select name="tag"><option value="">Todos los suscritos (<?= $all ?>)</option>
              <?php $pre = (string)($_SESSION['presel'][$id] ?? ''); foreach ($tags as $t => $n): ?><option value="<?= e($t) ?>" <?= $pre === (string)$t ? 'selected' : '' ?>>Etiqueta: <?= e($t) ?> (<?= $n ?>)</option><?php endforeach ?>
            </select></label>
          <label class="check"><input type="checkbox" name="skip_sent" checked> No reenviar a quien ya recibió este correo</label>
          <?php if ($email['survey_id']): ?><label class="check"><input type="checkbox" name="skip_answered" checked> Excluir a quien ya respondió la encuesta</label><?php endif ?>
          <?php if ($att = email_attachments($email)): ?><p class="muted">Adjuntos: <b><?= e(implode(', ', $att)) ?></b> (<?= media_size(array_sum(array_map(fn($n) => filesize(media_dir() . '/' . $n), $att))) ?>).</p><?php endif ?>
          <p class="muted">Los contactos dados de baja nunca reciben correos. Al enviar a una etiqueta, se excluye a quien se dio de baja de ella y el enlace de baja del correo da de baja <b>solo de esa etiqueta</b>; al enviar a «Todos los suscritos», el enlace da de baja de todo.<?php $nOff = (int)val("SELECT COUNT(*) FROM contacts WHERE status = 'subscribed' AND unsub_tags <> ''"); if ($nOff): ?> <?= $nOff ?> contacto(s) se han dado de baja de algún listado: sí recibirán un envío a «Todos los suscritos».<?php endif ?></p>
          <button class="btn primary" <?= $ready ? '' : 'disabled' ?>>Enviar ahora</button>
        </form>
        <?php if ($history): ?>
        <h2>Envíos realizados</h2>
        <div class="card table"><table><thead><tr><th>Fecha</th><th>Destinatarios</th><th>Enviados</th><th>Abiertos</th><th>Fallidos</th><th></th></tr></thead><tbody>
          <?php foreach ($history as $h): ?><tr><td><?= fecha($h['created_at']) ?></td><td><?= e($h['segment']) ?> (<?= $h['total'] ?>)</td><td><?= $h['sent'] ?></td>
            <td><?= $h['opened'] ?></td><td><?= $h['failed'] ?></td>
            <td><a href="index.php?p=emails&a=progress&send=<?= $h['id'] ?>"><?= $h['pending'] ? "Continuar ({$h['pending']} pendientes)" : 'Detalle' ?></a></td></tr><?php endforeach ?>
        </tbody></table></div>
        <?php endif ?>
      </div>
      <div class="card preview"><iframe src="index.php?p=emails&a=preview&id=<?= $id ?>" title="Vista previa"></iframe></div>
    </div>
    <?php layout_end(); return;
}

/* ---------- Editor ---------- */
if ($a === 'edit') {
    $m = $email ?? ['id' => 0, 'name' => '', 'subject' => '', 'preheader' => '', 'theme' => 'comunicado', 'header_image' => '', 'survey_id' => null,
        'body' => '<p>Hola {{nombre}},</p><p>Escriba aquí su mensaje.</p>'];
    $surveys = all('SELECT id, title FROM surveys ORDER BY title');
    layout_start($m['id'] ? 'Editar correo' : 'Nuevo correo', 'emails'); ?>
    <form method="post" action="index.php?p=emails&a=save" id="emailform">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
    <div class="head"><h1><?= $m['id'] ? 'Editar correo' : 'Nuevo correo' ?></h1>
      <div class="actions">
        <button class="btn primary">Guardar</button>
        <button class="btn" name="next" value="send">Guardar y enviar…</button>
        <?php if ($m['id'] && val('SELECT COUNT(*) FROM sends WHERE email_id = ?', [(int)$m['id']])): ?><a class="btn" href="index.php?p=emails&a=stats&id=<?= (int)$m['id'] ?>">Estadísticas</a><?php endif ?>
        <a class="btn" href="index.php?p=emails">Volver</a>
      </div></div>
    <div class="split editing">
      <div>
        <div class="card form">
          <div class="row2">
            <label>Nombre interno<input name="name" value="<?= e($m['name']) ?>" required></label>
            <label>Tema (diseño)<select name="theme"><?php foreach ($themes as $k => $l): ?><option value="<?= e($k) ?>" <?= $m['theme'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></label>
          </div>
          <div class="row2 r31">
            <label>Asunto<input name="subject" value="<?= e($m['subject']) ?>" required></label>
            <label>Idioma del correo<select name="lang"><option value="es">Español</option><option value="en" <?= ($m['lang'] ?? 'es') === 'en' ? 'selected' : '' ?>>English</option></select></label>
          </div>
          <label>Texto de vista previa (aparece junto al asunto en la bandeja de entrada)<input name="preheader" value="<?= e($m['preheader']) ?>"></label>
          <div class="row2">
            <label>Imagen de cabecera (opcional; vacío = logo, o <code>encabezado-bezares.jpg</code> en el tema Comunicado)
              <span class="withbtn"><input name="header_image" id="header_image" value="<?= e($m['header_image']) ?>" placeholder="Elija una imagen de la biblioteca"><button type="button" class="btn" data-pick="header_image">Elegir…</button></span></label>
            <label>Encuesta enlazada<select name="survey_id"><option value="">— Ninguna —</option><?php foreach ($surveys as $s): ?><option value="<?= $s['id'] ?>" <?= (int)$m['survey_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['title']) ?></option><?php endforeach ?></select></label>
          </div>
          <div class="attach" id="attach">
            <span class="plabel">Archivos adjuntos</span>
            <ul class="attlist"><?php foreach (email_attachments($m) as $n): ?>
              <li><input type="hidden" name="attachments[]" value="<?= e($n) ?>"><span class="docicon mini <?= e(strtolower(pathinfo($n, PATHINFO_EXTENSION))) ?>"><?= e(strtoupper(pathinfo($n, PATHINFO_EXTENSION))) ?></span>
                <a href="<?= e(media_url($n)) ?>" target="_blank"><?= e($n) ?></a> <span class="muted" data-size="<?= filesize(media_dir() . '/' . $n) ?>"><?= media_size(filesize(media_dir() . '/' . $n)) ?></span>
                <button type="button" class="btn small danger" data-unattach title="Quitar adjunto">×</button></li>
            <?php endforeach ?></ul>
            <button type="button" class="btn" data-attach>+ Adjuntar desde la biblioteca…</button>
            <p class="muted attwarn" hidden>Los adjuntos suman más de 5 MB: muchos servidores rechazan o marcan como spam los envíos masivos pesados. Si puede, enlace el documento en lugar de adjuntarlo.</p>
          </div>
        </div>
        <div class="card editor" data-brand="<?= brand_color() ?>">
          <div class="toolbar">
            <span class="tlabel">Añadir:</span>
            <button type="button" data-add="text">Texto</button>
            <button type="button" data-add="title">Título</button>
            <button type="button" data-add="image">Imagen</button>
            <button type="button" data-add="button">Botón</button>
            <button type="button" data-add="article">Artículo</button>
            <button type="button" data-add="columns">2 columnas</button>
            <button type="button" data-add="divider">Separador</button>
            <button type="button" data-add="callout">Destacado</button>
            <button type="button" data-add="news">Noticia</button>
            <button type="button" data-add="news2">2 noticias</button>
            <button type="button" data-add="stats">Cifras</button>
            <button type="button" data-add="hero">Cabecera oferta</button>
            <button type="button" data-add="products">2 productos</button>
            <button type="button" data-add="coupon">Cupón</button>
            <button type="button" data-add="event">Datos del evento</button>
            <button type="button" data-add="survey">Encuesta</button>
            <select data-act="var"><option value="">Campo del contacto…</option><option>{{nombre}}</option><option>{{apellidos}}</option><option>{{empresa}}</option><option>{{email}}</option></select>
            <span class="right">
              <button type="button" data-act="undo" title="Deshacer">↶ Deshacer</button>
              <button type="button" data-act="redo" title="Rehacer">↷</button>
              <button type="button" data-act="source" title="Editar el código HTML">&lt;/&gt;</button>
            </span>
          </div>
          <div class="canvaswrap"><div class="canvas" contenteditable="true"></div><div class="selmark" hidden></div></div>
          <textarea name="body" class="source" hidden><?= e($m['body']) ?></textarea>
          <input type="file" accept="image/*" data-accept-file=".pdf,.doc,.docx,.xls,.xlsx,image/*" hidden>
        </div>
        <p class="muted">Haga clic en cualquier elemento del correo para cambiarlo en el panel de la derecha. En los temas «Newsletter» y «Moderno · Newsletter», el índice de titulares del principio se crea solo a partir de los títulos de las noticias (se ve en la vista previa). En los temas «Moderno», el logo, la fecha y las redes los pone el tema, y la imagen de cabecera es la del campo de arriba. El pie con los datos de la empresa y el <b>enlace para darse de baja</b> se añade siempre automáticamente. Si hay una encuesta enlazada y no coloca <code>{{encuesta}}</code>, las notas se añaden al final del texto.</p>
      </div>
      <div class="side">
        <div class="card props" id="props"></div>
        <details class="card preview" <?= $m['id'] ? '' : 'hidden' ?>>
          <summary>Vista previa con el tema (se actualiza al guardar)</summary>
          <?php if ($m['id']): ?><iframe src="index.php?p=emails&a=preview&id=<?= (int)$m['id'] ?>" title="Vista previa" loading="lazy"></iframe><?php endif ?>
        </details>
      </div>
    </div>
    </form>
    <dialog id="picker" class="wide">
      <div class="head"><h2 data-pick-title>Biblioteca de imágenes</h2><div class="actions"><button type="button" class="btn primary" data-pick-upload>Subir…</button><button type="button" class="btn" data-close>Cerrar</button></div></div>
      <div class="pickgrid"></div>
    </dialog>
    <?php if ($m['id']): ?>
    <form method="post" action="index.php?p=emails&a=delete&id=<?= (int)$m['id'] ?>" onsubmit="return confirm('¿Eliminar este correo?')" class="foot"><?= csrf_field() ?><button class="btn danger small">Eliminar correo</button></form>
    <?php endif ?>
    <?php layout_end(); return;
}

/* ---------- Listado: pestañas Por enviar · En cola · Enviados (archivados por mes) · Todos ---------- */
$rows = all("SELECT e.*, s.title survey,
    (SELECT COUNT(*) FROM queue q WHERE q.email_id = e.id AND q.status = 'sent') sent,
    (SELECT COUNT(*) FROM queue q WHERE q.email_id = e.id AND q.opened_at IS NOT NULL) opened,
    (SELECT COUNT(*) FROM queue q WHERE q.email_id = e.id AND q.status IN ('pending','sending')) pending,
    (SELECT MAX(q.sent_at) FROM queue q WHERE q.email_id = e.id AND q.status = 'sent') last_sent,
    (SELECT MAX(d.created_at) FROM sends d WHERE d.email_id = e.id) last_send
    FROM emails e LEFT JOIN surveys s ON s.id = e.survey_id ORDER BY e.updated_at DESC, e.id DESC");
$tabs = ['pend' => [], 'cola' => [], 'env' => [], 'all' => $rows];
foreach ($rows as $r) $tabs[$r['pending'] ? 'cola' : ($r['sent'] ? 'env' : 'pend')][] = $r;
// Enviados: del último envío al más antiguo
$sentAt = fn($r) => (string)($r['last_sent'] ?: $r['last_send']);
usort($tabs['env'], fn($a, $b) => strcmp($sentAt($b), $sentAt($a)) ?: $b['id'] <=> $a['id']);
// Pestaña: la pedida, si no la última usada; al principio, «Por enviar» si hay algo pendiente y si no «Enviados»
$tab = (string)($_GET['t'] ?? $_SESSION['emails_tab'] ?? '');
if (!isset($tabs[$tab])) $tab = $tabs['pend'] ? 'pend' : 'env';
$_SESSION['emails_tab'] = $tab;
$labels = ['pend' => 'Por enviar', 'cola' => 'En cola', 'env' => 'Enviados', 'all' => 'Todos'];
$heads = [
    'pend' => ['Asunto', 'Tema', 'Encuesta', 'Modificado'],
    'cola' => ['Asunto', 'Enviados', 'En cola', 'Empezó'],
    'env'  => ['Asunto', 'Último envío', 'Enviados', 'Abiertos'],
    'all'  => ['Asunto', 'Estado', 'Enviados', 'Abiertos'],
];
$pct = fn($r) => $r['sent'] ? round($r['opened'] / $r['sent'] * 100) . '%' : '—';
$state = fn($r) => $r['pending'] ? '<span class="pill pending">En cola</span>'
    : ($r['sent'] ? '<span class="pill subscribed">Enviado</span>'
    : ($r['last_send'] ? '<span class="pill unsubscribed" title="Se intentó enviar y no salió ninguno">Fallido</span>' : '<span class="pill">Borrador</span>'));
$cells = function (array $r) use ($tab, $themes, $pct, $state, $sentAt): string {
    $c = match ($tab) {
        'pend' => [e($themes[$r['theme']] ?? $r['theme']), e($r['survey'] ?? '—'),
            '<span class="nowrap">' . e(fecha($r['updated_at'])) . '</span>' . ($r['last_send'] ? ' ' . $state($r) : '')],
        'cola' => [(string)$r['sent'], '<span class="pill pending">' . $r['pending'] . ' en cola</span>', '<span class="nowrap">' . e(fecha($r['last_send'])) . '</span>'],
        'env'  => ['<span class="nowrap">' . e(fecha($sentAt($r))) . '</span>', (string)$r['sent'], $r['opened'] . ' <span class="muted">(' . $pct($r) . ')</span>'],
        default => [$state($r), (string)$r['sent'], $r['sent'] ? $r['opened'] . ' <span class="muted">(' . $pct($r) . ')</span>' : '—'],
    };
    $id = (int)$r['id'];
    $name = '<a href="index.php?p=emails&a=edit&id=' . $id . '"><b>' . e($r['name']) . '</b></a>' . (($r['lang'] ?? '') === 'en' ? ' <span class="tag">EN</span>' : '');
    $csrf = csrf_field();
    $ask = '¿Eliminar el correo «' . addslashes($r['name']) . '»?' . ($r['sent'] ? ' Se perderán también sus estadísticas de envío.' : '');
    // Los ya enviados (o en cola) no llevan «Enviar»: para mandarlo otra vez, «Duplicar»
    $act = ($r['sent'] || $r['pending'] ? '' : '<a class="btn small primary" href="index.php?p=emails&a=send&id=' . $id . '">Enviar</a> ')
        . ($r['sent'] || $r['pending'] || $r['last_send'] ? '<a class="btn small" href="index.php?p=emails&a=stats&id=' . $id . '">Estadísticas</a> ' : '') // sin enviar no hay estadísticas (los fallidos sí, con el motivo)
        . '<form method="post" action="index.php?p=emails&a=copy&id=' . $id . '" class="inline">' . $csrf . '<button class="btn small">Duplicar</button></form> '
        . '<form method="post" action="index.php?p=emails&a=delete&id=' . $id . '" class="inline" onsubmit="return confirm(\'' . e($ask) . '\')">' . $csrf . '<button class="btn small danger">Eliminar</button></form>';
    return '<tr><td>' . $name . '</td><td>' . e($r['subject']) . '</td><td>' . implode('</td><td>', $c) . '</td><td class="nowrap">' . $act . '</td></tr>';
};
$months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$empty = [
    'pend' => 'No hay correos por enviar. <a href="index.php?p=emails&a=edit">Crear uno nuevo</a>.',
    'cola' => 'No hay ningún envío en curso.',
    'env'  => 'Todavía no se ha enviado ningún correo.',
    'all'  => 'Todavía no hay correos.',
];
layout_start('Correos', 'emails'); ?>
<div class="head"><h1>Correos</h1><a class="btn primary" href="index.php?p=emails&a=edit">+ Nuevo correo</a></div>
<div class="mediabar"><div class="seg">
  <?php foreach ($labels as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="index.php?p=emails&t=<?= $k ?>"><?= $l ?> <i><?= count($tabs[$k]) ?></i></a><?php endforeach ?>
</div></div>
<div class="card table"><table class="emaillist">
  <thead><tr><th>Nombre</th><th><?= implode('</th><th>', $heads[$tab]) ?></th><th></th></tr></thead>
  <?php if (!$tabs[$tab]): ?>
    <tbody><tr><td colspan="6" class="empty"><?= $empty[$tab] ?></td></tr></tbody>
  <?php elseif ($tab === 'env'):
      // Archivados por mes: el más reciente abierto y el resto plegados, con un resumen de cada mes
      $groups = [];
      foreach ($tabs['env'] as $r) $groups[substr($sentAt($r), 0, 7)][] = $r;
      $first = true;
      foreach ($groups as $ym => $list):
          $sent = array_sum(array_column($list, 'sent'));
          $opened = array_sum(array_column($list, 'opened'));
          $label = preg_match('/^(\d{4})-(\d{2})$/', (string)$ym, $mm) ? $months[(int)$mm[2]] . ' ' . $mm[1] : 'Sin fecha'; ?>
    <tbody class="month<?= $first ? '' : ' closed' ?>">
      <tr class="monthhead"><td colspan="6"><button type="button" aria-expanded="<?= $first ? 'true' : 'false' ?>" onclick="var b=this.closest('tbody');b.classList.toggle('closed');this.setAttribute('aria-expanded',!b.classList.contains('closed'))">
        <span class="chev" aria-hidden="true"></span><b><?= e($label) ?></b>
        <span class="muted"><?= count($list) ?> correo(s) · <?= $sent ?> enviados · <?= $sent ? round($opened / $sent * 100) : 0 ?>% abiertos</span></button></td></tr>
      <?php foreach ($list as $r) echo $cells($r) ?>
    </tbody>
  <?php $first = false; endforeach; else: ?>
    <tbody><?php foreach ($tabs[$tab] as $r) echo $cells($r) ?></tbody>
  <?php endif ?>
</table></div>
<?php layout_end();
