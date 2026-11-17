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
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    if ($letters === '' || strlen($letters) > 3) return 1 << 30; // fuera de rango (Excel llega hasta XFD)
    $n = 0;
    foreach (str_split($letters) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n - 1;
}

/* ---------- Lectura de archivos de importación ----------
   Se leen fila a fila (generadores), sin cargar el archivo entero en memoria, y con límites explícitos para que un
   archivo preparado (bomba ZIP, millones de celdas, columnas absurdas, entidades XML) no pueda agotar el servidor. */

const IMPORT_MAX_BYTES = 20 * 1024 * 1024;        // tamaño máximo del archivo subido (CSV o XLSX comprimido)
const IMPORT_MAX_ROWS = 100000;                   // filas de datos
const IMPORT_MAX_COLS = 200;                      // las columnas de más se ignoran
const IMPORT_MAX_CELL = 1000;                     // caracteres por celda (se recorta)
const XLSX_MAX_SHEET = 60 * 1024 * 1024;          // tamaño descomprimido máximo de la hoja
const XLSX_MAX_STRINGS_BYTES = 20 * 1024 * 1024;  // tamaño descomprimido máximo de la tabla de textos
const XLSX_MAX_STRINGS = 200000;                  // número máximo de textos compartidos
const XLSX_MAX_ENTRIES = 2000;                    // archivos dentro del ZIP

function import_cell(string $v): string
{
    return mb_strlen($v) > IMPORT_MAX_CELL ? mb_substr($v, 0, IMPORT_MAX_CELL) : $v;
}
function import_too_many_rows(): RuntimeException
{
    return new RuntimeException('El archivo tiene más de ' . number_format(IMPORT_MAX_ROWS, 0, ',', '.') . ' filas. Divídalo en varios archivos.');
}

/** Copia una parte del ZIP a un archivo temporal contando los bytes reales descomprimidos (no los que declara el ZIP). */
function xlsx_extract(ZipArchive $zip, string $name, int $max): string
{
    $in = $zip->getStream($name);
    if (!$in) throw new RuntimeException('El archivo Excel está dañado.');
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $out = fopen($tmp, 'wb');
    $n = 0;
    try {
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false || $chunk === '') break;
            $n += strlen($chunk);
            if ($n > $max) throw new RuntimeException('El archivo Excel es demasiado grande una vez descomprimido.');
            fwrite($out, $chunk);
        }
    } catch (Throwable $e) {
        fclose($out); fclose($in); @unlink($tmp);
        throw $e;
    }
    fclose($out); fclose($in);
    return $tmp;
}

