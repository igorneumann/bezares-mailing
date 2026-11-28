<?php
declare(strict_types=1);
/* Tipos de pregunta de las encuestas: cómo se muestran, cómo se validan, cuándo se ven (condiciones) y sus estadísticas.
   El mismo archivo está en Bezares Mailing (lib/surveyform.php) y en Lumen (app/mailing/lib/surveyform.php, con namespace).

   Cada pregunta guarda en questions.config (JSON) lo propio de su tipo:
     help, help_en          texto de ayuda bajo la pregunta
     min, max, lo, hi,      escala propia y deslizador: rango y textos de los extremos (lo_en, hi_en)
     unit                   deslizador: unidad («%», «€»…)
     rows, rows_en          matriz: filas (una por línea); las columnas son las opciones
     cond_q, cond_op,       condición: se muestra solo si la pregunta cond_q (id) cumple cond_op (eq, ne, le, ge) con cond_v
     cond_v
   Las respuestas se guardan como texto legible (lo que se exporta): opciones en español, «Fila: Columna | …» en la matriz,
   «A > B > C» al ordenar, AAAA-MM-DD en las fechas. */

function sf_types(): array
{
    return [
        'nps' => ['Escala 0–10 (NPS)', '📊', 'Valoración'],
        'rating' => ['Estrellas 1–5', '⭐', 'Valoración'],
        'faces' => ['Caras (satisfacción)', '😊', 'Valoración'],
        'scale' => ['Escala propia', '🎚️', 'Valoración'],
        'slider' => ['Deslizador', '↔️', 'Valoración'],
        'matrix' => ['Matriz', '▦', 'Valoración'],
        'yesno' => ['Sí / No', '👍', 'Elección'],
        'radio' => ['Opción única', '◉', 'Elección'],
        'checkbox' => ['Opción múltiple', '☑', 'Elección'],
        'select' => ['Desplegable', '▾', 'Elección'],
        'rank' => ['Ordenar', '⇅', 'Elección'],
        'text' => ['Texto corto', '✏️', 'Respuesta libre'],
        'textarea' => ['Texto largo', '✎', 'Respuesta libre'],
        'number' => ['Número', '#️⃣', 'Respuesta libre'],
        'date' => ['Fecha', '📅', 'Respuesta libre'],
        'section' => ['Título de sección', '🔖', 'Estructura'],
    ];
}

/** Descripción corta de cada tipo para el selector del editor. */
function sf_type_hint(string $t): string
{
    return ['nps' => 'NPS', 'rating' => 'de 1 a 5', 'faces' => '5 caras', 'scale' => '1–N con etiquetas', 'slider' => '0–100', 'matrix' => 'varias filas, una escala',
        'select' => 'listas largas', 'rank' => 'por preferencia', 'section' => 'texto, sin respuesta'][$t] ?? '';
}

/** Tipos nuevos (para marcarlos en el editor). */
const SF_NEW = ['faces', 'scale', 'slider', 'matrix', 'yesno', 'select', 'rank', 'number', 'date', 'section'];

function sf_cfg(array $q): array
{
    $c = json_decode((string)($q['config'] ?? ''), true);
    return is_array($c) ? $c : [];
}

function sf_lines(string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $s)), fn($o) => $o !== ''));
}

/** ¿El tipo usa la lista de opciones? */
function sf_has_options(string $t): bool
{
    return in_array($t, ['radio', 'checkbox', 'select', 'rank', 'matrix'], true);
}

/** Tipos con nota numérica (para medias y para las condiciones ≤ / ≥). */
function sf_is_numeric(string $t): bool
{
    return in_array($t, ['nps', 'rating', 'faces', 'scale', 'slider', 'number'], true);
}

