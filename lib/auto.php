<?php
declare(strict_types=1);

/* Automatizaciones: campaña NPS periódica (envío → recordatorio → resultados) y generador de newsletter desde WordPress. */

/* =====================================================================
   NPS AUTOMÁTICO
   ===================================================================== */

function nps_config(): array
{
    return [
        'enabled' => setting('nps_enabled', '0') === '1',
        'dates' => setting('nps_dates', '15/01, 15/04, 15/07, 15/10'),
        'hour' => setting('nps_hour', '09:00'),
        'tag' => setting('nps_tag', ''),            // listado en español ('' = todos los suscritos)
        'tag_en' => setting('nps_tag_en', ''),      // listado en inglés ('' = no se envía en inglés)
        'template' => (int)setting('nps_template', '0'),
        'template_en' => (int)setting('nps_template_en', '0'),
        'reminder_days' => max(0, (int)setting('nps_reminder_days', '7')),
        'results_days' => max(1, (int)setting('nps_results_days', '7')),
        'reminder_prefix' => setting('nps_reminder_prefix', 'Recordatorio: '),
        'reminder_prefix_en' => setting('nps_reminder_prefix_en', 'Reminder: '),
        'admins' => setting('nps_admins', ''),
    ];
}

/** Convierte "15/01, 15/04…" en una lista ordenada de [día, mes]. Ignora lo que no sea una fecha válida. */
function nps_parse_dates(string $s): array
{
    $out = [];
    foreach (preg_split('/[,;\n]+/', $s) as $p) {
        if (preg_match('#^\s*(\d{1,2})\s*[/.\-]\s*(\d{1,2})\s*$#', $p, $m) && checkdate((int)$m[2], (int)$m[1], 2024))
            $out[sprintf('%02d-%02d', $m[2], $m[1])] = [(int)$m[1], (int)$m[2]];
    }
    ksort($out);
    return array_values($out);
}

/** Calendario de un año: [['label' => 'NPS-Q1-2027', 'at' => timestamp], …]. El periodo es la posición de la fecha en el año. */
function nps_schedule(int $year): array
{
    $c = nps_config();
    $hour = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $c['hour']) ? $c['hour'] : '09:00';
    $out = [];
    foreach (nps_parse_dates($c['dates']) as $i => [$d, $m]) {
        if (!checkdate($m, $d, $year)) $d = (int)date('t', mktime(0, 0, 0, $m, 1, $year)); // 29/02 en año no bisiesto
        $out[] = ['label' => 'NPS-Q' . ($i + 1) . '-' . $year, 'at' => strtotime(sprintf('%04d-%02d-%02d %s:00', $year, $m, $d, $hour))];
    }
    return $out;
}

function nps_upcoming(int $n = 4): array
{
    $out = [];
    $y = (int)date('Y');
    foreach ([$y, $y + 1, $y + 2] as $year)
        foreach (nps_schedule($year) as $s) if ($s['at'] > time() && count($out) < $n) $out[] = $s;
    return $out;
}

function nps_admin_emails(): array
{
    $list = array_filter(array_map('norm_email', preg_split('/[,;\s]+/', nps_config()['admins'])), 'valid_email');
    if (!$list) $list = array_column(all('SELECT email FROM users'), 'email');
    return array_values(array_unique($list));
}

/** Plantilla del idioma indicado. La inglesa tiene que usar la misma encuesta que la española para que los resultados se cuenten juntos. */
function nps_template_email(string $lang = 'es'): ?array
{
    $c = nps_config();
    if ($lang === 'en') {
        $es = nps_template_email('es');
        if (!$es) return null;
        $e = $c['template_en'] ? one('SELECT * FROM emails WHERE id = ? AND survey_id = ?', [$c['template_en'], $es['survey_id']]) : null;
        return $e ?: one("SELECT * FROM emails WHERE survey_id = ? AND lang = 'en' AND name NOT LIKE 'NPS-Q%' ORDER BY id LIMIT 1", [$es['survey_id']]);
    }
    $e = $c['template'] ? one("SELECT * FROM emails WHERE id = ? AND survey_id IS NOT NULL AND lang <> 'en'", [$c['template']]) : null;
    return $e ?: one("SELECT * FROM emails WHERE survey_id IS NOT NULL AND lang <> 'en' AND name NOT LIKE 'NPS-Q%' ORDER BY id LIMIT 1")
        ?: one("SELECT * FROM emails WHERE survey_id IS NOT NULL AND lang <> 'en' ORDER BY id LIMIT 1");
}

/**
 * Deja preparado el NPS en dos idiomas: traduce al inglés la encuesta de la plantilla (si aún no lo está)
 * y crea el correo «Encuesta NPS (en)» enlazado a esa misma encuesta. Se ejecuta al instalar y al actualizar.
 */
