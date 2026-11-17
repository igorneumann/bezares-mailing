<?php
/* Datos iniciales: ajustes de Bezares, encuesta NPS y dos correos de ejemplo basados en los originales. */

function seed_defaults(string $adminEmail, string $baseUrl = ''): void
{
    $color = '#00567d';
    @copy(ROOT . '/assets/logo-bezares-mailing.png', ROOT . '/uploads/logo.png');
    $settings = [
        'company' => 'BEZARES SA', 'address' => "Av. de las Retamas, 145 | Casarrubios del Monte, Toledo | 45950\nSpain",
        'color' => $color, 'logo' => is_file(ROOT . '/uploads/logo.png') ? 'logo.png' : '',
        'base_url' => $baseUrl !== '' ? $baseUrl : detect_base_url(), 'force_https' => str_starts_with($baseUrl, 'https://') ? '1' : '0', 'from_name' => 'Bezares Mailing', 'from_email' => '', 'reply_to' => '',
        'driver' => 'smtp', 'smtp_host' => '', 'smtp_port' => '587', 'smtp_secure' => 'tls', 'smtp_user' => '', 'smtp_pass' => '',
        'batch' => '20', 'hourly_limit' => '0', 'cron_key' => token(12),
        'unsub_text' => 'Si no desea recibir más correos nuestros, puede [darse de baja] / [Unsubscribe].',
        'social_web' => 'https://bezares.com', 'social_linkedin' => 'https://es.linkedin.com/company/bezares-sa',
        'social_x' => 'https://twitter.com/Bezares_SA', 'social_facebook' => 'https://www.facebook.com/Bezares.SA/',
        'social_youtube' => 'https://www.youtube.com/channel/UCdQyMj7T-kc2sKd-71_CZug',
    ];
    foreach ($settings as $k => $v) set_setting($k, $v);
    set_setting('schema', '6');

    // Encuesta NPS (basada en survey.bezares.com/285343)
    $sid = insert('INSERT INTO surveys (slug, title, description, thanks, max_per_contact, active, is_public, created_at) VALUES (?,?,?,?,?,?,?,?)',
        ['nps', "Bezares' NPS", '', '¡Muchas gracias por su respuesta! Su opinión nos ayuda a seguir mejorando.', 1, 1, 0, now()]);
    q('INSERT INTO questions (survey_id, position, type, label, options, is_required) VALUES (?,?,?,?,?,?)',
        [$sid, 1, 'nps', 'Basándose en su experiencia, ¿con qué probabilidad recomendaría Bezares?', '', 1]);
    q('INSERT INTO questions (survey_id, position, type, label, options, is_required) VALUES (?,?,?,?,?,?)',
        [$sid, 2, 'textarea', '(Opcional) ¿Cuál es el principal motivo de su puntuación?', '', 0]);

    // Correo NPS
    $nps = '<p>Hola,</p>'
        . '<p>En Bezares trabajamos continuamente para mejorar nuestros productos y el servicio que ofrecemos.</p>'
        . '<p>Por eso, nos gustaría conocer tu opinión respondiendo a una única pregunta. Basta con hacer clic en una nota:</p>'
        . '{{encuesta}}'
        . '<p>Solo te llevará unos segundos y tu respuesta nos ayudará a seguir mejorando.</p>'
        . '<p>Muchas gracias por tu confianza y por ayudarnos a hacerlo mejor.</p>'
        . '<p>Un saludo,<br><strong>Equipo Bezares</strong></p>';
    q('INSERT INTO emails (name, subject, preheader, theme, header_image, body, survey_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        ['Encuesta NPS', "Bezares' NPS", '¿Nos ayudas con una pregunta?', 'comunicado', '', $nps, $sid, now(), now()]);

    // Newsletter de ejemplo
    $b = 'https://bezares.com/';
    $u = 'https://bezares.com/wp-content/uploads/2026/10/';
    $news = block_article('Bezares to Participate in bauma CHINA 2026', $b . 'bezares-to-participate-in-bauma-china-2026-through-zhejiang-bezares-power-transmission/',
            $u . 'BaumaChina.jpg', 'Bezares is pleased to announce its participation in bauma CHINA 2026, one of Asia’s leading international trade fairs for the construction machinery, mining, and industrial vehicle sectors, through its local subsidiary Zhejiang Bezares Power Transmission.', $color)
        . block_article('Bezares to Participate in FENATRAN 2026', $b . 'bezares-to-participate-in-fenatran-2026-through-bezares-ter-brasil/',
            $u . 'Fenatran-870x1073.jpg', 'Bezares is pleased to announce its participation in FENATRAN 2026, one of Latin America’s leading trade fairs for the road freight transport industry, through its local subsidiary Bezares TER Brasil.', $color)
        . '<div style="border-top:3px solid ' . $color . ';font-size:1px;line-height:1px;margin:0 0 20px;">&nbsp;</div>'
        . block_columns(
            ['FR Piston Pump Manufacturing Improvement: Integration of Grinding into the Machining Process', $b . 'fr-piston-pump-manufacturing-improvement-integration-of-grinding-into-the-machining-process/',
                $u . 'Pumps_2026-870x870.jpg', 'We continue moving forward in optimizing our manufacturing processes and driving continuous improvement…'],
            ['Bezares Branch Meeting 2026: Driving Ideas, Growing Together', $b . 'bezares-branch-meeting-2026-driving-ideas-growing-together-a-successful-gathering-in-toledo/',
                $u . 'BBM26final-870x580.jpg', 'Three days of collaboration, shared vision, and inspiration bring Bezares’ international teams closer together…'], $color)
        . '<div style="border-top:3px solid ' . $color . ';font-size:1px;line-height:1px;margin:10px 0 20px;">&nbsp;</div>'
        . '<h2 style="margin:0 0 12px;text-align:center;font-size:24px;color:' . $color . ';">About Bezares SA</h2>'
        . '<p style="margin:0 0 14px;color:#848484;">We are manufacturers of a wide range of auxiliary hydraulic equipment for trucks and industrial vehicles such as PTO (Power Takeoff), hydraulic pumps, fittings, winches, Power Packs, complete hydraulic systems, hydraulic adapters and other parts for all major trucks and industrial vehicles brands and models.</p>'
        . '<p style="margin:0 0 14px;color:#848484;"><strong>Global reference, 100% Spanish</strong> with a <strong>wide network of distributors</strong> in <strong>over 100 countries</strong>.</p>'
        . block_button('LEARN MORE', 'https://bezares.com', $color);
    q('INSERT INTO emails (name, subject, preheader, theme, header_image, body, survey_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        ['Newsletter (ejemplo)', 'Latest news from Bezares SA', 'Bezares news', 'newsletter',
            'https://mailing.bezares.com/media/images/bezares-newsletter-2026-2.png', $news, null, now(), now()]);

    // Copia a la biblioteca propia las imágenes que estaban en el servidor antiguo (si sigue accesible).
    // Si no se puede, los correos apuntan de momento al servidor antiguo y la pestaña «Imágenes» ofrece copiarlas.
    $old = 'https://mailing.bezares.com/media/images/';
    $reachable = true;
    try {
        media_fetch_url($old . 'encabezado-bezares.jpg'); // cabecera del correo NPS (el tema «Comunicado» la usa sola)
    } catch (Throwable $e) {
        $reachable = false;
        q("UPDATE emails SET header_image = ? WHERE theme = 'comunicado'", [$old . 'encabezado-bezares.jpg']);
    }
    if ($reachable) {
        try { media_localize([$old . 'bezares-newsletter-2026-2.png']); } catch (Throwable $e) {}
    }

    q("UPDATE emails SET lang = 'en' WHERE name = 'Newsletter (ejemplo)'");
    nps_bilingual_setup(); // traducción de la encuesta y correo «Encuesta NPS (en)»
    nps_notify_setup();    // aviso por correo de las notas de 6 o menos

    q('INSERT INTO contacts (email, nombre, apellidos, empresa, tags, status, token, created_at) VALUES (?,?,?,?,?,?,?,?)',
        [$adminEmail, 'Administrador', '', 'Bezares', tags_pack('pruebas'), 'subscribed', token(), now()]);
}