/** Rango de los tipos de escala: [mínimo, máximo]. */
function sf_range(array $q): array
{
    $c = sf_cfg($q);
    switch ($q['type']) {
        case 'nps': return [0, 10];
        case 'rating': case 'faces': return [1, 5];
        case 'scale':
            $min = (int)($c['min'] ?? 1) === 0 ? 0 : 1;
            return [$min, max($min + 2, min(10, (int)($c['max'] ?? 5)))];
        case 'slider':
            $min = (int)($c['min'] ?? 0);
            return [$min, max($min + 1, (int)($c['max'] ?? 100))];
    }
    return [0, 0];
}

/** Textos fijos de los tipos nuevos en los dos idiomas. */
function sf_t(string $k, string $lang): string
{
    $t = [
        'yes' => ['Sí', 'Yes'], 'no' => ['No', 'No'], 'choose' => ['Elija una opción', 'Choose an option'],
        'rank_help' => ['Ordene con las flechas: arriba lo que más le importa.', 'Use the arrows to order: most important at the top.'],
        'up' => ['Subir', 'Move up'], 'down' => ['Bajar', 'Move down'],
        'next' => ['Siguiente', 'Next'], 'back' => ['Atrás', 'Back'], 'of' => ['de', 'of'],
        'bad_number' => ['Escriba un número.', 'Please enter a number.'], 'bad_date' => ['Escriba una fecha válida.', 'Please enter a valid date.'],
    ];
    return $t[$k][$lang === 'en' ? 1 : 0] ?? $k;
}

/** Texto de un campo de config en el idioma del visitante (si falta el inglés, el español). */
function sf_cfg_text(array $c, string $k, string $lang): string
{
    $en = trim((string)($c[$k . '_en'] ?? ''));
    return $lang === 'en' && $en !== '' ? $en : trim((string)($c[$k] ?? ''));
}

const SF_FACES = [1 => '😞', 2 => '🙁', 3 => '😐', 4 => '🙂', 5 => '😄'];

/** Limpia la respuesta que llega del formulario según el tipo. Devuelve '' si no vale o no se ha contestado. */
function sf_value(array $q, $raw): string
{
    $t = (string)$q['type'];
    $opts = sf_lines((string)($q['options'] ?? ''));
    if ($t === 'section') return '';
    if ($t === 'checkbox') {
        return implode(' | ', array_values(array_intersect($opts, array_map('strval', array_filter((array)$raw, 'is_scalar')))));
    }
    if ($t === 'matrix') {
        $rows = sf_lines((string)(sf_cfg($q)['rows'] ?? ''));
        $out = [];
        foreach ((array)$raw as $i => $v) {
            if (is_scalar($v) && isset($rows[(int)$i]) && in_array((string)$v, $opts, true)) $out[(int)$i] = $rows[(int)$i] . ': ' . $v;
        }
        ksort($out);
        return implode(' | ', $out);
    }
    if ($t === 'rank') {
        $parts = is_array($raw) ? array_map('strval', $raw) : explode('|', (string)$raw);
        $order = [];
        foreach ($parts as $p) if (in_array($p, $opts, true) && !in_array($p, $order, true)) $order[] = $p;
        if (!$order) return '';
        foreach ($opts as $o) if (!in_array($o, $order, true)) $order[] = $o; // las que falten, al final
        return implode(' > ', $order);
    }
    $v = is_scalar($raw) ? trim((string)$raw) : '';
    if ($v === '') return '';
    switch ($t) {
        case 'nps': case 'rating': case 'faces': case 'scale': case 'slider':
            [$min, $max] = sf_range($q);
            return preg_match('/^-?\d+$/', $v) && (int)$v >= $min && (int)$v <= $max ? (string)(int)$v : '';
        case 'yesno':
            return in_array($v, ['Sí', 'No'], true) ? $v : '';
        case 'radio': case 'select':
            return in_array($v, $opts, true) ? $v : '';
        case 'number':
            $v = str_replace(',', '.', $v);
            return is_numeric($v) && strlen($v) <= 20 ? (string)(0 + $v) : '';
        case 'date':
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            return $d && $d->format('Y-m-d') === $v ? $v : '';
        case 'textarea':
            return mb_substr($v, 0, 5000);
    }
    return mb_substr($v, 0, 500);
}

