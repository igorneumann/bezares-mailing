<?php
/* Temas «Moderno» (comunicado, newsletter, encuesta, promoción y evento): piezas que los temas rellenan al enviar
   y bloques de contenido con los que se montan los correos de ejemplo. Todo es HTML de correo: tablas, estilos en
   línea y colores también en atributos bgcolor para que Outlook lo muestre bien. Los mismos bloques se pueden añadir
   desde el editor (assets/app.js). */

const MF = "'Segoe UI',Helvetica,Arial,sans-serif";
const M_INK = '#1b2230', M_TXT = '#3d4756', M_MUTED = '#6b7686', M_LINE = '#e3e8ee', M_RED = '#f5333f', M_SKY = '#009fe3';

/* ---------- Piezas del tema (marcadores {{...}}) ---------- */

/** Logo de la cabecera (el de Ajustes) o, si no hay, el nombre de la empresa. */
function modern_logo(): string
{
    $logo = logo_url();
    $company = setting('company', 'Bezares');
    return $logo !== ''
        ? '<img src="' . e($logo) . '" width="150" alt="' . e($company) . '" style="display:inline-block;width:150px;max-width:150px;height:auto;border:0;">'
        : '<span style="font-family:' . MF . ';font-size:20px;font-weight:700;color:' . brand_color() . ';">' . e($company) . '</span>';
}

/** Imagen de cabecera del correo a todo el ancho (vacío si el correo no tiene). */
function modern_header_image(string $url, string $alt, bool $top = false): string
{
    // $top: la imagen abre la tarjeta, así que lleva sus esquinas redondeadas de arriba
    return $url === '' ? '' : '<img src="' . e($url) . '" width="600" alt="' . e($alt) . '" style="display:block;width:100%;max-width:600px;height:auto;border:0;'
        . ($top ? 'border-radius:14px 14px 0 0;' : '') . '">';
}

/** Fecha larga en el idioma del correo: «22 de noviembre de 2026» / «22 November 2026». */
function modern_date(string $lang, bool $monthOnly = false): string
{
    $es = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $en = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $m = (int)date('n') - 1;
    if ($lang === 'en') return $monthOnly ? $en[$m] . ' ' . date('Y') : date('j') . ' ' . $en[$m] . ' ' . date('Y');
    return $monthOnly ? ucfirst($es[$m]) . ' ' . date('Y') : date('j') . ' de ' . $es[$m] . ' de ' . date('Y');
}

/** Índice numerado «En este número» con los titulares del correo (vacío si hay menos de dos). */
function modern_index(string $body, string $lang): string
{
    $items = email_headlines($body, 6);
    if (count($items) < 2) return '';
    $rows = '';
    foreach ($items as $i => $it) {
        $t = e($it['text']);
        if ($it['href'] !== '') $t = '<a href="' . e($it['href']) . '" target="_blank" style="color:' . M_INK . ';text-decoration:none;">' . $t . '</a>';
        $rows .= '<tr><td valign="top" width="30" style="padding:6px 0;font-family:' . MF . ';font-size:14px;font-weight:700;color:' . M_RED . ';">'
            . sprintf('%02d', $i + 1) . '</td><td style="padding:6px 0;font-family:' . MF . ';font-size:15px;line-height:1.4;color:' . M_INK . ';">' . $t . '</td></tr>';
    }
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 30px;"><tr>'
        . '<td bgcolor="#f6f8fa" style="background-color:#f6f8fa;border-radius:12px;padding:20px 24px;">'
        . '<p style="margin:0 0 8px;font-family:' . MF . ';font-size:12px;font-weight:700;letter-spacing:1.6px;color:' . M_MUTED . ';">'
        . tr('in_this_issue', $lang) . '</p><table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr></table>';
}

/** Redes sociales como círculos con su inicial (no dependen de imágenes, que muchos programas bloquean). */
function modern_social(): string
{
    $nets = ['linkedin' => 'in', 'youtube' => 'YT', 'x' => 'X', 'facebook' => 'f', 'web' => 'Web'];
    $out = '';
    foreach ($nets as $k => $label) {
        $u = setting('social_' . $k);
        if ($u === '') continue;
        $out .= '<td style="padding:0 4px;"><a href="' . e($u) . '" target="_blank" style="display:inline-block;width:36px;height:36px;line-height:36px;'
            . 'border-radius:18px;background-color:#e6eff5;color:' . brand_color() . ';font-family:' . MF . ';font-size:12px;font-weight:700;text-align:center;text-decoration:none;">'
            . $label . '</a></td>';
    }
    return $out === '' ? '' : '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 14px;"><tr>' . $out . '</tr></table>';
}

