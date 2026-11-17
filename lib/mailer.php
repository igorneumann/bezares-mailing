<?php
declare(strict_types=1);
require_once __DIR__ . '/smtp.php';

/* ---------- Temas ---------- */

function themes(): array
{
    $out = [];
    foreach (glob(ROOT . '/themes/*.html') as $f) {
        $slug = basename($f, '.html');
        $head = file_get_contents($f, false, null, 0, 200);
        $out[$slug] = preg_match('/<!--\s*Tema:\s*(.+?)\s*-->/u', $head, $m) ? $m[1] : ucfirst($slug);
    }
    asort($out);
    return $out;
}
function theme_html(string $slug): string
{
    $f = ROOT . '/themes/' . basename($slug) . '.html';
    if (!is_file($f)) $f = ROOT . '/themes/comunicado.html';
    return file_get_contents($f);
}

function sample_contact(): array
{
    return ['id' => 0, 'email' => 'maria.garcia@ejemplo.com', 'nombre' => 'María', 'apellidos' => 'García',
        'empresa' => 'Empresa Ejemplo', 'token' => 'demo', 'status' => 'subscribed'];
}

/* ---------- Idiomas (español / inglés) ---------- */

function lang_of($v): string { return $v === 'en' ? 'en' : 'es'; }

/** Campo traducido de una encuesta o pregunta; si falta la traducción se usa el español. */
function L(array $row, string $field, string $lang): string
{
    $en = trim((string)($row[$field . '_en'] ?? ''));
    return $lang === 'en' && $en !== '' ? $en : (string)($row[$field] ?? '');
}

/** Textos fijos de los correos y de las páginas públicas. */
function tr(string $key, string $lang): string
{
    static $t = [
        'low_nps' => ['Nada probable', 'Not at all likely'], 'high_nps' => ['Muy probable', 'Extremely likely'],
        'low_rating' => ['Muy mal', 'Very bad'], 'high_rating' => ['Muy bien', 'Very good'],
        'take_survey' => ['Responder a la encuesta', 'Take the survey'],
        'view_browser' => ['Ver este correo en el navegador', 'View this email in your browser'],
        'view_problems' => ['¿Problemas visualizando este correo? Haga clic aquí.', 'Having trouble viewing this email? Click here.'],
        'email' => ['Correo electrónico', 'Email address'], 'email_ph' => ['nombre@empresa.com', 'name@company.com'],
        'send' => ['Enviar', 'Submit'], 'required' => ['Esta pregunta es obligatoria.', 'This question is required.'],
        'bad_email' => ['Indique un correo electrónico válido.', 'Please enter a valid email address.'],
        'already' => ['Ya hemos registrado su respuesta a esta encuesta. ¡Muchas gracias por su participación!', 'We have already recorded your answer to this survey. Thank you very much for taking part!'],
        'closed' => ['Esta encuesta ya está cerrada. Gracias por su interés.', 'This survey is now closed. Thank you for your interest.'],
        'not_found_t' => ['Encuesta no encontrada', 'Survey not found'],
        'not_found' => ['El enlace no es correcto o la encuesta ya no existe.', 'The link is not correct or the survey no longer exists.'],
        'need_link' => ['Para responder a esta encuesta utilice el enlace personal que ha recibido por correo.', 'To answer this survey, please use the personal link you received by email.'],
        'thanks' => ['¡Gracias por su respuesta!', 'Thank you for your answer!'],
        'unsub_t' => ['Darse de baja', 'Unsubscribe'],
        'unsub_bad' => ['El enlace no es válido o ya no existe. Si desea dejar de recibir nuestros correos, responda a cualquiera de ellos indicándolo.', 'The link is not valid or no longer exists. If you no longer wish to receive our emails, please reply to any of them to let us know.'],
        'unsub_ask' => ['¿Desea que %s deje de recibir nuestros correos?', 'Do you want %s to stop receiving our emails?'],
        'unsub_yes' => ['Sí, darme de baja', 'Yes, unsubscribe me'],
        'unsub_done_t' => ['Baja confirmada', 'Unsubscribed'],
        'unsub_done' => ['%s ya no recibirá más correos nuestros.', '%s will no longer receive our emails.'],
        'unsub_already' => ['%s ya está dado de baja.', '%s is already unsubscribed.'],
        'resub' => ['Volver a suscribirme', 'Subscribe me again'],
        'resub_mistake' => ['Me he dado de baja por error: volver a suscribirme', 'I unsubscribed by mistake: subscribe me again'],
        'resub_t' => ['Suscripción reactivada', 'Subscription reactivated'],
        'resub_done' => ['Volverá a recibir nuestras comunicaciones en %s.', 'You will receive our communications again at %s.'],
    ];
    return $t[$key][$lang === 'en' ? 1 : 0] ?? $key;
}

/* ---------- Encuesta dentro del correo ---------- */