/** ¿Se muestra la pregunta? (su condición se cumple con las respuestas dadas). Las respuestas de las ocultas ya están vacías. */
function sf_visible(array $q, array $values): bool
{
    $c = sf_cfg($q);
    $src = (int)($c['cond_q'] ?? 0);
    if (!$src) return true;
    $v = (string)($values[$src] ?? '');
    if ($v === '') return false;
    $want = trim((string)($c['cond_v'] ?? ''));
    $op = (string)($c['cond_op'] ?? 'eq');
    if ($op === 'le' || $op === 'ge') {
        if (!is_numeric($v) || !is_numeric(str_replace(',', '.', $want))) return false;
        $w = (float)str_replace(',', '.', $want);
        return $op === 'le' ? (float)$v <= $w : (float)$v >= $w;
    }
    $parts = preg_split('/ \| | > /', $v);
    $hit = in_array($want, $parts, true) || mb_strtolower($v) === mb_strtolower($want);
    return $op === 'ne' ? !$hit : $hit;
}

/** Recorre las preguntas en orden: limpia cada respuesta y vacía las de las preguntas que no se muestran. */
function sf_values(array $questions, array $in): array
{
    $values = [];
    foreach ($questions as $q) {
        $v = sf_value($q, $in['q' . $q['id']] ?? '');
        $values[$q['id']] = sf_visible($q, $values) ? $v : '';
    }
    return $values;
}

/** Errores de validación: obligatorias sin contestar (solo las visibles) y formatos. */
function sf_errors(array $questions, array $values, array $in, string $lang, string $requiredMsg): array
{
    $err = [];
    foreach ($questions as $q) {
        if ($q['type'] === 'section' || !sf_visible($q, $values)) continue;
        $raw = $in['q' . $q['id']] ?? '';
        if ($values[$q['id']] === '' && is_scalar($raw) && trim((string)$raw) !== '') {
            if ($q['type'] === 'number') { $err[$q['id']] = sf_t('bad_number', $lang); continue; }
            if ($q['type'] === 'date') { $err[$q['id']] = sf_t('bad_date', $lang); continue; }
        }
        if (!empty($q['is_required']) && $values[$q['id']] === '') $err[$q['id']] = $requiredMsg;
    }
    return $err;
}

/**
 * HTML de una pregunta del formulario público.
 * $o: label (texto ya traducido), opts (valor => texto), name (nombre del campo), lowhigh ([texto bajo, texto alto] de NPS y estrellas),
 *     auto (autocomplete), required (texto del asterisco), error.
 */