/** Marcadores propios de los temas «Moderno» (solo se calculan si el tema los usa). */
function modern_vars(string $theme, array $email, string $body, string $lang): array
{
    if (!str_contains($theme, '{{m_')) return [];
    $company = setting('company', 'Bezares');
    $web = setting('social_web');
    return [
        '{{m_logo}}' => modern_logo(),
        '{{m_cabecera}}' => modern_header_image((string)$email['header_image'], $company),
        '{{m_cabecera_arriba}}' => modern_header_image((string)$email['header_image'], $company, true),
        '{{m_fecha}}' => modern_date($lang),
        '{{m_mes}}' => modern_date($lang, true),
        '{{m_indice}}' => str_contains($theme, '{{m_indice}}') ? modern_index($body, $lang) : '',
        '{{m_redes}}' => modern_social(),
        '{{m_web}}' => $web !== '' ? '<a href="' . e($web) . '" target="_blank" style="color:' . brand_color() . ';font-weight:700;font-size:13px;text-decoration:none;">'
            . e(preg_replace('#^https?://(www\.)?#', '', rtrim($web, '/'))) . '</a>' : '',
        '{{m_txt_comunicado}}' => tr('kind_announcement', $lang),
        '{{m_txt_invitacion}}' => tr('kind_invitation', $lang),
    ];
}

/* ---------- Bloques de contenido ---------- */

function mb_p(string $html, int $size = 16, string $color = M_TXT, int $mb = 16, string $align = 'left'): string
{
    return '<p style="margin:0 0 ' . $mb . 'px;font-family:' . MF . ';font-size:' . $size . 'px;line-height:1.65;color:' . $color . ';text-align:' . $align . ';">' . $html . '</p>';
}
function mb_eyebrow(string $text, string $color = M_RED, string $align = 'left'): string
{
    return '<p style="margin:0 0 10px;font-family:' . MF . ';font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:' . $color . ';text-align:' . $align . ';">' . e($text) . '</p>';
}
function mb_h1(string $text, int $size = 30, string $align = 'left', string $color = M_INK): string
{
    return '<h1 class="h1" style="margin:0 0 18px;font-family:' . MF . ';font-size:' . $size . 'px;line-height:1.22;font-weight:700;color:' . $color . ';text-align:' . $align . ';">' . e($text) . '</h1>';
}
function mb_h2(string $text, string $href = '', int $size = 22, int $mb = 10): string
{
    $t = $href !== '' ? '<a href="' . e($href) . '" target="_blank" style="color:' . M_INK . ';text-decoration:none;">' . e($text) . '</a>' : e($text);
    return '<h2 style="margin:0 0 ' . $mb . 'px;font-family:' . MF . ';font-size:' . $size . 'px;line-height:1.3;font-weight:700;color:' . M_INK . ';">' . $t . '</h2>';
}
/** Botón: el color va en la celda (bgcolor) para que Outlook lo pinte; el editor lo reconoce como botón. */
function mb_button(string $text, string $href, string $bg = '', string $color = '#ffffff', string $align = 'left', string $border = ''): string
{
    $bg = $bg ?: brand_color();
    $bd = $border !== '' ? 'border:2px solid ' . $border . ';' : '';
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0"' . ($align === 'center' ? ' align="center"' : '') . ' style="margin:6px 0 24px;"><tr>'
        . '<td align="center" bgcolor="' . $bg . '" style="background:' . $bg . ';border-radius:8px;' . $bd . '">'
        . '<a href="' . e($href) . '" target="_blank" style="display:inline-block;padding:13px 28px;color:' . $color . ';font-family:' . MF
        . ';font-size:15px;font-weight:700;line-height:20px;text-decoration:none;border-radius:8px;">' . e($text) . '</a></td></tr></table>';
}
function mb_link(string $text, string $href, int $size = 15): string
{
    return '<p style="margin:0 0 6px;font-family:' . MF . ';font-size:' . $size . 'px;"><a href="' . e($href) . '" target="_blank" style="font-weight:700;color:' . brand_color() . ';text-decoration:none;">' . e($text) . ' &rarr;</a></p>';
}
/** Recuadro destacado con borde de color a la izquierda. */
function mb_callout(string $title, string $text, string $color = M_SKY): string
{
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0 22px;"><tr>'
        . '<td bgcolor="#f1f7fb" style="background-color:#f1f7fb;border-left:4px solid ' . $color . ';border-radius:0 10px 10px 0;padding:16px 20px;font-family:' . MF . ';font-size:15px;line-height:1.6;color:' . M_INK . ';">'
        . '<strong>' . e($title) . '</strong><br>' . e($text) . '</td></tr></table>';
}
function mb_image(string $src, int $w = 528, int $radius = 10, string $href = ''): string
{
    $img = '<img src="' . e($src) . '" width="' . $w . '" alt="" style="display:block;width:100%;max-width:' . $w . 'px;height:auto;border:0;border-radius:' . $radius . 'px;">';
    return '<div class="full" style="margin:0 0 18px;">' . ($href !== '' ? '<a href="' . e($href) . '" target="_blank">' . $img . '</a>' : $img) . '</div>';
}
/** Noticia grande: imagen, antetítulo, titular con enlace, texto y «Leer más». */
function mb_article(string $img, string $tag, string $title, string $text, string $href, string $more = 'Leer más'): string
{
    return '<div style="margin:0 0 30px;">' . mb_image($img, 528, 10, $href) . mb_eyebrow($tag, M_SKY) . mb_h2($title, $href)
        . mb_p(e($text), 15, M_TXT, 12) . mb_link($more, $href) . '</div>';
}
/** Dos columnas (en el móvil, una debajo de otra). */
function mb_two(string $a, string $b): string
{
    return mb_cols([$a, $b]);
}
/**
 * Fila de 2 o 3 columnas del mismo ancho (en el móvil, una debajo de otra). El hueco entre columnas es de 24 px con dos
 * y de 15 px con tres, repartido para que todas tengan el mismo ancho útil.
 */