function nps_bilingual_setup(): void
{
    $es = nps_template_email('es');
    if (!$es) return;
    $survey = one('SELECT * FROM surveys WHERE id = ?', [$es['survey_id']]);
    if (!$survey) return;
    $known = [
        'Basándose en su experiencia, ¿con qué probabilidad recomendaría Bezares?' => 'Based on your experience, how likely are you to recommend Bezares?',
        '(Opcional) ¿Cuál es el principal motivo de su puntuación?' => '(Optional) What is the main reason for your score?',
        '¡Muchas gracias por su respuesta! Su opinión nos ayuda a seguir mejorando.' => 'Thank you very much for your answer! Your feedback helps us keep improving.',
    ];
    if (trim((string)($survey['thanks_en'] ?? '')) === '' && isset($known[trim((string)$survey['thanks'])]))
        q('UPDATE surveys SET thanks_en = ? WHERE id = ?', [$known[trim((string)$survey['thanks'])], $survey['id']]);
    foreach (all('SELECT * FROM questions WHERE survey_id = ?', [$survey['id']]) as $qq)
        if (trim((string)($qq['label_en'] ?? '')) === '' && isset($known[trim((string)$qq['label'])]))
            q('UPDATE questions SET label_en = ? WHERE id = ?', [$known[trim((string)$qq['label'])], $qq['id']]);
    if (one("SELECT id FROM emails WHERE name = ? OR (survey_id = ? AND lang = 'en')", ['Encuesta NPS (en)', $survey['id']])) return;
    $body = '<p>Hello,</p>'
        . '<p>At Bezares we work continuously to improve our products and the service we offer.</p>'
        . '<p>That is why we would like to know your opinion by answering a single question. Just click on a score:</p>'
        . '{{encuesta}}'
        . '<p>It will only take a few seconds and your answer will help us keep improving.</p>'
        . '<p>Thank you very much for your trust and for helping us do better.</p>'
        . '<p>Best regards,<br><strong>The Bezares Team</strong></p>';
    $id = insert('INSERT INTO emails (name, subject, preheader, theme, header_image, body, survey_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        ['Encuesta NPS (en)', "Bezares' NPS", 'Can you help us with one question?', $es['theme'], $es['header_image'], $body, $survey['id'], now(), now()]);
    q("UPDATE emails SET lang = 'en' WHERE id = ?", [$id]);
}

function unique_slug(string $base): string
{
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($base)), '-') ?: 'encuesta';
    $slug = $base;
    for ($i = 2; one('SELECT id FROM surveys WHERE slug = ?', [$slug]); $i++) $slug = "$base-$i";
    return $slug;
}