function sf_field(array $q, string $v, string $lang, array $o): string
{
    $t = (string)$q['type'];
    $c = sf_cfg($q);
    $n = $o['name'];
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $help = sf_cfg_text($c, 'help', $lang);
    $cond = !empty($c['cond_q']) ? ' data-cond="' . $h(json_encode(['q' => (int)$c['cond_q'], 'op' => (string)($c['cond_op'] ?? 'eq'), 'v' => (string)($c['cond_v'] ?? '')], JSON_UNESCAPED_UNICODE)) . '"' : '';
    $attrs = ' data-qid="' . (int)$q['id'] . '" data-type="' . $h($t) . '"' . $cond . (!empty($q['is_required']) ? ' data-req="1"' : '');
    if ($t === 'section') {
        return '<fieldset class="sf-section"' . $attrs . '><h2>' . $h($o['label']) . '</h2>' . ($help !== '' ? '<p class="sf-help">' . nl2br($h($help)) . '</p>' : '') . '</fieldset>';
    }
    $out = '<fieldset class="' . (!empty($o['error']) ? 'bad' : '') . '"' . $attrs . '><legend>' . $h($o['label']) . (!empty($q['is_required']) ? ' <em>*</em>' : '') . '</legend>'
        . ($help !== '' ? '<p class="sf-help">' . nl2br($h($help)) . '</p>' : '');
    $radio = fn($val, $inner, $cls = '') => '<label' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '><input type="radio" name="' . $n . '" value="' . $h($val) . '"' . ($v === (string)$val ? ' checked' : '') . '><span>' . $inner . '</span></label>';
    $ends = function (string $lo, string $hi) use ($h) {
        return $lo !== '' || $hi !== '' ? '<div class="ends"><span>' . $h($lo) . '</span><span>' . $h($hi) . '</span></div>' : '';
    };
    switch ($t) {
        case 'nps': case 'scale':
            [$min, $max] = sf_range($q);
            $out .= '<div class="scale n' . ($max - $min + 1) . '">';
            for ($i = $min; $i <= $max; $i++) $out .= $radio($i, (string)$i);
            $out .= '</div>' . ($t === 'nps' ? $ends($o['lowhigh'][0] ?? '', $o['lowhigh'][1] ?? '') : $ends(sf_cfg_text($c, 'lo', $lang), sf_cfg_text($c, 'hi', $lang)));
            break;
        case 'rating':
            $out .= '<div class="sf-stars">';
            for ($i = 5; $i >= 1; $i--) $out .= '<input type="radio" id="' . $n . '_' . $i . '" name="' . $n . '" value="' . $i . '"' . ($v === (string)$i ? ' checked' : '') . '><label for="' . $n . '_' . $i . '" title="' . $i . '">★</label>';
            $out .= '</div>' . $ends($o['lowhigh'][0] ?? '', $o['lowhigh'][1] ?? '');
            break;
        case 'faces':
            $out .= '<div class="sf-faces">';
            foreach (SF_FACES as $i => $f) $out .= $radio($i, $f);
            $out .= '</div>';
            break;
        case 'slider':
            [$min, $max] = sf_range($q);
            $unit = (string)($c['unit'] ?? '');
            $cur = $v !== '' ? (int)$v : (int)round(($min + $max) / 2);
            $out .= '<div class="sf-slider"><input type="range" min="' . $min . '" max="' . $max . '" step="1" value="' . $cur . '" data-sf-name="' . $n . '"' . ($v !== '' ? ' name="' . $n . '"' : '') . ' data-unit="' . $h($unit) . '" aria-label="' . $h($o['label']) . '">'
                . '<output>' . ($v !== '' ? $h($v . ($unit !== '' ? ' ' . $unit : '')) : '—') . '</output></div>'
                . '<noscript><input type="number" name="' . $n . '" min="' . $min . '" max="' . $max . '" value="' . $h($v) . '"></noscript>'
                . $ends(sf_cfg_text($c, 'lo', $lang) ?: $min . ($unit !== '' ? ' ' . $unit : ''), sf_cfg_text($c, 'hi', $lang) ?: $max . ($unit !== '' ? ' ' . $unit : ''));
            break;
        case 'matrix':
            $rowsEs = sf_lines((string)($c['rows'] ?? ''));
            $rowsEn = $lang === 'en' ? sf_lines((string)($c['rows_en'] ?? '')) : [];
            $chosen = [];
            foreach ($v !== '' ? explode(' | ', $v) : [] as $part) { $p = strrpos($part, ': '); if ($p !== false) $chosen[substr($part, 0, $p)] = substr($part, $p + 2); }
            $out .= '<div class="sf-matrix"><table><thead><tr><th></th>';
            foreach ($o['opts'] as $val => $text) $out .= '<th>' . $h($text) . '</th>';
            $out .= '</tr></thead><tbody>';
            foreach ($rowsEs as $ri => $row) {
                $label = count($rowsEn) === count($rowsEs) ? $rowsEn[$ri] : $row;
                $out .= '<tr><td>' . $h($label) . '</td>';
                foreach ($o['opts'] as $val => $text) $out .= '<td><input type="radio" name="' . $n . '[' . $ri . ']" value="' . $h($val) . '" aria-label="' . $h($label . ': ' . $text) . '"' . (($chosen[$row] ?? null) === (string)$val ? ' checked' : '') . '></td>';
                $out .= '</tr>';
            }
            $out .= '</tbody></table></div>';
            break;
        case 'yesno':
            $out .= '<div class="sf-yesno">' . $radio('Sí', '👍 ' . $h(sf_t('yes', $lang))) . $radio('No', '👎 ' . $h(sf_t('no', $lang))) . '</div>';
            break;
        case 'radio': case 'checkbox':
            $chosen = $t === 'checkbox' ? explode(' | ', $v) : [$v];
            $out .= '<div class="opts">';
            foreach ($o['opts'] as $val => $text) $out .= '<label><input type="' . $t . '" name="' . $n . ($t === 'checkbox' ? '[]' : '') . '" value="' . $h($val) . '"' . (in_array((string)$val, $chosen, true) ? ' checked' : '') . '> ' . $h($text) . '</label>';
            $out .= '</div>';
            break;
        case 'select':
            $out .= '<select name="' . $n . '" aria-label="' . $h($o['label']) . '"><option value="">' . $h(sf_t('choose', $lang)) . '</option>';
            foreach ($o['opts'] as $val => $text) $out .= '<option value="' . $h($val) . '"' . ($v === (string)$val ? ' selected' : '') . '>' . $h($text) . '</option>';
            $out .= '</select>';
            break;
        case 'rank':
            $order = $v !== '' ? explode(' > ', $v) : array_keys($o['opts']);
            $out .= '<p class="sf-help">' . $h(sf_t('rank_help', $lang)) . '</p><ol class="sf-rank" data-sf-rank>';
            foreach ($order as $val) {
                if (!isset($o['opts'][$val])) continue;
                $out .= '<li data-v="' . $h($val) . '"><b></b><span>' . $h($o['opts'][$val]) . '</span><button type="button" data-up aria-label="' . $h(sf_t('up', $lang)) . '">↑</button><button type="button" data-down aria-label="' . $h(sf_t('down', $lang)) . '">↓</button></li>';
            }
            $out .= '</ol><input type="hidden" name="' . $n . '" value="' . $h(implode('|', array_filter($order, fn($x) => isset($o['opts'][$x])))) . '">';
            break;
        case 'number':
            $out .= '<input type="number" step="any" inputmode="decimal" name="' . $n . '" value="' . $h($v) . '" aria-label="' . $h($o['label']) . '" class="sf-short">';
            break;
        case 'date':
            $out .= '<input type="date" name="' . $n . '" value="' . $h($v) . '" aria-label="' . $h($o['label']) . '" class="sf-short">';
            break;
        case 'textarea':
            $out .= '<textarea name="' . $n . '" rows="4" aria-label="' . $h($o['label']) . '">' . $h($v) . '</textarea>';
            break;
        default:
            $out .= '<input type="' . ($t === 'f_phone' ? 'tel' : 'text') . '" name="' . $n . '" value="' . $h($v) . '" aria-label="' . $h($o['label']) . '" autocomplete="' . $h($o['auto'] ?? 'on') . '">';
    }
    if (!empty($o['error'])) $out .= '<p class="error">' . $h($o['error']) . '</p>';
    return $out . '</fieldset>';
}