/** Abre un XML con XMLReader sin acceso a red ni sustitución de entidades. */
function xml_open(string $path): XMLReader
{
    $r = new XMLReader();
    if (!$r->open($path, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) throw new RuntimeException('El archivo Excel está dañado.');
    return $r;
}
function xml_read(XMLReader $r): bool
{
    $ok = @$r->read();
    // Un XLSX legítimo nunca lleva DOCTYPE: se rechaza para evitar ataques de entidades XML
    if ($ok && $r->nodeType === XMLReader::DOC_TYPE) throw new RuntimeException('El archivo Excel contiene una definición de documento no permitida.');
    return $ok;
}

/** Filas de la primera hoja de un .xlsx, una a una. */
function xlsx_rows(string $file): Generator
{
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) throw new RuntimeException('El servidor no tiene las extensiones ZIP/XMLReader; use CSV.');
    if (filesize($file) > IMPORT_MAX_BYTES) throw new RuntimeException('El archivo supera los 20 MB.');
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('No se pudo abrir el archivo Excel.');
    $tmps = [];
    try {
        if ($zip->numFiles > XLSX_MAX_ENTRIES) throw new RuntimeException('El archivo Excel no es válido.');
        // Textos compartidos
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $tmps[] = $path = xlsx_extract($zip, 'xl/sharedStrings.xml', XLSX_MAX_STRINGS_BYTES);
            $r = xml_open($path);
            $cur = null; $inPhonetic = false;
            while (xml_read($r)) {
                if ($r->nodeType === XMLReader::ELEMENT) {
                    if ($r->localName === 'si') { $cur = ''; if ($r->isEmptyElement) { $strings[] = ''; $cur = null; } }
                    elseif ($r->localName === 'rPh') $inPhonetic = !$r->isEmptyElement;
                    elseif ($r->localName === 't' && $cur !== null && !$inPhonetic && mb_strlen($cur) < IMPORT_MAX_CELL) $cur .= $r->readString();
                } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                    if ($r->localName === 'rPh') $inPhonetic = false;
                    elseif ($r->localName === 'si' && $cur !== null) {
                        if (count($strings) >= XLSX_MAX_STRINGS) throw new RuntimeException('El archivo Excel tiene demasiados textos distintos.');
                        $strings[] = import_cell($cur);
                        $cur = null;
                    }
                }
            }
            $r->close();
        }
        // Primera hoja
        $sheet = $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
        for ($i = 0; $sheet === null && $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) $sheet = $n;
        }
        if ($sheet === null) throw new RuntimeException('El Excel no contiene hojas.');
        $tmps[] = $path = xlsx_extract($zip, $sheet, XLSX_MAX_SHEET);
        $zip->close();
        $zip = null;

        $r = xml_open($path);
        $rows = 0;
        $row = null; $col = 0; $type = ''; $val = null; $inCell = false;
        $emit = function (array $cells): array {
            if (!$cells) return [];
            $out = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $k => $v) $out[$k] = $v;
            return $out;
        };
        while (xml_read($r)) {
            $t = $r->nodeType;
            if ($t === XMLReader::ELEMENT) {
                switch ($r->localName) {
                    case 'row':
                        $row = []; $col = 0;
                        if ($r->isEmptyElement) { if (++$rows > IMPORT_MAX_ROWS + 1) throw import_too_many_rows(); $row = null; }
                        break;
                    case 'c':
                        if ($row === null) break;
                        $ref = (string)$r->getAttribute('r');
                        if ($ref !== '') $col = xlsx_col_index($ref);
                        $type = (string)$r->getAttribute('t');
                        $val = null;
                        if ($r->isEmptyElement) { $col++; break; }
                        $inCell = true;
                        break;
                    case 'v':
                    case 't':
                        if ($inCell && mb_strlen((string)$val) < IMPORT_MAX_CELL) $val = (string)$val . $r->readString();
                        break;
                }
            } elseif ($t === XMLReader::END_ELEMENT) {
                if ($r->localName === 'c' && $inCell) {
                    $inCell = false;
                    if ($col >= 0 && $col < IMPORT_MAX_COLS && $val !== null) {
                        $row[$col] = import_cell($type === 's' ? ($strings[(int)$val] ?? '') : (string)$val);
                    }
                    $col++;
                } elseif ($r->localName === 'row' && $row !== null) {
                    if (++$rows > IMPORT_MAX_ROWS + 1) throw import_too_many_rows();
                    $out = $emit($row);
                    $row = null;
                    if ($out) yield $out;
                }
            }
        }
        $r->close();
    } finally {
        if ($zip) $zip->close();
        foreach ($tmps as $f) @unlink($f);
    }
}

/** Filas de un CSV, una a una. Detecta el separador (; , tabulador |) y convierte de Windows-1252 si hace falta. */
function csv_rows(string $file): Generator
{
    if (filesize($file) > IMPORT_MAX_BYTES) throw new RuntimeException('El archivo supera los 20 MB.');
    $h = fopen($file, 'rb');
    if (!$h) throw new RuntimeException('No se pudo abrir el archivo.');
    try {
        $head = (string)fread($h, 65536);
        $bom = str_starts_with($head, "\xEF\xBB\xBF");
        $first = strtok(substr($head, $bom ? 3 : 0), "\r\n") ?: '';
        $sep = ',';
        $best = 0;
        foreach ([';', ',', "\t", '|'] as $c) {
            $n = substr_count($first, $c);
            if ($n > $best) { $best = $n; $sep = $c; }
        }
        fseek($h, $bom ? 3 : 0);
        $rows = 0;
        while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            if ($r === [null]) continue;
            if (++$rows > IMPORT_MAX_ROWS + 1) throw import_too_many_rows();
            $out = [];
            foreach (array_slice($r, 0, IMPORT_MAX_COLS) as $v) {
                $v = (string)$v;
                if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
                $out[] = import_cell($v);
            }
            yield $out;
        }
    } finally {
        fclose($h);
    }
}

/** Filas de un archivo de importación según su extensión. */
function table_rows(string $file): Generator
{
    return str_ends_with(strtolower($file), '.xlsx') ? xlsx_rows($file) : csv_rows($file);
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
