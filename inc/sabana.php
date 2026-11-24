<?php
declare(strict_types=1);

require_once __DIR__ . '/importador.php';

/** Etiquetas de columnas de la sábana original (BASE_SELLCOM), en su orden. */
const SABANA_COLUMNAS = ['c_Folio [19]', 'c_Inventario [0]', 'c_Serie [1]', 'c_Equipo [6]', 'c_Proyecto [9]', 'CONCEPTOS', 'EXTRAVIADOS',
    'UBICACIÓN', 'SEGUIMIENTO CON', 'COMENTARIO', 'ACUERDO', 'CORDINADOR', 'AÑO'];

/**
 * Compara la sábana contra el sistema SIN guardar nada.
 * @return array propuesta: nuevos, cambios (clave "c:fila:campo"), acuerdos, mapa fila=>id, faltantes, resumen, avisos
 */
function sabana_analizar(string $path, string $hoja, ?int $uid): array
{
    $prop = ['nuevos' => [], 'cambios' => [], 'acuerdos' => [], 'mapa' => []];
    $decidir = function (int $fila, ?int $id, array $d, array $v) use (&$prop) {
        $prop['mapa'][$fila] = $id;
        $ac = isset($v['acuerdo']) ? trim(preg_replace('/\s+/u', ' ', (string)$v['acuerdo'])) : '';
        $hayAc = $ac !== '' && !in_array(mb_strtoupper($ac), IMP_NULOS, true);
        if (!$id) {
            $prop['nuevos'][$fila] = [
                'serie' => $d['serie'] ?? null, 'inventario' => $d['inventario'] ?? null, 'folio' => $d['folio'] ?? null,
                'tipo' => valor_legible('tipo_id', $d['tipo_id'] ?? null), 'etapa' => valor_legible('etapa_id', $d['etapa_id'] ?? null),
                'concepto' => valor_legible('concepto_id', $d['concepto_id'] ?? null), 'ubicacion' => valor_legible('ubicacion_id', $d['ubicacion_id'] ?? null),
            ];
            return $d;
        }
        $act = q('SELECT e.*, t.nombre AS tipo FROM equipos e LEFT JOIN tipos_equipo t ON t.id = e.tipo_id WHERE e.id = ?', [$id])->fetch();
        foreach (equipo_normalizar($d) as $k => $val) {
            $tipo = CAMPOS_EQUIPO[$k][1];
            $a = $act[$k];
            if ($a !== null) $a = ($tipo === 'date') ? (string)$a : (($tipo !== null || $k === 'anio') ? (int)$a : (string)$a);
            if ($a !== $val) {
                $prop['cambios']["c:$fila:$k"] = ['fila' => $fila, 'id' => $id, 'serie' => $act['serie'], 'tipo' => $act['tipo'],
                    'campo' => $k, 'antes' => valor_legible($k, $a), 'despues' => valor_legible($k, $val)];
            }
        }
        if ($hayAc && !q("SELECT 1 FROM acuerdos a JOIN acuerdo_equipo ae ON ae.acuerdo_id = a.id WHERE ae.equipo_id = ? AND a.descripcion = ? AND a.estatus = 'abierto'", [$id, $ac])->fetchColumn()) {
            $prop['acuerdos'][$fila] = ['id' => $id, 'serie' => $act['serie'], 'texto' => $ac];
        }
        return $d;
    };
    $r = importar_excel($path, $hoja, true, $uid, false, ['origen' => 'sabana', 'decidir' => $decidir, 'solo_ultima' => true]);

    // equipos del sistema que no vienen en la sábana (sin contar destruidos/entregados)
    $vistos = array_filter(array_unique(array_merge(array_values($prop['mapa']))));
    $sqlNo = $vistos ? ' AND e.id NOT IN (' . implode(',', array_map('intval', $vistos)) . ')' : '';
    $prop['faltantes'] = q(SQL_EQUIPO_SELECT . " WHERE COALESCE(et.es_final, 0) = 0 $sqlNo ORDER BY t.nombre, e.serie")->fetchAll();
    $prop['resumen'] = [
        'filas' => $r['filas'],
        'nuevos' => count($prop['nuevos']),
        'equipos_con_cambios' => count(array_unique(array_column($prop['cambios'], 'id'))),
        'cambios' => count($prop['cambios']),
        'acuerdos' => count($prop['acuerdos']),
        'sin_cambios' => max(0, count(array_filter($prop['mapa'])) - count(array_unique(array_merge(array_column($prop['cambios'], 'id'), array_column($prop['acuerdos'], 'id'))))),
        'faltantes' => count($prop['faltantes']),
    ];
    $prop['avisos'] = $r['avisos'];
    $prop['columnas'] = array_values(array_unique($r['columnas']));
    return $prop;
}