/** Estadísticas de una pregunta a partir de sus respuestas (no vacías). Se añaden a la fila de la pregunta. */
function sf_stats(array $q, array $vals): array
{
    $t = (string)$q['type'];
    $s = ['n' => count($vals)];
    $opts = sf_lines((string)($q['options'] ?? ''));
    if (in_array($t, ['nps', 'rating', 'faces', 'scale'], true)) {
        [$min, $max] = sf_range($q);
        $dist = array_fill($min, $max - $min + 1, 0);
        foreach ($vals as $v) if (isset($dist[(int)$v])) $dist[(int)$v]++;
        $s['dist'] = $dist;
        $s['avg'] = $s['n'] ? array_sum(array_map('intval', $vals)) / $s['n'] : null;
        if ($t === 'nps') {
            $s['promoters'] = $dist[9] + $dist[10];
            $s['passives'] = $dist[7] + $dist[8];
            $s['detractors'] = $s['n'] - $s['promoters'] - $s['passives'];
            $s['nps'] = $s['n'] ? (int)round(($s['promoters'] - $s['detractors']) / $s['n'] * 100) : null;
        }
    } elseif ($t === 'slider' || $t === 'number') {
        $nums = array_map('floatval', $vals);
        $s['avg'] = $nums ? array_sum($nums) / count($nums) : null;
        $s['min'] = $nums ? min($nums) : null;
        $s['max'] = $nums ? max($nums) : null;
        $s['texts'] = [];
    } elseif ($t === 'yesno' || $t === 'radio' || $t === 'checkbox' || $t === 'select') {
        $dist = [];
        foreach ($t === 'yesno' ? ['Sí', 'No'] : $opts as $o) $dist[$o] = 0;
        foreach ($vals as $v) foreach (explode(' | ', $v) as $p) $dist[$p] = ($dist[$p] ?? 0) + 1;
        $s['dist'] = $dist;
    } elseif ($t === 'rank') {
        $sum = array_fill_keys($opts, 0);
        $cnt = array_fill_keys($opts, 0);
        foreach ($vals as $v) foreach (explode(' > ', $v) as $i => $p) if (isset($sum[$p])) { $sum[$p] += $i + 1; $cnt[$p]++; }
        $rank = [];
        foreach ($opts as $o) if ($cnt[$o]) $rank[$o] = $sum[$o] / $cnt[$o];
        asort($rank);
        $s['rank'] = $rank;
    } elseif ($t === 'matrix') {
        $rows = sf_lines((string)(sf_cfg($q)['rows'] ?? ''));
        $m = [];
        foreach ($rows as $r) $m[$r] = array_fill_keys($opts, 0);
        foreach ($vals as $v) foreach (explode(' | ', $v) as $part) {
            $p = strrpos($part, ': ');
            if ($p === false) continue;
            [$r, $col] = [substr($part, 0, $p), substr($part, $p + 2)];
            if (isset($m[$r][$col])) $m[$r][$col]++;
        }
        $s['matrix'] = $m;
    } else {
        $s['texts'] = $vals;
    }
    return $s;
}

