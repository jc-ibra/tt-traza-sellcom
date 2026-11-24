<?php
declare(strict_types=1);

/**
 * Lector mínimo de .xlsx sin dependencias (ZipArchive + SimpleXML).
 * Devuelve todos los valores como string (o null). Las fechas se devuelven como AAAA-MM-DD.
 */
final class Xlsx
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private ZipArchive $zip;
    /** @var array<string,string> nombre => ruta xml */
    private array $hojas = [];
    private array $shared = [];
    /** @var array<int,bool> índice de estilo => es fecha */
    private array $estiloFecha = [];

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo. ¿Es un Excel .xlsx válido?');
        }
        $wb = $this->xml('xl/workbook.xml');
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        if (!$wb || !$rels) throw new RuntimeException('El archivo no tiene la estructura de un Excel .xlsx.');

        $targets = [];
        foreach ($rels->children() as $r) {
            $t = self::at($r, 'Target');
            $t = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
            $targets[self::at($r, 'Id')] = $t;
        }
        foreach ($wb->children(self::NS_MAIN)->sheets->sheet as $s) {
            $rid = (string)$s->attributes(self::NS_REL)['id'];
            if (isset($targets[$rid])) $this->hojas[self::at($s, 'name')] = $targets[$rid];
        }

        if ($ss = $this->xml('xl/sharedStrings.xml')) {
            foreach ($ss->children(self::NS_MAIN)->si as $si) {
                $txt = '';
                if (isset($si->t)) $txt .= (string)$si->t;
                foreach ($si->r as $run) $txt .= (string)$run->t;
                $this->shared[] = $txt;
            }
        }

        if ($st = $this->xml('xl/styles.xml')) {
            $m = $st->children(self::NS_MAIN);
            $custom = [];
            if (isset($m->numFmts)) {
                foreach ($m->numFmts->numFmt as $nf) $custom[(int)self::at($nf, 'numFmtId')] = self::at($nf, 'formatCode');
            }
            $i = 0;
            if (isset($m->cellXfs)) {
                foreach ($m->cellXfs->xf as $xf) {
                    $this->estiloFecha[$i++] = self::esFormatoFecha((int)self::at($xf, 'numFmtId'), $custom);
                }
            }
        }
    }

    /** Atributo sin espacio de nombres (evita el contexto heredado de children($ns)). */
    private static function at(SimpleXMLElement $e, string $name): string
    {
        $a = $e->attributes();
        return isset($a[$name]) ? (string)$a[$name] : '';
    }

    private function xml(string $name): ?SimpleXMLElement
    {
        $s = $this->zip->getFromName($name);
        if ($s === false) return null;
        $x = simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        return $x ?: null;
    }

    private static function esFormatoFecha(int $id, array $custom): bool
    {
        if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47)) return true;
        if (!isset($custom[$id])) return false;
        $f = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $custom[$id]);
        return (bool)preg_match('/[dmy]/i', $f);
    }

    public function hojas(): array
    {
        return array_keys($this->hojas);
    }

    private static function colIndex(string $ref): int
    {
        $letras = preg_replace('/\d+/', '', strtoupper($ref));
        $n = 0;
        for ($i = 0, $l = strlen($letras); $i < $l; $i++) $n = $n * 26 + (ord($letras[$i]) - 64);
        return $n - 1;
    }

    /** @return array<int, array<int, ?string>> filas indexadas desde 0 */
    public function filas(string $hoja): array
    {
        if (!isset($this->hojas[$hoja])) throw new RuntimeException('No existe la hoja "' . $hoja . '".');
        $sx = $this->xml($this->hojas[$hoja]);
        if (!$sx) throw new RuntimeException('No se pudo leer la hoja "' . $hoja . '".');
        $filas = [];
        foreach ($sx->children(self::NS_MAIN)->sheetData->row as $row) {
            $fila = [];
            foreach ($row->c as $c) {
                $idx = self::colIndex(self::at($c, 'r'));
                $t = self::at($c, 't');
                $v = isset($c->v) ? (string)$c->v : null;
                if ($t === 's') {
                    $val = $this->shared[(int)$v] ?? null;
                } elseif ($t === 'inlineStr') {
                    $val = isset($c->is->t) ? (string)$c->is->t : '';
                    foreach ($c->is->r as $run) $val .= (string)$run->t;
                } elseif ($t === 'b') {
                    $val = $v === '1' ? 'TRUE' : 'FALSE';
                } elseif ($t === 'str' || $t === 'e') {
                    $val = $v;
                } elseif ($v !== null && is_numeric($v)) {
                    $s = (int)self::at($c, 's');
                    if (!empty($this->estiloFecha[$s])) {
                        $val = (new DateTime('1899-12-30'))->modify('+' . (int)floor((float)$v) . ' days')->format('Y-m-d');
                    } else {
                        $f = (float)$v;
                        $val = (floor($f) == $f && abs($f) < 1e15) ? sprintf('%.0f', $f) : $v;
                    }
                } else {
                    $val = $v;
                }
                $fila[$idx] = $val;
            }
            $filas[] = $fila;
        }
        return $filas;
    }
}