function mb_cols(array $cells): string
{
    $n = max(1, count($cells));
    $gap = $n >= 3 ? 15 : 24;
    $w = $n === 3 ? '33%' : (int)floor(100 / $n) . '%';
    $wCss = $n === 3 ? '33.33%' : $w;
    $out = '';
    foreach (array_values($cells) as $i => $x) {
        // Cada columna cede el mismo relleno en total: la primera solo a la derecha, la última solo a la izquierda
        $left = (int)round($gap * $i / $n);
        $right = (int)round($gap * ($n - 1 - $i) / $n);
        $px = fn(int $v) => $v ? $v . 'px' : '0';
        $out .= '<td class="col2 mstack" width="' . $w . '" valign="top" style="width:' . $wCss . ';padding:0 ' . $px($right) . ' 18px ' . $px($left) . ';">' . $x . '</td>';
    }
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 26px;"><tr>' . $out . '</tr></table>';
}
function mb_article_small(string $img, string $tag, string $title, string $text, string $href, string $more = 'Leer más'): string
{
    return '<div class="full" style="margin:0 0 14px;"><a href="' . e($href) . '" target="_blank"><img src="' . e($img) . '" width="252" alt="" style="display:block;width:100%;max-width:252px;height:auto;border:0;border-radius:10px;"></a></div>'
        . mb_eyebrow($tag, M_SKY) . mb_h2($title, $href, 17, 8) . mb_p(e($text), 14, M_TXT, 10) . mb_link($more, $href, 14);
}
/** Banda de cifras sobre el color de la marca. */
function mb_stats(array $items): string
{
    $c = brand_color();
    $cells = '';
    foreach ($items as [$n, $label]) {
        $cells .= '<td width="' . floor(100 / count($items)) . '%" align="center" style="padding:10px 6px;font-family:' . MF . ';color:#ffffff;">'
            . '<div style="font-size:30px;font-weight:700;line-height:1.1;color:#ffffff;">' . e($n) . '</div>'
            . '<div style="font-size:13px;color:#cfe2ec;padding-top:4px;">' . e($label) . '</div></td>';
    }
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0 24px;"><tr>'
        . '<td bgcolor="' . $c . '" style="background-color:' . $c . ';border-radius:12px;padding:20px 14px;">'
        . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table></td></tr></table>';
}
/** Cabecera de promoción: fondo de color, etiqueta, titular, texto y botón blanco. */
function mb_hero(string $tag, string $title, string $text, string $btn, string $href): string
{
    $c = brand_color();
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 26px;"><tr>'
        . '<td bgcolor="' . $c . '" style="background-color:' . $c . ';background-image:linear-gradient(135deg,' . $c . ' 0%,#002f45 100%);border-radius:14px;padding:40px 34px 38px;">'
        . '<p style="margin:0 0 14px;"><span style="display:inline-block;background-color:' . M_RED . ';color:#ffffff;font-family:' . MF . ';font-size:12px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;padding:6px 12px;border-radius:20px;">' . e($tag) . '</span></p>'
        . '<h1 class="h1" style="margin:0 0 12px;font-family:' . MF . ';font-size:36px;line-height:1.15;font-weight:800;color:#ffffff;">' . e($title) . '</h1>'
        . '<p style="margin:0 0 24px;font-family:' . MF . ';font-size:17px;line-height:1.55;color:#d3e6f0;">' . e($text) . '</p>'
        . mb_button($btn, $href, '#ffffff', $c) . '</td></tr></table>';
}
/** Ventajas con una marca de verificación. */
function mb_perks(array $items): string
{
    $cells = '';
    foreach ($items as [$t, $d]) {
        $cells .= '<td class="col2 mstack" width="33%" valign="top" style="padding:0 8px 16px 0;font-family:' . MF . ';">'
            . '<span style="display:inline-block;width:26px;height:26px;line-height:26px;border-radius:13px;background-color:#e3f4ea;color:#2f9e5b;font-weight:700;text-align:center;font-size:14px;">&#10003;</span>'
            . '<div style="font-size:14px;font-weight:700;color:' . M_INK . ';padding-top:8px;">' . e($t) . '</div>'
            . '<div style="font-size:13px;color:' . M_MUTED . ';line-height:1.45;">' . e($d) . '</div></td>';
    }
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 18px;"><tr>' . $cells . '</tr></table>';
}
/** Tarjeta de producto: imagen, nombre, características, precio (antes / ahora) y enlace. */
function mb_product(string $img, string $name, string $spec, string $before, string $now, string $href, string $more = 'Ver producto'): string
{
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="border:1px solid ' . M_LINE . ';border-radius:12px;">'
        . '<tr><td class="full" style="padding:0;"><img src="' . e($img) . '" width="250" alt="" style="display:block;width:100%;max-width:250px;height:auto;border:0;border-radius:12px 12px 0 0;"></td></tr>'
        . '<tr><td style="padding:16px 18px 18px;">' . mb_h2($name, '', 17, 4)
        . '<p style="margin:0 0 12px;font-family:' . MF . ';font-size:13px;line-height:1.5;color:' . M_MUTED . ';">' . e($spec) . '</p>'
        . '<p style="margin:0 0 14px;font-family:' . MF . ';font-size:14px;color:' . M_TXT . ';">'
        . ($before !== '' ? '<span style="text-decoration:line-through;color:' . M_MUTED . ';">' . e($before) . '</span>&nbsp; ' : '')
        . '<strong style="font-size:20px;color:' . M_RED . ';">' . e($now) . '</strong></p>' . mb_link($more, $href, 14) . '</td></tr></table>';
}
/** Código de descuento con fecha de validez. */
function mb_coupon(string $intro, string $code, string $untilLabel, string $until): string
{
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0 24px;"><tr>'
        . '<td bgcolor="#fff4f4" style="background-color:#fff4f4;border:1px dashed ' . M_RED . ';border-radius:12px;padding:18px 20px;font-family:' . MF . ';">'
        . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr>'
        . '<td class="col2 mstack center-m" style="font-family:' . MF . ';font-size:14px;line-height:1.5;color:' . M_TXT . ';">' . e($intro) . '<br><strong style="font-size:22px;letter-spacing:3px;color:' . M_RED . ';">' . e($code) . '</strong></td>'
        . '<td class="col2 mstack center-m" align="right" style="font-family:' . MF . ';font-size:13px;color:' . M_MUTED . ';padding-top:4px;">' . e($untilLabel) . '<br><strong style="color:' . M_INK . ';font-size:15px;">' . e($until) . '</strong></td>'
        . '</tr></table></td></tr></table>';
}
/** Hoja de calendario con la fecha junto al antetítulo y el titular del evento. */
function mb_event_title(string $month, string $day, string $note, string $tag, string $title): string
{
    $date = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="86"><tr><td bgcolor="' . M_RED . '" align="center" style="background-color:' . M_RED . ';border-radius:10px 10px 0 0;padding:6px 0;font-family:' . MF . ';font-size:12px;font-weight:700;letter-spacing:1.5px;color:#ffffff;">' . e($month) . '</td></tr>'
        . '<tr><td align="center" style="border:1px solid ' . M_LINE . ';border-top:0;border-radius:0 0 10px 10px;padding:6px 0 8px;font-family:' . MF . ';color:' . M_INK . ';"><div style="font-size:30px;font-weight:800;line-height:1.1;">' . e($day) . '</div><div style="font-size:11px;color:' . M_MUTED . ';">' . e($note) . '</div></td></tr></table>';
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 8px;"><tr>'
        . '<td valign="top" width="86" style="padding-right:20px;">' . $date . '</td><td valign="top">' . mb_eyebrow($tag) . mb_h1($title, 26) . '</td></tr></table>';
}
/** Datos del evento: filas con icono, título y texto. */
function mb_details(array $rows): string
{
    $out = '';
    foreach ($rows as [$icon, $t, $d]) {
        $out .= '<tr><td width="34" valign="top" style="padding:9px 0;font-size:18px;">' . $icon . '</td><td style="padding:9px 0;border-bottom:1px solid ' . M_LINE . ';font-family:' . MF . ';font-size:15px;line-height:1.5;color:' . M_TXT . ';"><strong style="color:' . M_INK . ';">' . e($t) . '</strong><br>' . e($d) . '</td></tr>';
    }
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:4px 0 26px;">' . $out . '</table>';
}
function mb_note(string $html, string $align = 'left'): string
{
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:22px 0 4px;"><tr><td align="' . $align . '" bgcolor="#f6f8fa" style="background-color:#f6f8fa;border-radius:10px;padding:14px 18px;font-family:' . MF . ';font-size:13px;line-height:1.6;color:' . M_MUTED . ';">' . $html . '</td></tr></table>';
}
function mb_signature(string $name, string $company): string
{
    return mb_p('Un cordial saludo,', 16, M_TXT, 4) . '<p style="margin:0;font-family:' . MF . ';font-size:16px;line-height:1.5;color:' . M_INK . ';"><strong>' . e($name) . '</strong><br><span style="font-size:14px;color:' . M_MUTED . ';">' . e($company) . '</span></p>';
}