function survey_url(array $survey, array $contact, array $extra = []): string
{
    $p = ['s' => $survey['slug'], 'c' => $contact['token']] + $extra;
    return base_url() . '/s.php?' . http_build_query($p);
}

/** Bloque con la escala (0-10 o 1-5) en la que cada nota es un enlace que abre la encuesta ya rellenada. */
function survey_block(array $survey, array $contact, string $lang = 'es'): string
{
    $color = brand_color();
    $font = 'Ubuntu, Helvetica, Arial, sans-serif';
    $q = one("SELECT * FROM questions WHERE survey_id = ? AND type IN ('nps','rating') ORDER BY position, id LIMIT 1", [$survey['id']]);
    if (!$q) {
        return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:10px auto;"><tr>'
            . '<td bgcolor="' . $color . '" style="border-radius:3px;"><a href="' . e(survey_url($survey, $contact, $lang === 'en' ? ['lang' => 'en'] : []))
            . '" target="_blank" style="display:inline-block;padding:11px 26px;color:#ffffff;font-family:' . $font
            . ';font-size:14px;font-weight:bold;text-decoration:none;">' . tr('take_survey', $lang) . '</a></td></tr></table>';
    }
    [$min, $max] = $q['type'] === 'nps' ? [0, 10] : [1, 5];
    $cells = '';
    $w = $max - $min === 10 ? 9 : 20;
    for ($i = $min; $i <= $max; $i++) {
        if ($q['type'] === 'nps') $bg = $i <= 6 ? '#e5484d' : ($i <= 8 ? '#f0a020' : '#2f9e5b');
        else $bg = $i <= 2 ? '#e5484d' : ($i === 3 ? '#f0a020' : '#2f9e5b');
        $url = survey_url($survey, $contact, ['q' . $q['id'] => $i] + ($lang === 'en' ? ['lang' => 'en'] : []));
        // El color va en la celda (bgcolor), no en el enlace: Outlook ignora display:block y el alto de línea en los enlaces
        $cells .= '<td width="' . $w . '%" align="center" valign="middle" style="width:' . $w . '%;padding:2px;">'
            . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>'
            . '<td align="center" valign="middle" bgcolor="' . $bg . '" height="38" style="background:' . $bg . ';height:38px;border-radius:4px;'
            . 'font-family:' . $font . ';font-size:15px;font-weight:bold;line-height:38px;mso-line-height-rule:exactly;color:#ffffff;text-align:center;">'
            . '<a href="' . e($url) . '" target="_blank" style="display:block;color:#ffffff;font-family:' . $font
            . ';font-size:15px;font-weight:bold;line-height:38px;text-decoration:none;"><span style="color:#ffffff;">' . $i . '</span></a>'
            . '</td></tr></table></td>';
    }
    $n = $max - $min + 1;
    $low = tr($q['type'] === 'nps' ? 'low_nps' : 'low_rating', $lang);
    $high = tr($q['type'] === 'nps' ? 'high_nps' : 'high_rating', $lang);
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:14px 0;table-layout:fixed;">'
        . '<tr><td colspan="' . $n . '" style="padding:0 2px 10px;font-family:' . $font . ';font-size:16px;font-weight:bold;color:#000000;line-height:1.4;">'
        . e(L($q, 'label', $lang)) . '</td></tr><tr>' . $cells . '</tr>'
        . '<tr><td colspan="' . intdiv($n, 2) . '" style="padding:6px 2px 0;font-family:' . $font . ';font-size:11px;color:#6d6d6d;text-align:left;">' . $low . '</td>'
        . '<td colspan="' . ($n - intdiv($n, 2)) . '" style="padding:6px 2px 0;font-family:' . $font . ';font-size:11px;color:#6d6d6d;text-align:right;">' . $high . '</td></tr></table>';
}

/* ---------- Bloques de contenido (artículo, botón, columnas) ---------- */

function block_button(string $text, string $url, string $color): string
{
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:6px 0 22px;"><tr>'
        . '<td align="center" bgcolor="' . $color . '" style="background:' . $color . ';border-radius:3px;">'
        . '<a href="' . e($url) . '" target="_blank" style="display:block;padding:10px 25px;color:#ffffff;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:13px;text-decoration:none;">'
        . e($text) . '</a></td></tr></table>';
}
function block_article(string $title, string $url, string $img, string $text, string $color, string $btn = 'More info >'): string
{
    return '<a href="' . e($url) . '" target="_blank" style="text-decoration:none;"><img src="' . e($img) . '" alt="' . e($title)
        . '" width="550" style="display:block;width:100%;height:auto;border:0;"></a>'
        . '<h2 style="margin:12px 0 10px;font-family:Helvetica, sans-serif;font-size:24px;line-height:32px;"><a href="' . e($url)
        . '" target="_blank" style="color:' . $color . ';">' . e($title) . '</a></h2>'
        . '<p style="margin:0 0 14px;">' . e($text) . '</p>' . block_button($btn, $url, $color);
}
function block_columns(array $a, array $b, string $color, string $btn = 'More info >'): string
{
    $col = fn($x) => '<td class="col2" width="50%" valign="top" style="width:50%;padding:0 8px 16px;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:13px;line-height:1.5;">'
        . '<a href="' . e($x[1]) . '" target="_blank"><img src="' . e($x[2]) . '" alt="' . e($x[0]) . '" width="260" style="display:block;width:100%;height:auto;border:0;"></a>'
        . '<p style="margin:10px 0;"><a href="' . e($x[1]) . '" target="_blank" style="color:' . $color . ';"><strong>' . e($x[0]) . '</strong></a></p>'
        . '<p style="margin:0 0 10px;">' . e($x[3]) . '</p>' . block_button($btn, $x[1], $color) . '</td>';
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:0 -8px;"><tr>' . $col($a) . $col($b) . '</tr></table>';
}