/** Etiqueta de un valor en las barras de resultados (las caras, con su icono). */
function sf_dist_label(array $q, $k): string
{
    return $q['type'] === 'faces' && isset(SF_FACES[(int)$k]) ? SF_FACES[(int)$k] . ' ' . $k : (string)$k;
}

/** Bloque de resultados de los tipos que no son barras ni textos (matriz, ordenar, número y deslizador). HTML. */
function sf_result_extra(array $q): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $num = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
    if (isset($q['matrix'])) {
        $cols = $q['matrix'] ? array_keys(reset($q['matrix'])) : [];
        $o = '<div class="sf-rmatrix"><table><thead><tr><th></th>';
        foreach ($cols as $c) $o .= '<th>' . $h($c) . '</th>';
        $o .= '</tr></thead><tbody>';
        foreach ($q['matrix'] as $r => $counts) {
            $tot = max(1, array_sum($counts));
            $o .= '<tr><td>' . $h($r) . '</td>';
            foreach ($counts as $n) $o .= '<td><span style="--p:' . round($n / $tot * 100) . '%">' . $n . '</span></td>';
            $o .= '</tr>';
        }
        return $o . '</tbody></table></div>';
    }
    if (isset($q['rank'])) {
        if (!$q['rank']) return '<p class="muted">Sin respuestas todavía.</p>';
        $o = '<ol class="sf-rrank">';
        foreach ($q['rank'] as $opt => $avg) $o .= '<li><b>' . $h($opt) . '</b> <span class="muted">posición media ' . $num($avg) . '</span></li>';
        return $o . '</ol>';
    }
    if (array_key_exists('min', $q)) {
        return $q['n'] ? '<p>Media: <b>' . $num($q['avg']) . '</b> · Mínimo: ' . $num($q['min']) . ' · Máximo: ' . $num($q['max']) . '</p>' : '<p class="muted">Sin respuestas todavía.</p>';
    }
    return '';
}