/* ---------- Correos de ejemplo ---------- */

/** Crea un correo de ejemplo por cada tema «Moderno» (si no existe ya uno con ese nombre). */
function modern_seed(): int
{
    $img = fn($f) => base_url() . '/assets/plantillas/' . $f;
    $web = setting('social_web') ?: 'https://bezares.com';
    $company = setting('company', 'BEZARES SA');
    $nps = val("SELECT id FROM surveys WHERE slug = 'nps'");
    $mails = [
        ['Ejemplo · Comunicado moderno', 'moderno-comunicado', 'TER Brasil se incorpora al Grupo Bezares', 'Nuestra quinta fábrica, en Caxias do Sul', null,
            mb_eyebrow('Grupo Bezares') . mb_h1('TER Brasil se incorpora al Grupo Bezares')
            . mb_p('Estimado/a {{nombre}}:')
            . mb_p('Nos complace anunciar que TER Brasil, fabricante de soluciones hidráulicas con sede en Caxias do Sul, forma parte desde hoy del Grupo Bezares. Con esta incorporación sumamos nuestra quinta fábrica y reforzamos la atención a nuestros clientes en Sudamérica.')
            . mb_callout('¿Qué cambia para usted?', 'Nada en su día a día: mantiene su contacto comercial y las mismas condiciones. A partir de enero tendrá además acceso a la gama fabricada en Brasil.')
            . mb_p('Si tiene cualquier pregunta, su equipo comercial habitual estará encantado de atenderle.')
            . mb_button('Leer la noticia completa', $web) . mb_signature('Departamento de Comunicación', $company), ''],
        ['Ejemplo · Newsletter moderna', 'moderno-newsletter', 'Newsletter Bezares: bauma CHINA, nueva fábrica y más', 'Las novedades del Grupo Bezares este mes', null,
            mb_article($img('n1.jpg'), 'Ferias', 'Bezares, presente en bauma CHINA 2026', 'Nuestra filial Zhejiang Bezares Power Transmission presentó en Shanghái la nueva gama de tomas de fuerza para vehículos industriales del mercado asiático, con certificación IATF 16949.', $web)
            . mb_two(mb_article_small($img('n2.jpg'), 'Grupo', 'TER Brasil se une a Bezares', 'Nuestra quinta fábrica, en Caxias do Sul, refuerza la presencia en Sudamérica.', $web),
                mb_article_small($img('n3.jpg'), 'Producto', 'Toma de fuerza eléctrica ePTO', 'Menos consumo y ruido para camiones de recogida en ciudad.', $web))
            . mb_stats([['1947', 'Desde'], ['10', 'Empresas'], ['+100', 'Países']]), $img('hero.jpg')],
        ['Ejemplo · Encuesta moderna', 'moderno-encuesta', 'Su opinión nos importa', '¿Nos recomendaría? Solo le llevará un minuto', $nps ? (int)$nps : null,
            mb_eyebrow('Su opinión nos importa', M_SKY, 'center') . mb_h1('Ayúdenos a mejorar', 28, 'center')
            . mb_p('Hola {{nombre}}, pulse una puntuación del 0 al 10. Solo le llevará un minuto y nos ayuda a mejorar.', 15, M_TXT, 18, 'center')
            . '{{encuesta}}' . mb_note('&#128274; Sus respuestas son confidenciales y solo se usan para mejorar nuestro servicio.', 'center'), ''],
        ['Ejemplo · Promoción moderna', 'moderno-promocion', 'Oferta de invierno: -15 % en bombas', 'Solo hasta el 31 de diciembre', null,
            mb_hero('Oferta de invierno', '-15 % en bombas de engranajes', 'En toda la gama GPA y GPR. Pedidos realizados antes del 31 de diciembre de 2026.', 'Ver la oferta', $web)
            . mb_perks([['Envío gratis', 'en pedidos de más de 500 €'], ['2 años de garantía', 'en todos los productos'], ['Soporte técnico', 'de nuestros ingenieros']])
            . mb_h2('Productos destacados', '', 20, 14)
            . mb_two(mb_product($img('p1.jpg'), 'Bomba GPA 40', 'Desplazamiento 40 cc · hasta 250 bar', '412 €', '350 €', $web),
                mb_product($img('p2.jpg'), 'Bomba GPR 63', 'Desplazamiento 63 cc · hasta 280 bar', '586 €', '498 €', $web))
            . mb_coupon('Use este código al hacer su pedido:', 'INVIERNO15', 'Válido hasta', '31/12/2026'), ''],
        ['Ejemplo · Invitación a evento', 'moderno-evento', 'Le invitamos a bauma 2026', 'Visítenos en el pabellón B5, stand 320', null,
            mb_event_title('ABRIL', '13', 'al 19', 'Invitación', 'Le esperamos en bauma 2026')
            . mb_p('Estimado/a {{nombre}}: este año presentamos en primicia nuestra nueva gama de tomas de fuerza eléctricas y soluciones hidráulicas para la electrificación de vehículos industriales. Nos encantará enseñársela en persona.')
            . mb_details([['&#128205;', 'Lugar', 'Messe München · Múnich (Alemania)'], ['&#127915;', 'Stand', 'Pabellón B5 · Stand 320'], ['&#128337;', 'Horario', 'De 9:30 a 18:30 h']])
            . mb_button('Confirmar asistencia', $web)
            . mb_p('¿Necesita una entrada gratuita? Responda a este correo y se la enviaremos.', 13, M_MUTED, 0), $img('ev.jpg')],
    ];
    $n = 0;
    foreach ($mails as [$name, $theme, $subject, $pre, $survey, $body, $header]) {
        if (val('SELECT id FROM emails WHERE name = ?', [$name])) continue;
        q('INSERT INTO emails (name, subject, preheader, theme, header_image, body, lang, survey_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$name, $subject, $pre, $theme, $header, $body, 'es', $survey, now(), now()]);
        $n++;
    }
    return $n;
}