/* ---------- Composición del correo ---------- */

function html_to_text(string $html): string
{
    $t = preg_replace('#<(style|script|head|title)\b.*?</\1>#is', '', $html);
    $t = preg_replace('/<!--.*?-->/s', '', $t);
    $t = preg_replace_callback('#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', function ($m) {
        $label = trim(strip_tags($m[2]));
        if ($m[1] === '' || $m[1] === '#') return $label;
        return $label === '' || $label === $m[1] ? $m[1] : "$label ({$m[1]})";
    }, $t);
    $t = preg_replace('#<br\s*/?>#i', "\n", $t);
    $t = preg_replace('#</t[dh]>#i', ' ', $t);
    $t = preg_replace('#</(p|div|h[1-6]|tr|li|table)>#i', "\n", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = preg_replace('/ *\n */', "\n", $t);
    return trim(preg_replace('/\n{3,}/', "\n\n", $t)) . "\n";
}

function email_footer(string $unsub): string
{
    $company = setting('company');
    $addr = setting('address');
    $txt = setting('unsub_text', 'Si no desea recibir más correos nuestros, puede [darse de baja].');
    $link = '<a href="' . e($unsub) . '" style="color:inherit;text-decoration:underline;">$1</a>';
    $txt = preg_match('/\[(.+?)\]/u', $txt)
        ? preg_replace('/\[(.+?)\]/u', $link, e($txt))
        : e($txt) . ' ' . str_replace('$1', 'Darse de baja', $link);
    $out = '';
    if ($company !== '') $out .= '© ' . e($company);
    if ($addr !== '') $out .= ($out ? '<br>' : '') . nl2br(e($addr));
    return $out . ($out ? '<br><br>' : '') . $txt;
}

function social_links(): string
{
    $nets = ['web' => ['Web', '#4bade9'], 'linkedin' => ['LinkedIn', '#0077b5'], 'x' => ['X', '#111111'],
        'facebook' => ['Facebook', '#3b5998'], 'youtube' => ['YouTube', '#eb3323']];
    $out = '';
    foreach ($nets as $k => [$label, $bg]) {
        $u = setting('social_' . $k);
        if ($u === '') continue;
        $out .= '<a href="' . e($u) . '" target="_blank" style="display:inline-block;margin:3px;padding:8px 12px;background:' . $bg
            . ';color:#ffffff;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:12px;font-weight:bold;text-decoration:none;border-radius:3px;">'
            . $label . '</a>';
    }
    return $out;
}

/**
 * Índice de la newsletter: hasta 4 titulares del contenido (con su enlace), 2 a la izquierda y 2 a la derecha
 * del logo, para que al abrir el correo se vea que hay varias noticias. Se genera solo a partir de los títulos.
 */
function email_headlines(string $body, int $max = 4): array
{
    if (!class_exists('DOMDocument') || trim($body) === '') return [];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $body . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $clean = fn($t) => trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string)$t));
    $okHref = fn($h) => $h !== '' && $h !== '#' && !preg_match('#^https?://$#i', $h);
    $items = [];
    $pending = null;
    $push = function (?array $it) use (&$items) {
        if (!$it) return;
        foreach ($items as $x) if ($x['text'] === $it['text']) return;
        $items[] = $it;
    };
    foreach ($xp->query('//h1|//h2|//h3|//td//p[strong or a/strong]|//a[@href]') as $n) {
        $name = strtolower($n->nodeName);
        if ($name === 'a') {
            // enlaces que ya forman parte de un titular
            if ($xp->query('ancestor::h1|ancestor::h2|ancestor::h3', $n)->length) continue;
            if ($n->parentNode && strtolower($n->parentNode->nodeName) === 'p' && $xp->query('strong', $n)->length && $xp->query('ancestor::td', $n)->length) continue;
            $href = trim($n->getAttribute('href'));
            if ($pending && $okHref($href)) { $pending['href'] = $href; $push($pending); $pending = null; }
            continue;
        }
        $text = $clean($n->textContent);
        if ($name === 'p') { // en bloques de columnas el titular es un párrafo entero en negrita
            $bold = $clean($xp->query('strong|a/strong', $n)->item(0)->textContent ?? '');
            if ($bold === '' || $bold !== $text) continue;
        }
        if ($text === '' || str_contains($text, '{{')) continue;
        $push($pending);
        $pending = ['text' => $text, 'href' => ''];
        $a = $xp->query('.//a[@href]', $n)->item(0);
        if ($a && $okHref(trim($a->getAttribute('href')))) { $pending['href'] = trim($a->getAttribute('href')); $push($pending); $pending = null; }
    }
    $push($pending);
    return array_slice($items, 0, $max);
}

