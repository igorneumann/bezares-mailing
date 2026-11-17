<?php
declare(strict_types=1);

/* Lectura y escritura sencillas de CSV y Excel (.xlsx) sin dependencias. */

function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}
function xlsx_col_index(string $ref): int
{
    $n = 0;
    foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper($ref))) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n - 1;
}

/** Devuelve las filas de la primera hoja como arrays de cadenas. */
function xlsx_read(string $file): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no tiene la extensión ZIP; use CSV.');
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('No se pudo abrir el archivo Excel.');
    $strings = [];
    if (($x = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($x);
        foreach ($sx->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $r) $t .= (string)$r->t;
            $strings[] = $t;
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) { $sheet = $zip->getFromIndex($i); break; }
        }
    }
    $zip->close();
    if ($sheet === false) throw new RuntimeException('El Excel no contiene hojas.');
    $rows = [];
    $sx = simplexml_load_string($sheet);
    foreach ($sx->sheetData->row as $row) {
        $r = [];
        $i = 0;
        foreach ($row->c as $c) {
            if (isset($c['r'])) $i = xlsx_col_index((string)$c['r']);
            $type = (string)$c['t'];
            if ($type === 's') $v = $strings[(int)$c->v] ?? '';
            elseif ($type === 'inlineStr') {
                $v = (string)$c->is->t;
                foreach ($c->is->r as $rr) $v .= (string)$rr->t;
            } else $v = (string)$c->v;
            $r[$i++] = $v;
        }
        if ($r) {
            $full = array_fill(0, max(array_keys($r)) + 1, '');
            foreach ($r as $k => $v) $full[$k] = $v;
            $rows[] = $full;
        }
    }
    return $rows;
}

/** Genera un .xlsx con una sola hoja y devuelve su contenido binario. */
function xlsx_write(array $rows, string $sheetName = 'Datos'): string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no tiene la extensión ZIP; use CSV.');
    $x = fn($s) => htmlspecialchars(preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $sd = '';
    foreach (array_values($rows) as $ri => $row) {
        $sd .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($row) as $ci => $v) {
            $ref = xlsx_col($ci) . ($ri + 1);
            $style = $ri === 0 ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) $sd .= "<c r=\"$ref\"$style><v>$v</v></c>";
            else $sd .= "<c r=\"$ref\"$style t=\"inlineStr\"><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>';
        }
        $sd .= '</row>';
    }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheets><sheet name="' . $x(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' . $ns . '"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' . $ns . '"><sheetData>' . $sd . '</sheetData></worksheet>',
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) $zip->addFromString($name, $content);
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Lee un CSV detectando separador (; , tabulador) y codificación. */
function csv_read(string $file): array
{
    $raw = file_get_contents($file);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $first = strtok($raw, "\r\n") ?: '';
    $sep = ',';
    $best = 0;
    foreach ([';', ',', "\t", '|'] as $s) {
        $n = substr_count($first, $s);
        if ($n > $best) { $best = $n; $sep = $s; }
    }
    $h = fopen('php://temp', 'r+');
    fwrite($h, $raw);
    rewind($h);
    $rows = [];
    while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        if ($r === [null]) continue;
        $rows[] = array_map(fn($v) => (string)$v, $r);
    }
    fclose($h);
    return $rows;
}

/** Envía al navegador una descarga en CSV o XLSX. */
function download_table(array $rows, string $name, string $format): void
{
    if ($format === 'xlsx') {
        $bin = xlsx_write($rows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"$name.xlsx\"");
        header('Content-Length: ' . strlen($bin));
        echo $bin;
    } else {
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"$name.csv\"");
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            // Evita que Excel interprete celdas como fórmulas
            $r = array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') ? "'" . $v : $v, $r);
            fputcsv($out, $r, ';', '"', '');
        }
    }
    exit;
}
