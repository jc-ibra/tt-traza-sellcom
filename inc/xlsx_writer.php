<?php
declare(strict_types=1);

/** Escritor mínimo de .xlsx de una hoja (encabezado en negritas, filtro y panel inmovilizado). */
function xlsx_escribir(string $destino, string $hoja, array $encabezados, iterable $filas, array $anchos = []): void
{
    xlsx_escribir_libro($destino, [['nombre' => $hoja, 'enc' => $encabezados, 'filas' => $filas, 'anchos' => $anchos]]);
}

/**
 * Libro con varias hojas.
 * @param array $hojas lista de ['nombre' => string, 'enc' => array, 'filas' => iterable, 'anchos' => array, 'filtro' => bool(=true)]
 *   Una fila puede empezar con la marca '__titulo' para resaltarla (negritas), útil en hojas de resumen.
 */
function xlsx_escribir_libro(string $destino, array $hojas): void
{
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    };
    $limpia = fn($s) => preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s);

    $z = new ZipArchive();
    if ($z->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo crear el archivo.');

    $sheetsXml = $relsXml = $ctXml = $defNames = '';
    foreach (array_values($hojas) as $n => $h) {
        $idx = $n + 1;
        $nombre = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $h['nombre']), 0, 31);
        $enc = $h['enc'];
        $nc = max(1, count($enc));
        $rows = '<row r="1">';
        foreach ($enc as $i => $t) $rows .= '<c r="' . $col($i) . '1" t="inlineStr" s="1"><is><t>' . $x($t) . '</t></is></c>';
        $rows .= '</row>';
        $r = 1;
        foreach ($h['filas'] as $f) {
            $r++;
            $f = array_values($f);
            $estilo = '';
            if (($f[0] ?? null) === '__titulo') { array_shift($f); $estilo = ' s="2"'; }
            $rows .= '<row r="' . $r . '">';
            foreach ($f as $i => $v) {
                if ($v === null || $v === '') continue;
                $ref = $col($i) . $r;
                if (is_int($v)) $rows .= '<c r="' . $ref . '"' . $estilo . '><v>' . $v . '</v></c>';
                else $rows .= '<c r="' . $ref . '" t="inlineStr"' . $estilo . '><is><t xml:space="preserve">' . $x($limpia($v)) . '</t></is></c>';
            }
            $rows .= '</row>';
            if (count($f) > $nc) $nc = count($f);
        }
        $cols = '';
        for ($i = 0; $i < $nc; $i++) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($h['anchos'][$i] ?? 18) . '" customWidth="1"/>';
        $ultima = $col($nc - 1);
        $filtro = ($h['filtro'] ?? true) && count($enc) > 0;

        $z->addFromString("xl/worksheets/sheet$idx.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"' . ($n === 0 ? ' tabSelected="1"' : '') . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>' . $rows . '</sheetData>'
            . ($filtro ? '<autoFilter ref="A1:' . $ultima . max(1, $r) . '"/>' : '') . '</worksheet>');
        $sheetsXml .= '<sheet name="' . $x($nombre) . '" sheetId="' . $idx . '" r:id="rId' . $idx . '"/>';
        $relsXml .= '<Relationship Id="rId' . $idx . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $idx . '.xml"/>';
        $ctXml .= '<Override PartName="/xl/worksheets/sheet' . $idx . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        if ($filtro) $defNames .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $n . '" hidden="1">\'' . $x(str_replace("'", "''", $nombre)) . '\'!$A$1:$' . $ultima . '$' . max(1, $r) . '</definedName>';
    }
    $nh = count($hojas);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $ctXml . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheetsXml . '</sheets>' . ($defNames ? '<definedNames>' . $defNames . '</definedNames>' : '') . '</workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relsXml . '<Relationship Id="rId' . ($nh + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0D3B66"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F1"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf fontId="2" fillId="3" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
    if (!$z->close()) throw new RuntimeException('No se pudo cerrar el archivo.');
}