function email_index(string $body): string
{
    $items = email_headlines($body);
    if (count($items) < 2) return '';
    $color = brand_color();
    $half = (int)ceil(count($items) / 2);
    $col = function (array $list) use ($color) {
        $out = '';
        foreach ($list as $it) {
            $t = e($it['text']);
            $out .= '<p style="margin:0 0 12px;">' . ($it['href'] !== ''
                ? '<a href="' . e($it['href']) . '" target="_blank" style="color:' . $color . ';text-decoration:underline;">' . $t . '</a>' : $t) . '</p>';
        }
        return $out;
    };
    $td = 'valign="middle" style="width:41%;padding:0 12px;font-family:Helvetica, Arial, sans-serif;font-size:11px;line-height:1.35;color:#000000;';
    $logo = base_url() . '/assets/logo-cuadrado.png';
    $web = setting('social_web');
    $img = '<img src="' . e($logo) . '" alt="' . e(setting('company', 'Bezares')) . '" width="100" style="display:block;width:100px;max-width:100%;height:auto;border:0;margin:0 auto;">';
    if ($web !== '') $img = '<a href="' . e($web) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>';
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout:fixed;"><tr>'
        . '<td width="41%" ' . $td . 'text-align:right;">' . $col(array_slice($items, 0, $half)) . '</td>'
        . '<td width="18%" align="center" valign="middle" style="width:18%;padding:0;">' . $img . '</td>'
        . '<td width="41%" ' . $td . 'text-align:left;">' . $col(array_slice($items, $half)) . '</td>'
        . '</tr></table>';
}

/**
 * Compone el correo para un contacto.
 * $ctx: ['queue_id' => int|null] para el píxel de apertura y el enlace "ver en el navegador".
 */