function clone_survey(int $id, string $title): int
{
    $s = one('SELECT * FROM surveys WHERE id = ?', [$id]);
    if (!$s) throw new RuntimeException('La encuesta de la plantilla ya no existe.');
    $new = insert('INSERT INTO surveys (slug, title, description, thanks, max_per_contact, active, is_public, created_at, title_en, description_en, thanks_en) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [unique_slug($title), $title, $s['description'], $s['thanks'], $s['max_per_contact'], 1, $s['is_public'], now(), $title, $s['description_en'] ?? null, $s['thanks_en'] ?? null]);
    q('UPDATE surveys SET notify_mode = ?, notify_max = ?, notify_es = ?, notify_en = ? WHERE id = ?',
        [(string)($s['notify_mode'] ?? ''), (int)($s['notify_max'] ?? 6), (string)($s['notify_es'] ?? ''), (string)($s['notify_en'] ?? ''), $new]);
    foreach (all('SELECT * FROM questions WHERE survey_id = ? ORDER BY position, id', [$id]) as $q)
        q('INSERT INTO questions (survey_id, position, type, label, options, is_required, label_en, options_en) VALUES (?,?,?,?,?,?,?,?)',
            [$new, $q['position'], $q['type'], $q['label'], $q['options'], $q['is_required'], $q['label_en'] ?? null, $q['options_en'] ?? null]);
    return $new;
}

function clone_email(array $e, string $name, ?int $surveyId, string $subjectPrefix = ''): int
{
    $new = insert('INSERT INTO emails (name, subject, preheader, theme, header_image, body, survey_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$name, $subjectPrefix . $e['subject'], $e['preheader'], $e['theme'], $e['header_image'], $e['body'], $surveyId, now(), now()]);
    q('UPDATE emails SET attachments = ?, lang = ? WHERE id = ?', [$e['attachments'] ?? null, lang_of($e['lang'] ?? 'es'), $new]);
    return $new;
}

/** Pone un correo en la cola para una lista de contactos. Devuelve el id del envío. */
function queue_send(int $emailId, array $contactIds, string $segment): int
{
    $sendId = insert('INSERT INTO sends (email_id, segment, total, created_at) VALUES (?,?,?,?)', [$emailId, $segment, count($contactIds), now()]);
    $st = db()->prepare("INSERT INTO queue (send_id, email_id, contact_id, status) VALUES (?, ?, ?, 'pending')");
    foreach ($contactIds as $cid) $st->execute([$sendId, $emailId, $cid]);
    return $sendId;
}

/** Contactos suscritos de cada idioma. Quien está en el listado inglés recibe el inglés; el resto del listado español, el español. */
function nps_audiences(): array
{
    $c = nps_config();
    $list = function (string $tag): array {
        $w = "status = 'subscribed'"; $p = [];
        if ($tag !== '') { $w .= ' AND tags LIKE ?'; $p[] = '%,' . mb_strtolower($tag) . ',%'; }
        return array_map('intval', array_column(all("SELECT id FROM contacts WHERE $w ORDER BY id", $p), 'id'));
    };
    $en = $c['tag_en'] !== '' ? $list($c['tag_en']) : [];
    $es = array_values(array_diff($list($c['tag']), $en));
    return ['es' => $es, 'en' => $en];
}

/**
 * Crea la encuesta del periodo (una sola, con su traducción) y un correo por idioma, y los pone en cola.
 * $base = momento desde el que se cuentan recordatorio y resultados.
 */
function nps_launch(string $label, ?int $base = null): array
{
    $c = nps_config();
    $base ??= time();
    if (one('SELECT id FROM nps_runs WHERE label = ?', [$label])) throw new RuntimeException("Ya existe una campaña $label.");
    $tpl = nps_template_email('es');
    if (!$tpl) throw new RuntimeException('No hay ningún correo con encuesta que sirva de plantilla.');
    $aud = nps_audiences();
    $tplEn = null;
    if ($aud['en']) {
        $tplEn = nps_template_email('en');
        if (!$tplEn) throw new RuntimeException('Hay un listado en inglés pero falta la plantilla en inglés (un correo en inglés enlazado a la misma encuesta que la plantilla en español).');
    }
    if (!$aud['es'] && !$aud['en']) throw new RuntimeException('Los listados de destinatarios están vacíos.');

    db()->beginTransaction();
    try {
        $surveyId = clone_survey((int)$tpl['survey_id'], $label);
        $emailId = clone_email($tpl, $label, $surveyId);
        $sendId = $aud['es'] ? queue_send($emailId, $aud['es'], $c['tag'] !== '' ? $c['tag'] : 'Todos los suscritos') : null;
        $emailEn = $sendEn = null;
        if ($aud['en']) {
            $emailEn = clone_email($tplEn, $label . ' (en)', $surveyId);
            $sendEn = queue_send($emailEn, $aud['en'], $c['tag_en']);
        }
        $rem = $c['reminder_days'] > 0 ? $base + $c['reminder_days'] * 86400 : null;
        $res = ($rem ?? $base) + $c['results_days'] * 86400;
        $runId = insert('INSERT INTO nps_runs (label, email_id, survey_id, send_id, tag, sent_at, reminder_at, results_at, reminder_done, results_done, note, email_en_id, send_en_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$label, $emailId, $surveyId, $sendId, trim(($c['tag'] !== '' ? $c['tag'] : 'todos') . ($aud['en'] ? ' + ' . $c['tag_en'] . ' (en)' : '')), now(),
                $rem ? date('Y-m-d H:i:s', $rem) : null, date('Y-m-d H:i:s', $res), $rem ? 0 : 2, 0, '', $emailEn, $sendEn]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return ['id' => $runId, 'label' => $label, 'recipients' => count($aud['es']) + count($aud['en']), 'es' => count($aud['es']), 'en' => count($aud['en'])];
}

/** Recordatorio, en el idioma de cada uno: a quienes recibieron el correo del periodo, siguen suscritos y no han respondido. */
function nps_remind(array $run): int
{
    $c = nps_config();
    $total = 0;
    $remId = null;
    db()->beginTransaction();
    foreach ([['email_id', 'send_id', $c['reminder_prefix'], ' (recordatorio)'], ['email_en_id', 'send_en_id', $c['reminder_prefix_en'], ' (en, recordatorio)']] as [$ek, $sk, $prefix, $suffix]) {
        if (empty($run[$ek]) || empty($run[$sk])) continue;
        $email = one('SELECT * FROM emails WHERE id = ?', [$run[$ek]]);
        if (!$email) continue;
        $ids = array_column(all("SELECT DISTINCT c.id FROM queue q JOIN contacts c ON c.id = q.contact_id
            WHERE q.send_id = ? AND q.status = 'sent' AND c.status = 'subscribed'
              AND c.email NOT IN (SELECT email FROM responses WHERE survey_id = ?) ORDER BY c.id", [$run[$sk], $run['survey_id']]), 'id');
        if (!$ids) continue;
        $id = clone_email($email, $run['label'] . $suffix, (int)$run['survey_id'], $prefix);
        queue_send($id, $ids, $ek === 'email_id' ? 'Recordatorio: sin respuesta' : 'Reminder: no answer');
        $remId ??= $id;
        $total += count($ids);
    }
    q('UPDATE nps_runs SET reminder_done = 1, reminder_email_id = ?, note = ? WHERE id = ?', [$remId, "Recordatorio a $total contacto(s)", $run['id']]);
    db()->commit();
    return $total;
}

/** Informe de resultados en HTML (para el correo a los administradores). */
function nps_results_html(array $run): array
{
    $survey = one('SELECT * FROM surveys WHERE id = ?', [$run['survey_id']]);
    if (!$survey) throw new RuntimeException('La encuesta de la campaña fue eliminada.');
    $qs = survey_stats($survey);
    $responses = (int)val('SELECT COUNT(*) FROM responses WHERE survey_id = ?', [$survey['id']]);
    $sent = (int)val("SELECT COUNT(DISTINCT contact_id) FROM queue WHERE send_id IN (?, ?) AND status = 'sent'", [(int)$run['send_id'], (int)($run['send_en_id'] ?? 0)]);
    $byLang = ['es' => 0, 'en' => 0];
    foreach (all('SELECT lang, COUNT(*) n FROM responses WHERE survey_id = ? GROUP BY lang', [$survey['id']]) as $r) $byLang[lang_of($r['lang'])] += (int)$r['n'];
    $color = brand_color();
    $f = 'font-family:Ubuntu, Helvetica, Arial, sans-serif;';
    $h = '<h1 style="' . $f . 'font-size:22px;color:' . $color . ';margin:0 0 6px;">Resultados ' . e($run['label']) . '</h1>'
        . '<p style="' . $f . 'font-size:14px;color:#555;margin:0 0 18px;">Enviado el ' . fecha($run['sent_at']) . ' a ' . $sent . ' contacto(s) · '
        . $responses . ' respuesta(s) · tasa de respuesta ' . ($sent ? round(min(100, $responses / $sent * 100)) . '%' : '—') . '</p>'
        . '';
    $sum = survey_lang_summary($survey, array_filter([(int)$run['send_id'], (int)($run['send_en_id'] ?? 0)]));
    if (!empty($run['send_en_id']) || $byLang['en']) {
        $td = 'style="padding:7px 10px;border-bottom:1px solid #dde3e8;' . $f . 'font-size:13px;color:#222;"';
        $th = 'style="padding:7px 10px;border-bottom:2px solid ' . $color . ';' . $f . 'font-size:11px;color:#667782;text-align:left;text-transform:uppercase;"';
        $h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 20px;border-collapse:collapse;"><tr>'
            . "<th $th>Resultados</th><th $th>Enviados</th><th $th>Respuestas</th><th $th>NPS</th><th $th>Prom.</th><th $th>Pas.</th><th $th>Detr.</th><th $th>Media</th></tr>";
        foreach ($sum as $lg => $row) {
            $q0 = $row['q'];
            $v = $q0['nps'] ?? null;
            $h .= '<tr><td ' . $td . '><b>' . e($row['label']) . "</b></td><td $td>{$row['sent']}</td><td $td>{$row['responses']}</td>"
                . "<td $td><b>" . ($v === null ? '—' : ($v > 0 ? '+' : '') . $v) . '</b></td>'
                . "<td $td>" . ($q0['promoters'] ?? '—') . "</td><td $td>" . ($q0['passives'] ?? '—') . "</td><td $td>" . ($q0['detractors'] ?? '—') . '</td>'
                . "<td $td>" . (($q0['avg'] ?? null) === null ? '—' : number_format($q0['avg'], 1, ',', '')) . '</td></tr>';
        }
        $h .= '</table><p style="' . $f . 'font-size:13px;color:#555;margin:0 0 6px;">Detalle del resultado general (español + inglés):</p>';
    }
    $nps = null;
    foreach ($qs as $q) {
        $h .= '<h2 style="' . $f . 'font-size:16px;color:#000;margin:18px 0 8px;">' . e($q['label']) . ' <span style="font-weight:normal;color:#777;font-size:12px;">(' . $q['n'] . ')</span></h2>';
        if ($q['type'] === 'nps') {
            $nps ??= $q['nps'];
            $n = max(1, $q['n']);
            $bg = $q['nps'] === null ? '#999999' : ($q['nps'] >= 50 ? '#2f9e5b' : ($q['nps'] >= 0 ? '#f0a020' : '#e5484d'));
            $h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;"><tr>'
                . '<td bgcolor="' . $bg . '" align="center" style="background:' . $bg . ';padding:14px 22px;border-radius:6px;' . $f . 'color:#ffffff;">'
                . '<span style="font-size:12px;">NPS</span><br><span style="font-size:34px;font-weight:bold;line-height:1.1;">'
                . ($q['nps'] === null ? '—' : ($q['nps'] > 0 ? '+' : '') . $q['nps']) . '</span></td>'
                . '<td style="padding-left:18px;' . $f . 'font-size:14px;line-height:1.7;color:#222;">'
                . 'Promotores (9–10): <b>' . $q['promoters'] . '</b> · ' . round($q['promoters'] / $n * 100) . '%<br>'
                . 'Pasivos (7–8): <b>' . $q['passives'] . '</b> · ' . round($q['passives'] / $n * 100) . '%<br>'
                . 'Detractores (0–6): <b>' . $q['detractors'] . '</b> · ' . round($q['detractors'] / $n * 100) . '%<br>'
                . 'Nota media: <b>' . ($q['avg'] === null ? '—' : number_format($q['avg'], 1, ',', '')) . '</b></td></tr></table>';
        }
        if (isset($q['dist'])) {
            $mx = max(1, max($q['dist'] ?: [0]));
            $h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="' . $f . 'font-size:13px;">';
            foreach ($q['dist'] as $k => $v) {
                $bar = $q['type'] === 'nps' ? ($k <= 6 ? '#e5484d' : ($k <= 8 ? '#f0a020' : '#2f9e5b')) : $color;
                $wpx = (int)round($v / $mx * 300);
                $h .= '<tr><td width="90" align="right" style="padding:2px 8px 2px 0;">' . e((string)$k) . '</td><td style="padding:2px 0;">'
                    . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="' . $bar . '" width="' . max(1, $wpx) . '" height="12" style="background:' . $bar
                    . ';font-size:1px;line-height:12px;">&nbsp;</td><td style="padding-left:8px;' . $f . 'font-size:13px;color:#555;">' . $v . '</td></tr></table></td></tr>';
            }
            $h .= '</table>';
        } else {
            $texts = array_slice(array_reverse($q['texts']), 0, 60);
            if (!$texts) $h .= '<p style="' . $f . 'font-size:13px;color:#777;margin:0;">Sin respuestas.</p>';
            foreach ($texts as $t) $h .= '<p style="' . $f . 'font-size:13px;color:#222;margin:0 0 6px;padding:6px 10px;background:#f4f6f8;border-left:3px solid #c9d3da;">' . nl2br(e($t)) . '</p>';
        }
    }
    $link = base_url() . '/index.php?p=surveys&a=results&id=' . $survey['id'];
    $h .= '<p style="' . $f . 'font-size:14px;margin:22px 0 0;"><a href="' . e($link) . '" style="color:' . $color . ';">Ver los resultados completos en el panel</a>. Se adjunta el detalle de las respuestas en Excel.</p>';
    $logo = logo_url();
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Resultados ' . e($run['label']) . '</title></head><body style="margin:0;padding:20px;background:#eef1f4;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;"><tr><td style="padding:26px 28px;">'
        . ($logo ? '<img src="' . e($logo) . '" alt="" width="200" style="display:block;width:200px;height:auto;border:0;margin:0 0 18px;">' : '') . $h
        . '</td></tr></table></body></html>';
    return ['html' => $html, 'nps' => $nps, 'responses' => $responses, 'survey' => $survey, 'questions' => $qs];
}

/** Excel con las respuestas, en un archivo temporal. Devuelve la ruta (o null si no hay extensión ZIP). */
function nps_results_xlsx(array $run, array $questions): ?string
{
    if (!function_exists('xlsx_write') || !class_exists('ZipArchive')) return null;
    $ans = [];
    foreach (all('SELECT a.* FROM answers a JOIN responses r ON r.id = a.response_id WHERE r.survey_id = ?', [$run['survey_id']]) as $x) $ans[$x['response_id']][$x['question_id']] = $x['value'];
    $rows = [array_merge(['fecha', 'email', 'nombre', 'empresa', 'idioma'], array_column($questions, 'label'))];
    foreach (all('SELECT r.*, c.nombre, c.apellidos, c.empresa FROM responses r LEFT JOIN contacts c ON c.id = r.contact_id WHERE r.survey_id = ? ORDER BY r.id', [$run['survey_id']]) as $r) {
        $row = [$r['created_at'], $r['email'], trim($r['nombre'] . ' ' . $r['apellidos']), (string)$r['empresa'], lang_of($r['lang'] ?? 'es')];
        foreach ($questions as $q) {
            $v = $ans[$r['id']][$q['id']] ?? '';
            $row[] = in_array($q['type'], ['nps', 'rating'], true) && $v !== '' ? (int)$v : $v;
        }
        $rows[] = $row;
    }
    $dir = sys_get_temp_dir() . '/bzm-' . token(6);
    if (!@mkdir($dir, 0700)) return null;
    $path = $dir . '/resultados-' . strtolower($run['label']) . '.xlsx';
    return file_put_contents($path, xlsx_write($rows, 'Respuestas')) ? $path : null;
}

function nps_send_results(array $run): int
{
    $rep = nps_results_html($run);
    $to = nps_admin_emails();
    if (!$to) throw new RuntimeException('No hay administradores a los que enviar los resultados.');
    $xlsx = nps_results_xlsx($run, $rep['questions']);
    $subject = 'Resultados ' . $run['label'] . ': NPS ' . ($rep['nps'] === null ? 'sin respuestas' : ($rep['nps'] > 0 ? '+' : '') . $rep['nps'])
        . ' (' . $rep['responses'] . ' respuestas)';
    $smtp = null;
    try {
        foreach ($to as $addr)
            deliver($addr, ['subject' => $subject, 'html' => $rep['html'], 'text' => html_to_text($rep['html']), 'attachments' => $xlsx ? [$xlsx] : []], $smtp);
    } finally {
        if ($smtp) $smtp->close();
        if ($xlsx) { @unlink($xlsx); @rmdir(dirname($xlsx)); }
    }
    q('UPDATE nps_runs SET results_done = 1, note = ? WHERE id = ?', ['Resultados enviados a ' . implode(', ', $to), $run['id']]);
    return count($to);
}

/** Se ejecuta desde el cron: lanza campañas, recordatorios y resultados cuando toca. Devuelve un registro de lo hecho. */
function automation_run(): array
{
    $log = [];
    $c = nps_config();
    $now = time();
    if ($c['enabled']) {
        // Margen de 5 días: si el cron estuvo parado, la campaña sale con retraso; pasado ese margen se salta el periodo.
        foreach ([(int)date('Y') - 1, (int)date('Y')] as $year) {
            foreach (nps_schedule($year) as $s) {
                if ($now < $s['at'] || $now > $s['at'] + 5 * 86400) continue;
                if (one('SELECT id FROM nps_runs WHERE label = ?', [$s['label']])) continue;
                try {
                    $r = nps_launch($s['label'], $s['at']);
                    $log[] = "{$s['label']}: campaña creada y en cola para {$r['recipients']} contacto(s) ({$r['es']} en español, {$r['en']} en inglés)";
                } catch (Throwable $e) {
                    $log[] = "{$s['label']}: ERROR al lanzar: " . $e->getMessage();
                    set_setting('nps_last_error', date('d/m/Y H:i') . " · {$s['label']}: " . $e->getMessage());
                }
            }
        }
    }
    $nowS = date('Y-m-d H:i:s', $now);
    // Los pasos de campañas ya lanzadas se completan aunque la automatización se desactive después
    foreach (all('SELECT * FROM nps_runs WHERE reminder_done = 0 AND reminder_at IS NOT NULL AND reminder_at <= ?', [$nowS]) as $run) {
        // Espera a que termine el envío inicial para no recordar a quien aún no lo ha recibido
        if ((int)val("SELECT COUNT(*) FROM queue WHERE send_id IN (?, ?) AND status IN ('pending','sending')", [(int)$run['send_id'], (int)($run['send_en_id'] ?? 0)]) > 0) continue;
        try { $log[] = "{$run['label']}: recordatorio en cola para " . nps_remind($run) . ' contacto(s)'; }
        catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            q('UPDATE nps_runs SET reminder_done = 2, note = ? WHERE id = ?', [mb_substr('Recordatorio no enviado: ' . $e->getMessage(), 0, 480), $run['id']]);
            $log[] = "{$run['label']}: ERROR en el recordatorio: " . $e->getMessage();
        }
    }
    foreach (all('SELECT * FROM nps_runs WHERE results_done = 0 AND results_at <= ?', [$nowS]) as $run) {
        try { $log[] = "{$run['label']}: resultados enviados a " . nps_send_results($run) . ' administrador(es)'; }
        catch (Throwable $e) {
            // No se marca como hecho: se reintenta en la siguiente ejecución (salvo que falte la encuesta)
            $fatal = !one('SELECT id FROM surveys WHERE id = ?', [$run['survey_id']]);
            q('UPDATE nps_runs SET results_done = ?, note = ? WHERE id = ?', [$fatal ? 2 : 0, mb_substr('Resultados no enviados: ' . $e->getMessage(), 0, 480), $run['id']]);
            $log[] = "{$run['label']}: ERROR al enviar resultados: " . $e->getMessage();
        }
    }
    return $log;
}

/* =====================================================================
   GENERADOR DE NEWSLETTER DESDE WORDPRESS
   ===================================================================== */

function wp_base(string $url): string
{
    $url = trim($url);
    if ($url === '') throw new RuntimeException('Indique la dirección del sitio WordPress.');
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    return rtrim($url, '/');
}

/** Primeros párrafos con texto de un HTML (sin imágenes, pies de foto ni bloques vacíos). */
function first_paragraphs(string $html, int $n = 2): array
{
    $clean = fn($t) => trim(preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode((string)$t, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $out = [];
    if (class_exists('DOMDocument') && trim($html) !== '') {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//p[not(ancestor::figure) and not(ancestor::blockquote[contains(@class,"wp-embedded")])]') as $p) {
            $t = $clean($p->textContent);
            if (mb_strlen($t) < 3 || preg_match('/^\[.*\]$/', $t)) continue;
            $out[] = $t;
            if (count($out) >= $n) break;
        }
    }
    if (!$out) { // sin etiquetas <p>: texto plano separado por saltos de línea
        foreach (preg_split('/\n\s*\n|<br\s*\/?>\s*<br\s*\/?>/i', $html) as $chunk) {
            $t = $clean(strip_tags($chunk));
            if (mb_strlen($t) < 3) continue;
            $out[] = $t;
            if (count($out) >= $n) break;
        }
    }
    return $out;
}

function first_image(string $html): string
{
    return preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
}

/** Últimas entradas de un WordPress: API REST y, si no está disponible, el canal RSS. */
function wp_fetch_posts(string $url, int $n): array
{
    $base = wp_base($url);
    $n = max(1, min(20, $n));
    $errors = [];
    foreach (["$base/wp-json/wp/v2/posts?per_page=$n&_embed=1", "$base/?rest_route=/wp/v2/posts&per_page=$n&_embed=1"] as $api) {
        try {
            $data = json_decode(http_download($api, 25), true);
            if (!is_array($data) || !isset($data[0]['link'])) throw new RuntimeException('respuesta no reconocida');
            $posts = [];
            foreach ($data as $p) {
                $content = (string)($p['content']['rendered'] ?? '');
                $img = (string)($p['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '');
                $sizes = $p['_embedded']['wp:featuredmedia'][0]['media_details']['sizes'] ?? [];
                foreach (['large', 'medium_large'] as $sz) if (!empty($sizes[$sz]['source_url'])) { $img = (string)$sizes[$sz]['source_url']; break; }
                $paras = first_paragraphs($content);
                if (!$paras) $paras = first_paragraphs((string)($p['excerpt']['rendered'] ?? ''));
                $posts[] = ['title' => trim(html_entity_decode(strip_tags((string)($p['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'link' => (string)$p['link'], 'image' => $img ?: first_image($content), 'paragraphs' => $paras];
            }
            return $posts;
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
    try {
        $xml = @simplexml_load_string(http_download("$base/feed/", 25), 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml || !isset($xml->channel->item)) throw new RuntimeException('canal RSS no reconocido');
        $posts = [];
        foreach ($xml->channel->item as $it) {
            $content = (string)$it->children('http://purl.org/rss/1.0/modules/content/')->encoded ?: (string)$it->description;
            $img = '';
            foreach ($it->enclosure as $en) if (str_starts_with((string)$en['type'], 'image/')) { $img = (string)$en['url']; break; }
            $posts[] = ['title' => trim(html_entity_decode((string)$it->title, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'link' => trim((string)$it->link),
                'image' => $img ?: first_image($content), 'paragraphs' => first_paragraphs($content) ?: first_paragraphs((string)$it->description)];
            if (count($posts) >= $n) break;
        }
        if ($posts) return $posts;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    throw new RuntimeException('No se pudieron leer las entradas de ' . $base . ' (' . implode('; ', array_unique($errors)) . ').');
}

/** Crea el correo de newsletter a partir de las últimas entradas. Devuelve [id del correo, nº de entradas, avisos]. */
function newsletter_generate(array $o): array
{
    $posts = wp_fetch_posts($o['url'], (int)$o['count']);
    if (!$posts) throw new RuntimeException('El sitio no tiene entradas publicadas.');
    $color = brand_color();
    $f = 'Ubuntu, Helvetica, Arial, sans-serif';
    $btn = $o['button'] !== '' ? $o['button'] : 'More info >';
    $warn = [];
    foreach ($posts as &$p) {
        if ($p['image'] !== '' && !empty($o['copy_images'])) {
            try { $p['image'] = media_url(media_fetch_url($p['image'])); }
            catch (Throwable $e) { $warn[] = 'No se pudo copiar la imagen de «' . $p['title'] . '» (se enlaza la original).'; }
        }
    }
    unset($p);
    $divider = '<div style="border-top:3px solid ' . $color . ';font-size:1px;line-height:1px;margin:4px 0 22px;">&nbsp;</div>';
    // Entrada grande: imagen a todo el ancho, titular, los dos primeros párrafos y botón
    $big = function (array $p) use ($color, $f, $btn) {
        $h = '<div>';
        if ($p['image'] !== '') $h .= '<a href="' . e($p['link']) . '" target="_blank" style="text-decoration:none;"><img src="' . e($p['image']) . '" alt="' . e($p['title'])
            . '" style="display:block;width:100%;height:auto;border:0;"></a>';
        $h .= '<h2 style="margin:12px 0 10px;font-family:Helvetica, sans-serif;font-size:24px;line-height:32px;"><a href="' . e($p['link'])
            . '" target="_blank" style="color:' . $color . ';">' . e($p['title']) . '</a></h2>';
        foreach ($p['paragraphs'] as $t) $h .= '<p style="margin:0 0 14px;font-family:' . $f . ';">' . e($t) . '</p>';
        return $h . block_button($btn, $p['link'], $color) . '</div>';
    };
    // Entrada pequeña (media columna): imagen, titular y un resumen corto del primer párrafo
    $small = function (array $p) use ($color, $f, $btn) {
        $text = (string)($p['paragraphs'][0] ?? '');
        if (mb_strlen($text) > 150) $text = rtrim(preg_replace('/\s+\S*$/u', '', mb_substr($text, 0, 150)), ' ,;:.') . '…';
        $h = '<td class="col2" width="50%" valign="top" style="width:50%;padding:0 8px 16px;font-family:' . $f . ';font-size:13px;line-height:1.5;">';
        if ($p['image'] !== '') $h .= '<a href="' . e($p['link']) . '" target="_blank" style="text-decoration:none;"><img src="' . e($p['image']) . '" alt="' . e($p['title'])
            . '" style="display:block;width:100%;height:auto;border:0;"></a>';
        return $h . '<p style="margin:10px 0;"><a href="' . e($p['link']) . '" target="_blank" style="color:' . $color . ';"><strong>' . e($p['title']) . '</strong></a></p>'
            . '<p style="margin:0 0 10px;">' . e($text) . '</p>' . block_button($btn, $p['link'], $color) . '</td>';
    };
    // Como el correo de ejemplo: las 2 primeras entradas grandes y el resto de dos en dos, lado a lado.
    // Si queda una suelta al final, va en grande.
    $body = '';
    $rest = array_slice($posts, 2);
    foreach (array_slice($posts, 0, 2) as $p) $body .= $big($p);
    if ($rest) $body .= $divider;
    for ($i = 0; $i < count($rest); $i += 2) {
        if (isset($rest[$i + 1])) {
            $body .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout:fixed;"><tr>'
                . $small($rest[$i]) . $small($rest[$i + 1]) . '</tr></table>';
        } else $body .= $big($rest[$i]);
    }
    // Cabecera: por defecto bezares-newsletter-2026-2.png. Si todavía está solo en el servidor antiguo, se copia a la biblioteca.
    $header = trim((string)$o['header_image']);
    if ($header === 'default' || $header === NEWSLETTER_HEADER_OLD) {
        $header = newsletter_default_header();
        if ($header === '') {
            try { $header = media_url(media_fetch_url(NEWSLETTER_HEADER_OLD)); }
            catch (Throwable $e) {
                $header = NEWSLETTER_HEADER_OLD;
                $warn[] = 'La cabecera ' . NEWSLETTER_HEADER . ' no está en la biblioteca y no se pudo copiar del servidor antiguo: súbala en Biblioteca con ese nombre.';
            }
        }
    }
    $name = trim($o['name']) !== '' ? trim($o['name']) : 'Newsletter ' . date('d/m/Y');
    $id = insert('INSERT INTO emails (name, subject, preheader, theme, header_image, body, survey_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$name, trim($o['subject']) !== '' ? trim($o['subject']) : $name, mb_strimwidth(implode(' · ', array_column($posts, 'title')), 0, 240, '…'),
            'newsletter', $header, $body, null, now(), now()]);
    q('UPDATE emails SET lang = ? WHERE id = ?', [lang_of($o['lang'] ?? 'es'), $id]);
    return [$id, count($posts), $warn];
}