/** Lee la configuración de una pregunta desde el formulario del editor. $ref: id de la pregunta de la condición (ya resuelto). */
function sf_cfg_from_post(array $row, string $type, int $ref): array
{
    $s = fn($k, $max = 500) => mb_substr(trim(strip_tags((string)($row[$k] ?? ''))), 0, $max);
    $c = array_filter(['help' => $s('help'), 'help_en' => $s('help_en')], fn($v) => $v !== '');
    if ($type === 'scale' || $type === 'slider') { // campos con prefijo: s_ (escala propia) y d_ (deslizador)
        $p = $type === 'scale' ? 's_' : 'd_';
        $c['min'] = (int)($row[$p . 'min'] ?? ($type === 'scale' ? 1 : 0));
        $c['max'] = (int)($row[$p . 'max'] ?? ($type === 'scale' ? 5 : 100));
        foreach (['lo', 'hi', 'lo_en', 'hi_en'] as $k) if ($s($p . $k, 60) !== '') $c[$k] = $s($p . $k, 60);
        if ($type === 'slider' && $s('d_unit', 10) !== '') $c['unit'] = $s('d_unit', 10);
    }
    if ($type === 'matrix') {
        $c['rows'] = $s('rows', 2000);
        if ($s('rows_en', 2000) !== '') $c['rows_en'] = $s('rows_en', 2000);
    }
    if ($ref && in_array($row['cond_op'] ?? '', ['eq', 'ne', 'le', 'ge'], true) && $s('cond_v', 200) !== '') {
        $c += ['cond_q' => $ref, 'cond_op' => $row['cond_op'], 'cond_v' => $s('cond_v', 200)];
    }
    return $c;
}

/** Vista previa en directo del editor: las preguntas tal como están en el formulario, sin guardar (ids ficticios 1, 2, 3…). */
function sf_preview_questions(array $rows, array $types): array
{
    $out = [];
    $map = [];
    $id = 0;
    foreach ($rows as $i => $row) {
        if (!is_array($row)) continue;
        $label = trim((string)($row['label'] ?? ''));
        $type = isset($types[$row['type'] ?? '']) ? (string)$row['type'] : 'text';
        if ($label === '') $label = $type === 'section' ? 'Título de la sección' : '(Pregunta sin texto)';
        $map[(string)$i] = ++$id;
        $cfg = sf_cfg_from_post($row, $type, $map[(string)($row['cond_ref'] ?? '')] ?? 0);
        if (($cfg['cond_q'] ?? 0) === $id) unset($cfg['cond_q'], $cfg['cond_op'], $cfg['cond_v']);
        $out[] = ['id' => $id, 'type' => $type, 'label' => $label, 'label_en' => trim((string)($row['label_en'] ?? '')), 'options' => trim((string)($row['options'] ?? '')),
            'options_en' => trim((string)($row['options_en'] ?? '')), 'is_required' => empty($row['required']) || $type === 'section' ? 0 : 1, 'config' => json_encode($cfg, JSON_UNESCAPED_UNICODE)];
    }
    return $out;
}

/** Opciones a mostrar: [valor que se guarda (siempre el español) => texto en el idioma del visitante]. */
function sf_option_map(array $q, string $lang): array
{
    $es = sf_lines((string)($q['options'] ?? ''));
    $en = $lang === 'en' ? sf_lines((string)($q['options_en'] ?? '')) : [];
    $out = [];
    foreach ($es as $i => $o) $out[$o] = count($en) === count($es) ? $en[$i] : $o;
    return $out;
}