function render_email(array $email, array $contact, array $ctx = []): array
{
    $color = brand_color();
    $qid = $ctx['queue_id'] ?? null;
    $lang = lang_of($email['lang'] ?? 'es');
    $unsub = base_url() . '/u.php?c=' . rawurlencode($contact['token']) . ($lang === 'en' ? '&lang=en' : '');
    $view = $qid ? base_url() . '/v.php?q=' . $qid . '&h=' . sign('v' . $qid) : '#';

    $survey = $email['survey_id'] ? one('SELECT * FROM surveys WHERE id = ?', [$email['survey_id']]) : null;
    $body = (string)$email['body'];
    $block = $survey ? survey_block($survey, $contact, $lang) : '';
    if ($survey && !str_contains($body, '{{encuesta}}') && !str_contains($body, '{{encuesta_url}}')) $body .= '{{encuesta}}';

    // Estilos en línea mínimos para que el contenido se vea bien en cualquier cliente de correo
    $body = preg_replace('/<p(?![^>]*\bstyle=)/i', '<p style="margin:0 0 14px;"', $body);
    $body = preg_replace('/<img(?![^>]*\bstyle=)/i', '<img style="max-width:100%;height:auto;border:0;"', $body);
    $body = preg_replace('/<a (?![^>]*\bstyle=)/i', '<a style="color:' . $color . ';" ', $body);
    $body = preg_replace('/<h([1-3])(?![^>]*\bstyle=)/i', '<h$1 style="margin:0 0 12px;line-height:1.3;color:#000000;"', $body);

    $logo = logo_url();
    $company = setting('company', 'Bezares');
    // Tema «Comunicado» (encuesta NPS): si el correo no indica cabecera, usa encabezado-bezares.jpg de la biblioteca
    if ($email['header_image'] === '' && $email['theme'] === 'comunicado') {
        foreach (['jpg', 'jpeg', 'png'] as $ext) {
            if (is_file(ROOT . "/uploads/encabezado-bezares.$ext")) { $email['header_image'] = base_url() . "/uploads/encabezado-bezares.$ext"; break; }
        }
    }
    // Tema «Newsletter»: si el correo no indica cabecera, usa bezares-newsletter-2026-2.png de la biblioteca
    if ($email['header_image'] === '' && $email['theme'] === 'newsletter' && ($def = newsletter_default_header()) !== '') $email['header_image'] = $def;
    if ($email['header_image'] !== '') {
        $header = '<img src="' . e($email['header_image']) . '" alt="' . e($company) . '" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;">';
    } elseif ($logo !== '') {
        $header = '<img src="' . e($logo) . '" alt="' . e($company) . '" width="260" style="display:block;width:260px;max-width:70%;height:auto;border:0;margin:22px auto 14px;">';
    } else {
        $header = '<div style="padding:24px;font-family:Ubuntu, Helvetica, Arial, sans-serif;font-size:24px;font-weight:bold;color:' . $color . ';text-align:center;">' . e($company) . '</div>';
    }

    $html = strtr(theme_html($email['theme']), [
        '{{contenido}}' => $body,
        '{{cabecera}}' => $header,
        '{{pie}}' => email_footer('{{baja_url}}'),
        '{{redes}}' => social_links(),
        '{{indice}}' => str_contains(theme_html($email['theme']), '{{indice}}') ? email_index($body) : '',
        '{{remitente}}' => e($company),
    ]);
    if (!str_contains($html, '{{baja_url}}')) {
        $f = '<div style="max-width:600px;margin:0 auto;padding:16px;font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#777777;text-align:center;">'
            . email_footer('{{baja_url}}') . '</div>';
        $html = str_ireplace('</body>', $f . '</body>', $html, $n);
        if (!$n) $html .= $f;
    }
    $h = fn($k) => e($contact[$k] ?? '');
    $vars = [
        '{{encuesta}}' => $block,
        '{{encuesta_url}}' => $survey ? e(survey_url($survey, $contact, $lang === 'en' ? ['lang' => 'en'] : [])) : '#',
        '{{txt_ver}}' => tr('view_browser', $lang), '{{txt_problemas}}' => tr('view_problems', $lang),
        '{{asunto}}' => e($email['subject']),
        '{{preheader}}' => e($email['preheader']),
        '{{color}}' => $color,
        '{{baja_url}}' => e($unsub),
        '{{ver_url}}' => e($view),
        '{{nombre}}' => $h('nombre'), '{{apellidos}}' => $h('apellidos'),
        '{{empresa}}' => $h('empresa'), '{{email}}' => $h('email'),
    ];
    $html = strtr($html, $vars);
    $subject = strtr($email['subject'], ['{{nombre}}' => $contact['nombre'] ?? '', '{{apellidos}}' => $contact['apellidos'] ?? '',
        '{{empresa}}' => $contact['empresa'] ?? '', '{{email}}' => $contact['email'] ?? '']);
    $text = html_to_text($html);
    if ($qid && !empty($ctx['track'])) {
        $px = '<img src="' . e(base_url() . '/o.php?q=' . $qid . '&h=' . sign('o' . $qid)) . '" width="1" height="1" alt="" style="display:block;border:0;">';
        $html = str_ireplace('</body>', $px . '</body>', $html, $n);
        if (!$n) $html .= $px;
    }
    $files = function_exists('email_attachments') ? array_map(fn($n) => media_dir() . '/' . $n, email_attachments($email)) : [];
    return ['subject' => $subject, 'html' => $html, 'text' => $text, 'unsub' => $unsub, 'attachments' => $files];
}

/* ---------- Envío ---------- */

function smtp_from_settings(): Smtp
{
    return new Smtp(setting('smtp_host'), (int)setting('smtp_port', '587'), setting('smtp_secure', 'tls'),
        setting('smtp_user'), setting('smtp_pass'));
}

/** Envía un mensaje ya compuesto. Lanza una excepción si falla. */
function deliver(string $to, array $r, ?Smtp &$smtp = null): void
{
    $from = setting('from_email');
    if (!valid_email($from)) throw new RuntimeException('Falta configurar el correo del remitente en Ajustes.');
    [$headers, $body] = build_message([
        'from_email' => $from, 'from_name' => setting('from_name'), 'to' => $to,
        'reply_to' => $r['reply_to'] ?? setting('reply_to'), 'subject' => $r['subject'],
        'html' => $r['html'], 'text' => $r['text'], 'unsub_url' => $r['unsub'] ?? '',
        'attachments' => $r['attachments'] ?? [],
    ]);
    if (setting('driver', 'smtp') === 'mail') {
        $subject = $headers['Subject'];
        unset($headers['Subject'], $headers['To']);
        $lines = [];
        foreach ($headers as $k => $v) $lines[] = "$k: $v";
        if (!@mail($to, $subject, $body, implode("\r\n", $lines), '-f' . $from))
            throw new RuntimeException('La función mail() del servidor rechazó el envío.');
        return;
    }
    if (setting('smtp_host') === '') throw new RuntimeException('Falta configurar el servidor SMTP en Ajustes.');
    if (!$smtp) $smtp = smtp_from_settings();
    $raw = '';
    foreach ($headers as $k => $v) $raw .= "$k: $v\r\n";
    try {
        $smtp->send($from, $to, $raw . "\r\n" . $body);
    } catch (Throwable $e) {
        $smtp->close();
        throw $e;
    }
}