/**
 * Aplica solo lo aceptado de una propuesta.
 * @param array $rechazados  claves "n:fila", "c:fila:campo", "a:fila" que el usuario desmarcó
 */
function sabana_aplicar(string $path, string $hoja, array $prop, array $rechazados, ?int $uid, string $comentario): array
{
    $rech = array_flip($rechazados);
    $avisos = [];
    $aplicados = 0;
    $decidir = function (int $fila, ?int $id, array $d, array $v) use ($prop, $rech, &$avisos, &$aplicados) {
        if (!array_key_exists($fila, $prop['mapa']) || $prop['mapa'][$fila] !== $id) {
            $avisos[] = "Fila $fila: el equipo cambió desde la comparación; no se aplicó. Vuelve a comparar.";
            return null;
        }
        if (!$id) {
            if (isset($rech["n:$fila"])) return null;
            $aplicados++;
            return $d;
        }
        $keep = [];
        foreach ($d as $k => $val) {
            $key = "c:$fila:$k";
            if (isset($prop['cambios'][$key]) && !isset($rech[$key])) { $keep[$k] = $val; $aplicados++; }
        }
        $acOk = isset($prop['acuerdos'][$fila]) && !isset($rech["a:$fila"]);
        if ($acOk) $aplicados++;
        if (!$keep && !$acOk) return null;
        $keep['__acuerdo'] = $acOk;
        return $keep;
    };
    $r = importar_excel($path, $hoja, false, $uid, false, ['origen' => 'sabana', 'decidir' => $decidir, 'solo_ultima' => true, 'comentario' => $comentario]);
    $r['aplicados'] = $aplicados;
    $r['avisos_aplicar'] = $avisos;
    return $r;
}

// ---------- Sábanas pendientes (subidas pero no aplicadas) ----------
function sabana_pendiente_guardar(string $dir, array $s): void
{
    @file_put_contents($dir . '/' . $s['archivo'] . '.json', json_encode([
        'nombre' => $s['nombre'], 'hoja' => $s['hoja'], 'usuario_id' => uid(), 'fecha' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE));
}

function sabana_pendiente_leer(string $dir, string $archivo): array
{
    $m = is_file($dir . '/' . $archivo . '.json') ? (json_decode((string)file_get_contents($dir . '/' . $archivo . '.json'), true) ?: []) : [];
    return $m + ['nombre' => 'Sábana subida el ' . date('d/m/Y H:i', (int)@filemtime($dir . '/' . $archivo)), 'hoja' => null,
        'usuario_id' => null, 'fecha' => date('Y-m-d H:i:s', (int)@filemtime($dir . '/' . $archivo))];
}

/** Sábanas que se subieron y no se aplicaron ni descartaron (la más reciente primero). */
function sabana_pendientes(string $dir): array
{
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $j) {
        $arch = basename($j, '.json');
        if (!preg_match('/^[a-f0-9]{24}\.xlsx$/', $arch) || !is_file($dir . '/' . $arch)) continue;
        $m = sabana_pendiente_leer($dir, $arch);
        $out[] = ['archivo' => $arch, 'nombre' => $m['nombre'], 'fecha' => $m['fecha'],
            'usuario' => $m['usuario_id'] ? (q('SELECT nombre FROM usuarios WHERE id = ?', [$m['usuario_id']])->fetchColumn() ?: '—') : '—'];
    }
    usort($out, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    return $out;
}

/** Folios GLPI que trae una sábana (fila → folio), para medir cuántos venían y cuáles no quedaron en el sistema. */
function sabana_folios_glpi(string $path, string $hoja): array
{
    $out = [];
    try {
        $filas = (new Xlsx($path))->filas($hoja);
        $col = null;
        foreach ($filas as $i => $f) {
            foreach ($f as $c => $h) if (imp_encabezado($h) === 'folio glpi') $col = $c;
            if ($col !== null) {
                foreach (array_slice($filas, $i + 1, null, true) as $n => $r) {
                    $v = imp_folio_glpi($r[$col] ?? null);
                    if ($v !== null) $out[$n + 1] = $v;
                }
                break;
            }
            if ($i > 20) break;
        }
    } catch (Throwable $e) { /* sin folios */ }
    return $out;
}