function sent_last_hour(): int
{
    return (int)val("SELECT COUNT(*) FROM queue WHERE status = 'sent' AND sent_at >= ?", [date('Y-m-d H:i:s', time() - 3600)]);
}

/** Procesa un lote de la cola. Devuelve contadores. */
function process_queue(?int $max = null): array
{
    @set_time_limit(300);
    $max = $max ?? max(1, (int)setting('batch', '20'));
    $limit = (int)setting('hourly_limit', '0');
    $res = ['sent' => 0, 'failed' => 0, 'limited' => false, 'error' => ''];
    // Recupera envíos que se quedaron a medias (proceso interrumpido)
    q("UPDATE queue SET status = 'pending' WHERE status = 'sending' AND sent_at < ?", [date('Y-m-d H:i:s', time() - 900)]);
    if ($limit > 0) {
        $left = $limit - sent_last_hour();
        if ($left <= 0) { $res['limited'] = true; $max = 0; } else $max = min($max, $left);
    }
    $rows = $max > 0 ? all("SELECT id FROM queue WHERE status = 'pending' ORDER BY id LIMIT " . (int)$max) : [];
    $smtp = null;
    $emails = [];
    $consecutive = 0;
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        // Reserva la fila para que dos procesos (cron y navegador) no envíen el mismo correo
        if (q("UPDATE queue SET status = 'sending', sent_at = ? WHERE id = ? AND status = 'pending'", [now(), $id])->rowCount() !== 1) continue;
        $item = one('SELECT * FROM queue WHERE id = ?', [$id]);
        $contact = one('SELECT * FROM contacts WHERE id = ?', [$item['contact_id']]);
        $email = $emails[$item['email_id']] ??= one('SELECT * FROM emails WHERE id = ?', [$item['email_id']]);
        if (!$contact || !$email || $contact['status'] !== 'subscribed') {
            q("UPDATE queue SET status = 'skipped', error = ? WHERE id = ?", ['Contacto dado de baja o eliminado', $id]);
            continue;
        }
        try {
            $r = render_email($email, $contact, ['queue_id' => $id, 'track' => true]);
            deliver($contact['email'], $r, $smtp);
            q("UPDATE queue SET status = 'sent', sent_at = ?, error = NULL WHERE id = ?", [now(), $id]);
            $res['sent']++;
            $consecutive = 0;
        } catch (Throwable $e) {
            q("UPDATE queue SET status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 480), $id]);
            $res['failed']++;
            $res['error'] = $e->getMessage();
            if (++$consecutive >= 3) break; // el servidor no funciona: no quemar toda la cola
        }
    }
    if ($smtp) $smtp->close();
    $res['pending'] = (int)val("SELECT COUNT(*) FROM queue WHERE status IN ('pending','sending')");
    return $res;
}

/* ---------- Estadísticas de encuestas ---------- */

function survey_limit_reached(array $survey, string $email): bool
{
    $max = (int)$survey['max_per_contact'];
    if ($max <= 0) return false;
    return (int)val('SELECT COUNT(*) FROM responses WHERE survey_id = ? AND email = ?', [$survey['id'], norm_email($email)]) >= $max;
}

/** Condición SQL para filtrar respuestas por idioma ('' = todas). Las respuestas antiguas sin idioma cuentan como español. */
function lang_filter(string $lang, string $alias = 'r'): string
{
    if ($lang === 'en') return " AND $alias.lang = 'en'";
    if ($lang === 'es') return " AND ($alias.lang IS NULL OR $alias.lang <> 'en')";
    return '';
}

/** Estadísticas por pregunta. $lang: '' (general: español + inglés), 'es' o 'en'. */
function survey_stats(array $survey, string $lang = ''): array
{
    $questions = all('SELECT * FROM questions WHERE survey_id = ? ORDER BY position, id', [$survey['id']]);
    $answers = all('SELECT a.question_id, a.value FROM answers a JOIN responses r ON r.id = a.response_id WHERE r.survey_id = ?' . lang_filter($lang), [$survey['id']]);
    $by = [];
    foreach ($answers as $a) if ((string)$a['value'] !== '') $by[$a['question_id']][] = $a['value'];
    foreach ($questions as &$q) {
        $vals = $by[$q['id']] ?? [];
        $q['n'] = count($vals);
        if ($q['type'] === 'nps' || $q['type'] === 'rating') {
            [$min, $max] = $q['type'] === 'nps' ? [0, 10] : [1, 5];
            $dist = array_fill($min, $max - $min + 1, 0);
            foreach ($vals as $v) if (isset($dist[(int)$v])) $dist[(int)$v]++;
            $q['dist'] = $dist;
            $q['avg'] = $q['n'] ? array_sum(array_map('intval', $vals)) / $q['n'] : null;
            if ($q['type'] === 'nps') {
                $q['promoters'] = $dist[9] + $dist[10];
                $q['passives'] = $dist[7] + $dist[8];
                $q['detractors'] = $q['n'] - $q['promoters'] - $q['passives'];
                $q['nps'] = $q['n'] ? (int)round(($q['promoters'] - $q['detractors']) / $q['n'] * 100) : null;
            }
        } elseif ($q['type'] === 'radio' || $q['type'] === 'checkbox') {
            $dist = [];
            foreach (question_options($q) as $o) $dist[$o] = 0;
            foreach ($vals as $v) foreach (explode(' | ', $v) as $p) $dist[$p] = ($dist[$p] ?? 0) + 1;
            $q['dist'] = $dist;
        } else {
            $q['texts'] = $vals;
        }
    }
    return $questions;
}
function question_options(array $q): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$q['options'])), fn($o) => $o !== ''));
}

/**
 * Resumen comparativo General / Español / Inglés de una encuesta: enviados, respuestas y, si hay pregunta NPS, su desglose.
 * $sendIds: limitar los "enviados" a unos envíos concretos (campaña automática); vacío = todos los correos de la encuesta.
 */
function survey_lang_summary(array $survey, array $sendIds = []): array
{
    $out = [];
    foreach (['' => 'General (español + inglés)', 'es' => 'Español', 'en' => 'Inglés'] as $lang => $label) {
        $w = $lang === 'en' ? " AND e.lang = 'en'" : ($lang === 'es' ? " AND e.lang <> 'en'" : '');
        $p = [$survey['id']];
        if ($sendIds) { $w .= ' AND q.send_id IN (' . implode(',', array_map('intval', $sendIds)) . ')'; }
        $sent = (int)val("SELECT COUNT(DISTINCT q.contact_id) FROM queue q JOIN emails e ON e.id = q.email_id WHERE e.survey_id = ? AND q.status = 'sent'$w", $p);
        $responses = (int)val('SELECT COUNT(*) FROM responses r WHERE r.survey_id = ?' . lang_filter($lang), [$survey['id']]);
        $nps = null;
        foreach (survey_stats($survey, $lang) as $q) if ($q['type'] === 'nps') { $nps = $q; break; }
        $out[$lang] = ['label' => $label, 'sent' => $sent, 'responses' => $responses, 'q' => $nps];
    }
    return $out;
}

const NEWSLETTER_HEADER = 'bezares-newsletter-2026-2.png';
const NEWSLETTER_HEADER_OLD = 'https://mailing.bezares.com/media/images/bezares-newsletter-2026-2.png';

/** Dirección de la cabecera por defecto de las newsletters si está en la biblioteca; '' si no. */
function newsletter_default_header(): string
{
    return is_file(ROOT . '/uploads/' . NEWSLETTER_HEADER) ? base_url() . '/uploads/' . NEWSLETTER_HEADER : '';
}

/* ---------- Aviso por correo de las respuestas de una encuesta ---------- */

function email_list(string $s): array
{
    return array_values(array_unique(array_filter(array_map('norm_email', preg_split('/[,;\s]+/', $s)), 'valid_email')));
}

/**
 * Deja configurada la encuesta NPS (la plantilla y las campañas ya creadas) para avisar de cada respuesta con nota de 6 o menos:
 * las respuestas en inglés a p.hermo@bezares.com y las respuestas en español a j.adalia@bezares.com.
 * Solo toca las encuestas con pregunta NPS que aún no tienen ningún aviso configurado.
 */
function nps_notify_setup(): void
{
    foreach (all("SELECT DISTINCT s.id FROM surveys s JOIN questions q ON q.survey_id = s.id AND q.type = 'nps'
        WHERE s.notify_mode = '' AND s.notify_es = '' AND s.notify_en = '' AND (s.slug = 'nps' OR s.title LIKE 'NPS-Q%' OR s.title LIKE '%NPS%')") as $r)
        q("UPDATE surveys SET notify_mode = 'low', notify_max = 6, notify_es = ?, notify_en = ? WHERE id = ?", ['j.adalia@bezares.com', 'p.hermo@bezares.com', $r['id']]);
}

/**
 * Envía el aviso de una respuesta, si la encuesta lo tiene configurado.
 * notify_mode: '' (no avisar), 'all' (cada respuesta) o 'low' (solo si la nota es igual o menor que notify_max).
 * Devuelve a cuántas direcciones se envió. Nunca lanza excepciones: un fallo del aviso no debe estropear la respuesta del cliente.
 */
function survey_notify(array $survey, array $questions, array $values, string $email, ?array $contact, string $lang): int
{
    try {
        $mode = (string)($survey['notify_mode'] ?? '');
        if ($mode !== 'all' && $mode !== 'low') return 0;
        $score = null;
        foreach ($questions as $q) if (in_array($q['type'], ['nps', 'rating'], true)) { $score = ($values[$q['id']] ?? '') === '' ? null : (int)$values[$q['id']]; break; }
        if ($mode === 'low' && ($score === null || $score > (int)$survey['notify_max'])) return 0;
        // Destinatarios según el idioma de la respuesta; si el de ese idioma está vacío se usa el del otro
        $to = email_list((string)($lang === 'en' ? $survey['notify_en'] : $survey['notify_es']));
        if (!$to) $to = email_list((string)($lang === 'en' ? $survey['notify_es'] : $survey['notify_en']));
        if (!$to) return 0;
        $contact ??= one('SELECT * FROM contacts WHERE email = ?', [$email]);
        $color = brand_color();
        $f = 'font-family:Ubuntu, Helvetica, Arial, sans-serif;';
        $td = 'style="padding:7px 10px;border-bottom:1px solid #dde3e8;' . $f . 'font-size:14px;color:#222;vertical-align:top;"';
        $th = 'style="padding:7px 10px;border-bottom:1px solid #dde3e8;' . $f . 'font-size:13px;color:#667782;vertical-align:top;width:38%;"';
        $rows = [['Correo', '<a href="mailto:' . e($email) . '" style="color:' . $color . ';">' . e($email) . '</a>']];
        $name = trim(($contact['nombre'] ?? '') . ' ' . ($contact['apellidos'] ?? ''));
        if ($name !== '') $rows[] = ['Nombre', e($name)];
        if (($contact['empresa'] ?? '') !== '') $rows[] = ['Empresa', e($contact['empresa'])];
        $rows[] = ['Idioma de la respuesta', $lang === 'en' ? 'Inglés' : 'Español'];
        $rows[] = ['Fecha', date('d/m/Y H:i')];
        foreach ($questions as $q) {
            $v = (string)($values[$q['id']] ?? '');
            $shown = $v === '' ? '<span style="color:#999;">(sin respuesta)</span>' : nl2br(e($v));
            if (in_array($q['type'], ['nps', 'rating'], true) && $v !== '') {
                $bg = $q['type'] === 'nps' ? ((int)$v <= 6 ? '#e5484d' : ((int)$v <= 8 ? '#f0a020' : '#2f9e5b')) : ((int)$v <= 2 ? '#e5484d' : ((int)$v === 3 ? '#f0a020' : '#2f9e5b'));
                $shown = '<span style="display:inline-block;background:' . $bg . ';color:#ffffff;font-weight:bold;padding:2px 12px;border-radius:4px;">' . e($v) . '</span>';
            }
            $rows[] = [e($q['label']), $shown];
        }
        $link = base_url() . '/index.php?p=surveys&a=results&id=' . $survey['id'];
        $intro = $mode === 'low' ? 'Se ha recibido una respuesta con una nota de <b>' . $score . '</b> (igual o menor que ' . (int)$survey['notify_max'] . ').' : 'Se ha recibido una nueva respuesta.';
        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Respuesta a ' . e($survey['title']) . '</title></head><body style="margin:0;padding:20px;background:#eef1f4;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:620px;margin:0 auto;background:#ffffff;"><tr><td style="padding:26px 28px;">'
            . '<h1 style="' . $f . 'font-size:20px;color:' . $color . ';margin:0 0 6px;">' . e($survey['title']) . '</h1>'
            . '<p style="' . $f . 'font-size:14px;color:#444;margin:0 0 16px;">' . $intro . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border-top:2px solid ' . $color . ';">';
        foreach ($rows as [$k, $v]) $html .= "<tr><td $th>$k</td><td $td>$v</td></tr>";
        $html .= '</table><p style="' . $f . 'font-size:13px;color:#555;margin:18px 0 0;">Puede responder a este correo para escribir directamente al cliente. '
            . '<a href="' . e($link) . '" style="color:' . $color . ';">Ver todos los resultados</a>.</p></td></tr></table></body></html>';
        $subject = '[' . $survey['title'] . '] ' . ($score !== null ? 'Nota ' . $score . ' de ' : 'Respuesta de ') . $email
            . (($contact['empresa'] ?? '') !== '' ? ' (' . $contact['empresa'] . ')' : '');
        $smtp = null;
        try {
            foreach ($to as $addr) deliver($addr, ['subject' => $subject, 'html' => $html, 'text' => html_to_text($html), 'reply_to' => $email], $smtp);
        } finally { if ($smtp) $smtp->close(); }
        return count($to);
    } catch (Throwable $e) {
        try { set_setting('notify_last_error', date('d/m/Y H:i') . ' · ' . ($survey['title'] ?? '') . ': ' . mb_substr($e->getMessage(), 0, 300)); } catch (Throwable $e2) {}
        return 0;
    }
}

/** Lista de destinatarios de una prueba: [válidos (máximo 20, sin repetir), no válidos]. Acepta comas, punto y coma, espacios o saltos de línea. */
function test_recipients(string $s): array
{
    $ok = []; $bad = [];
    foreach (preg_split('/[,;\s]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $p) {
        $e = norm_email($p);
        if (valid_email($e)) $ok[$e] = true; else $bad[] = $p;
    }
    return [array_slice(array_keys($ok), 0, 20), $bad];
}
