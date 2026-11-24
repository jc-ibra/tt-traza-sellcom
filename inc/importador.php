<?php
declare(strict_types=1);

require_once __DIR__ . '/xlsx.php';

/** Encabezado normalizado => campo interno */
const IMP_COLUMNAS = [
    'id trazapulso' => 'id', 'codigo trazapulso' => 'id',
    'c_folio' => 'folio', 'folio' => 'folio',
    'c_inventario' => 'inventario', 'inventario' => 'inventario',
    'c_serie' => 'serie', 'serie' => 'serie',
    'c_equipo' => 'tipo', 'equipo' => 'tipo', 'tipo de equipo' => 'tipo', 'tipo' => 'tipo',
    'c_proyecto' => 'proyecto', 'proyecto' => 'proyecto',
    'conceptos' => 'concepto', 'concepto' => 'concepto',
    'extraviados' => 'extraviado', 'extraviado' => 'extraviado',
    'ubicacion' => 'ubicacion',
    'seguimiento con' => 'seguimiento',
    'comentario' => 'comentario', 'comentario / nota' => 'comentario',
    'acuerdo' => 'acuerdo',
    'cordinador' => 'coordinador', 'coordinador' => 'coordinador',
    'ano' => 'anio', 'anio' => 'anio',
    'etapa' => 'etapa', 'etapa en trazapulso' => 'etapa',
    'folio glpi' => 'folio_glpi', 'ticket glpi' => 'folio_glpi',
    'estatus de glpi' => 'estado_glpi', 'estado glpi' => 'estado_glpi', 'estado (glpi)' => 'estado_glpi',
    'sucursal' => 'sucursal',
    'guia' => 'guia', 'guias' => 'guia', 'guia(s)' => 'guia',
    'paqueteria' => 'paqueteria',
    'fecha recepcion' => 'fecha_recepcion', 'fecha de recepcion' => 'fecha_recepcion',
    'comentario del movimiento' => 'mov_comentario', 'comentario movimiento' => 'mov_comentario', 'observacion del movimiento' => 'mov_comentario',
];

/** Sinónimos para unificar catálogos */
const IMP_ALIAS = [
    'tipos_equipo' => [
        'TABLETA HIBRIDA' => 'TABLETAS HIBRIDAS',
        'ESCANER'         => 'SCANNERS',
        'SCANNER'         => 'SCANNERS',
        'PC THINCLIENT'   => 'THINCLIENTS',
        'THINCLIENT'      => 'THINCLIENTS',
        'LAPTOP'          => 'LAP TOP',
    ],
    'ubicaciones' => [
        'SIN UBICACION' => 'SIN UBICAR',
    ],
    'proyectos' => [
        'SP RECOLECCIONES' => 'SP RECOLECCION',
    ],
];

const IMP_NULOS = ['N/A', 'NA', '#N/A', '-', '--', '(EN BLANCO)', 'NULL', 'NINGUNO'];
const IMP_NO_IDENT = ['ILEGIBLE', 'SIN SERIE', 'S/N', 'SN', 'NO VISIBLE', '0', 'SIN INVENTARIO'];

function imp_quitar_acentos(string $s): string
{
    return strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Ñ' => 'N']);
}

function imp_encabezado(?string $h): string
{
    $h = mb_strtolower(imp_quitar_acentos(trim((string)$h)));
    $h = preg_replace('/\s*\[\d+\]\s*$/', '', $h);   // "c_Folio [19]" -> "c_folio"
    return trim(preg_replace('/\s+/', ' ', $h));
}

function imp_txt(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim(preg_replace('/\s+/u', ' ', $v));
    if ($v === '') return null;
    $u = mb_strtoupper($v);
    return in_array($u, IMP_NULOS, true) ? null : $u;
}

function imp_cat(string $tabla, ?string $v): ?string
{
    $v = imp_txt($v);
    if ($v === null) return null;
    $sin = imp_quitar_acentos($v);
    foreach (IMP_ALIAS[$tabla] ?? [] as $de => $a) {
        if ($sin === $de) return $a;
    }
    return $v;
}

/** Acepta AAAA-MM-DD (celda fecha) o DD/MM/AAAA (texto). */
function imp_fecha(?string $v): ?string
{
    $v = imp_txt($v);
    if ($v === null) return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

/** Folio GLPI: "13 629", "13,629" o "13.629" → "13629" (si solo son dígitos con separadores). */
function imp_folio_glpi(?string $v): ?string
{
    $v = imp_txt($v);
    if ($v === null) return null;
    $limpio = preg_replace('/[\s.,\x{00A0}]/u', '', $v);
    return preg_match('/^\d+$/', $limpio) ? $limpio : $v;
}

function imp_es_ident(?string $v): bool
{
    return $v !== null && !in_array($v, IMP_NO_IDENT, true);
}

/** Intenta obtener una fecha compromiso del texto del acuerdo ("... 25 DE NOVIEMBRE"). */
function imp_fecha_acuerdo(string $t, ?int $anio = null): ?string
{
    $meses = ['ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6, 'JULIO' => 7,
        'AGOSTO' => 8, 'SEPTIEMBRE' => 9, 'SETIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12];
    $u = imp_quitar_acentos(mb_strtoupper($t));
    $anio = $anio ?: (int)date('Y');
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $u, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match_all('/\b(\d{1,2})\s+DE\s+(' . implode('|', array_keys($meses)) . ')\b/', $u, $mm, PREG_SET_ORDER)) {
        $m = end($mm); // la última fecha mencionada suele ser el compromiso
        $mes = $meses[$m[2]];
        if (checkdate($mes, (int)$m[1], $anio)) return sprintf('%04d-%02d-%02d', $anio, $mes, $m[1]);
    }
    // "EL DIA MIERCOLES 25" sin mes -> mes actual (o el siguiente si el día ya pasó hace mucho)
    if (preg_match_all('/\b(\d{1,2})\b/', $u, $mm)) {
        $dia = (int)end($mm[1]);
        $mes = (int)date('n');
        $a = (int)date('Y');
        if ($dia < (int)date('j') - 7) {
            $mes++;
            if ($mes > 12) { $mes = 1; $a++; }
        }
        if (checkdate($mes, $dia, $a)) return sprintf('%04d-%02d-%02d', $a, $mes, $dia);
    }
    return null;
}

/**
 * Importa/actualiza equipos desde una hoja de Excel.
 * En modo simulación ($simular=true) todo se ejecuta dentro de una transacción que se revierte.
 */
/**
 * Opciones ($opc):
 *  - origen:      origen de los movimientos en bitácora (default 'importacion')
 *  - comentario:  comentario general para cada movimiento
 *  - decidir:     fn(int $fila, ?int $id, array $d, array $v): ?array  -> permite revisar/filtrar los datos de cada fila
 *                 (devolver null omite la fila; la clave '__acuerdo' => false evita vincular el acuerdo)
 *  - solo_ultima: si una serie/inventario viene repetida, solo se toma la última fila
 */
function importar_excel(string $path, string $hoja, bool $simular, ?int $uid, bool $vaciosBorran = false, array $opc = []): array
{
    $origen = $opc['origen'] ?? 'importacion';
    $decidir = $opc['decidir'] ?? null;
    $x = new Xlsx($path);
    $filas = $x->filas($hoja);

    // localizar encabezado (primera fila con al menos 3 columnas reconocidas)
    $map = [];
    $inicio = null;
    foreach ($filas as $i => $f) {
        $m = [];
        foreach ($f as $col => $h) {
            $k = imp_encabezado($h);
            if (isset(IMP_COLUMNAS[$k]) && !in_array(IMP_COLUMNAS[$k], $m, true)) $m[$col] = IMP_COLUMNAS[$k];
        }
        if (count($m) >= 2) {
            $map = $m;
            $inicio = $i + 1;
            break;
        }
        if ($i > 20) break;
    }
    if ($inicio === null) throw new RuntimeException('No se encontró una fila de encabezados reconocible (Serie, Inventario, Equipo, Conceptos...).');
    $presentes = array_values($map);
    if (!in_array('serie', $presentes, true) && !in_array('inventario', $presentes, true) && !in_array('id', $presentes, true) && !in_array('folio_glpi', $presentes, true)) {
        throw new RuntimeException('El archivo debe tener al menos la columna Serie, Inventario, FOLIO GLPI o ID TrazaPulso.');
    }

    $r = ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'omitidos' => 0, 'acuerdos_nuevos' => 0,
        'vinculos' => 0, 'filas' => 0, 'comentarios' => 0, 'avisos' => [], 'columnas' => $map, 'catalogos_nuevos' => [], 'ids' => []];

    // si una serie/inventario viene repetida, opcionalmente solo cuenta la última aparición
    $ultimaFila = [];
    if (!empty($opc['solo_ultima'])) {
        $colSerie = array_search('serie', $map, true);
        $colInv = array_search('inventario', $map, true);
        foreach (array_slice($filas, $inicio, null, true) as $n => $f) {
            $se = $colSerie !== false ? imp_txt($f[$colSerie] ?? null) : null;
            $in = $colInv !== false ? imp_txt($f[$colInv] ?? null) : null;
            $k = imp_es_ident($se) ? 'S:' . $se : (imp_es_ident($in) ? 'I:' . $in : null);
            if ($k) $ultimaFila[$k] = $n + 1;
        }
    }
    $usados = [];   // equipos ya asignados a una fila de este archivo
    $soloGlpi = in_array('folio_glpi', $presentes, true) && !in_array('serie', $presentes, true) && !in_array('inventario', $presentes, true) && !in_array('id', $presentes, true);
    $catAntes = [];
    foreach (array_keys(CATALOGOS) as $t) $catAntes[$t] = (int)q("SELECT COUNT(*) FROM `$t`")->fetchColumn();

    $vistos = [];
    $acuerdosTxt = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (array_slice($filas, $inicio, null, true) as $n => $f) {
            $fila = $n + 1; // número de fila en Excel
            $v = [];
            foreach ($map as $col => $campo) $v[$campo] = $f[$col] ?? null;
            if (!array_filter($v, fn($x) => $x !== null && trim($x) !== '')) continue;
            $r['filas']++;

            $serie = imp_txt($v['serie'] ?? null);
            $inv = imp_txt($v['inventario'] ?? null);
            $codigo = id_desde_codigo(imp_txt($v['id'] ?? null));

            // --- buscar equipo existente ---
            $id = null;
            if ($codigo && q('SELECT 1 FROM equipos WHERE id = ?', [$codigo])->fetchColumn()) {
                $id = $codigo;
            }
            if (!$id && imp_es_ident($serie)) {
                $ids = q('SELECT id, inventario FROM equipos WHERE serie = ?', [$serie])->fetchAll();
                if (count($ids) === 1) {
                    $id = (int)$ids[0]['id'];
                } elseif (count($ids) > 1) {
                    foreach ($ids as $c) if ($inv !== null && $c['inventario'] === $inv) $id = (int)$c['id'];
                    if (!$id) {
                        $r['avisos'][] = "Fila $fila: la serie $serie existe en varios equipos y no se pudo distinguir por inventario; se omitió.";
                        $r['omitidos']++;
                        continue;
                    }
                }
            }
            if (!$id && !imp_es_ident($serie) && imp_es_ident($inv)) {
                $ids = q('SELECT id FROM equipos WHERE inventario = ?', [$inv])->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) === 1) $id = (int)$ids[0];
            }
            $fglpi = imp_folio_glpi($v['folio_glpi'] ?? null);
            if (!$id && $fglpi !== null && !imp_es_ident($serie) && !imp_es_ident($inv)) {
                $ids = q('SELECT id FROM equipos WHERE folio_glpi = ?', [$fglpi])->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) === 1) $id = (int)$ids[0];
                if (!$id && $soloGlpi) {   // archivo de GLPI (sin serie/inventario): un folio desconocido no se da de alta a ciegas
                    $r['avisos'][] = "Fila $fila: el FOLIO GLPI $fglpi no corresponde a ningún equipo; se omitió.";
                    $r['omitidos']++;
                    continue;
                }
            }
            if (!$id && !imp_es_ident($serie) && !imp_es_ident($inv) && !$codigo) {
                // sin identificador: se busca un equipo igual de "sin identificador" por folio + tipo que no se haya usado ya
                $folioF = imp_txt($v['folio'] ?? null);
                $tipoF = isset($v['tipo']) ? imp_cat('tipos_equipo', $v['tipo']) : null;
                $cands = q("SELECT e.id FROM equipos e LEFT JOIN tipos_equipo t ON t.id = e.tipo_id
                    WHERE (e.serie IS NULL OR e.serie IN ('ILEGIBLE','SIN SERIE','S/N','SN','NO VISIBLE','0'))
                      AND (e.inventario IS NULL OR e.inventario IN ('ILEGIBLE','SIN INVENTARIO','0'))
                      AND e.folio <=> ? AND t.nombre <=> ? ORDER BY e.id", [$folioF, $tipoF])->fetchAll(PDO::FETCH_COLUMN);
                foreach ($cands as $cid) if (!isset($usados[(int)$cid])) { $id = (int)$cid; break; }
                if (!$id) $r['avisos'][] = "Fila $fila: sin serie ni inventario válidos (" . ($serie ?? 'vacío') . ' / ' . ($inv ?? 'vacío') . '); se registra como equipo nuevo sin identificador.';
            }
            $clave = imp_es_ident($serie) ? 'S:' . $serie : (imp_es_ident($inv) ? 'I:' . $inv : null);
            if ($clave && isset($ultimaFila[$clave]) && $ultimaFila[$clave] !== $fila) {
                $r['avisos'][] = "Fila $fila: " . substr($clave, 2) . " viene repetida; se tomó la fila {$ultimaFila[$clave]} (la última).";
                continue;
            }
            if ($clave && isset($vistos[$clave])) {
                $r['avisos'][] = "Fila $fila: " . (str_starts_with($clave, 'S:') ? 'serie' : 'inventario') . ' ' . substr($clave, 2) . " repetida (ya venía en la fila {$vistos[$clave]}); se trató como el mismo equipo y su historial muestra ambos registros.";
            }
            if ($clave) $vistos[$clave] = $fila;

            // --- construir datos solo con las columnas presentes ---
            $d = [];
            if (array_key_exists('serie', $v)) $d['serie'] = imp_txt($v['serie']);
            if (array_key_exists('inventario', $v)) $d['inventario'] = $inv;
            if (array_key_exists('folio', $v)) $d['folio'] = imp_txt($v['folio']);
            if (array_key_exists('tipo', $v)) $d['tipo_id'] = catalogo_id('tipos_equipo', imp_cat('tipos_equipo', $v['tipo']));
            if (array_key_exists('proyecto', $v)) $d['proyecto_id'] = catalogo_id('proyectos', imp_cat('proyectos', $v['proyecto']));
            if (array_key_exists('concepto', $v)) $d['concepto_id'] = catalogo_id('conceptos', imp_cat('conceptos', $v['concepto']));
            if (array_key_exists('ubicacion', $v)) $d['ubicacion_id'] = catalogo_id('ubicaciones', imp_cat('ubicaciones', $v['ubicacion']));
            if (array_key_exists('seguimiento', $v)) $d['seguimiento_id'] = catalogo_id('personas', imp_cat('personas', $v['seguimiento']));
            if (array_key_exists('coordinador', $v)) {
                $c = imp_cat('personas', $v['coordinador']);
                if ($c !== null && in_array(imp_quitar_acentos($c), ['SIN CORDINADOR', 'SIN COORDINADOR'], true)) $c = null;
                $d['coordinador_id'] = catalogo_id('personas', $c);
            }
            if (array_key_exists('extraviado', $v)) {
                $e = imp_quitar_acentos((string)imp_txt($v['extraviado']));
                $d['extraviado'] = in_array($e, ['SI', '1', 'TRUE', 'X', 'VERDADERO'], true) ? 1 : 0;
            }
            if (array_key_exists('anio', $v)) $d['anio'] = is_numeric($v['anio']) ? (int)$v['anio'] : null;
            if (array_key_exists('comentario', $v)) {
                $cm = $v['comentario'] !== null ? trim(preg_replace('/\s+/u', ' ', $v['comentario'])) : null;
                $d['comentario'] = ($cm === null || $cm === '' || in_array(mb_strtoupper($cm), IMP_NULOS, true)) ? null : $cm;
            }

            if (array_key_exists('etapa', $v)) {
                $et = imp_txt($v['etapa']);
                $d['etapa_id'] = $et !== null ? catalogo_id('etapas', $et, false) : null;
                if ($et !== null && !$d['etapa_id']) $r['avisos'][] = "Fila $fila: la etapa \"$et\" no existe en el catálogo; se dejó vacía.";
            }
            if (array_key_exists('folio_glpi', $v)) $d['folio_glpi'] = imp_folio_glpi($v['folio_glpi']);
            if (array_key_exists('estado_glpi', $v)) {   // si viene escrito, se toma como estado manual
                $eg = trim((string)$v['estado_glpi']);
                if ($eg !== '') {
                    // estado que trae la sábana (se compara contra el que se consulta directo en GLPI)
                    $mid = catalogo_id('estados_glpi', $eg, false);
                    if ($mid) $d['estado_glpi_sabana_id'] = $mid;
                    else $r['avisos'][] = "Fila $fila: el estado GLPI \"$eg\" no existe en el catálogo; no se registró.";
                }
            }
            if (array_key_exists('sucursal', $v)) $d['sucursal'] = imp_txt($v['sucursal']);
            if (array_key_exists('guia', $v)) $d['guia'] = imp_txt($v['guia']);
            if (array_key_exists('paqueteria', $v)) $d['paqueteria_id'] = catalogo_id('paqueterias', imp_txt($v['paqueteria']));
            if (array_key_exists('fecha_recepcion', $v)) $d['fecha_recepcion'] = imp_fecha($v['fecha_recepcion']);
            // Equipo nuevo sin columna de etapa: se clasifica automáticamente
            if (!$id && !array_key_exists('etapa', $v)) {
                $conc = isset($d['concepto_id']) ? catalogo_nombre('conceptos', $d['concepto_id']) : null;
                $d['etapa_id'] = catalogo_id('etapas', etapa_sugerida($conc, (int)($d['extraviado'] ?? 0), $d['comentario'] ?? null), false);
                $env = extraer_envio($d['comentario'] ?? null);
                if ($env['guia'] && empty($d['guia'])) $d['guia'] = $env['guia'];
                if ($env['paqueteria'] && empty($d['paqueteria_id'])) $d['paqueteria_id'] = catalogo_id('paqueterias', $env['paqueteria']);
            }

            // En equipos existentes, una celda vacía no borra el dato (salvo que se pida lo contrario)
            if ($id && !$vaciosBorran) {
                if (array_key_exists('extraviado', $d) && imp_txt($v['extraviado'] ?? null) === null) unset($d['extraviado']);
                foreach ($d as $k => $val) if ($val === null) unset($d[$k]);
            }
            $movCom = isset($v['mov_comentario']) ? trim(preg_replace('/\s+/u', ' ', (string)$v['mov_comentario'])) : '';
            if ($movCom !== '') $r['comentarios']++;

            if ($id) $usados[$id] = true;
            if ($decidir) {
                $d = $decidir($fila, $id, $d, $v);
                if ($d === null) { if ($id) $r['ids'][] = $id; continue; }
            }
            $vincularAcuerdo = !array_key_exists('__acuerdo', $d) || $d['__acuerdo'];
            unset($d['__acuerdo']);
            $com = $movCom !== '' ? $movCom : ($opc['comentario'] ?? null);
            $res = equipo_guardar($id, $d, $origen, $com, $uid);
            $usados[$res['id']] = true;
            $r['ids'][] = $res['id'];
            $r[$res['estado'] === 'nuevo' ? 'nuevos' : ($res['estado'] === 'actualizado' ? 'actualizados' : 'sin_cambios')]++;

            // --- acuerdo ---
            $ac = isset($v['acuerdo']) ? trim(preg_replace('/\s+/u', ' ', (string)$v['acuerdo'])) : '';
            if ($vincularAcuerdo && $ac !== '' && !in_array(mb_strtoupper($ac), IMP_NULOS, true)) {
                $k = mb_strtoupper($ac);
                if (!isset($acuerdosTxt[$k])) {
                    $aid = q("SELECT id FROM acuerdos WHERE descripcion = ? AND estatus = 'abierto' LIMIT 1", [$ac])->fetchColumn();
                    if (!$aid) {
                        q('INSERT INTO acuerdos (descripcion, fecha_compromiso, responsable_id, created_by) VALUES (?,?,?,?)',
                            [$ac, imp_fecha_acuerdo($ac), $d['seguimiento_id'] ?? null, $uid]);
                        $aid = db()->lastInsertId();
                        $r['acuerdos_nuevos']++;
                    }
                    $acuerdosTxt[$k] = (int)$aid;
                }
                $r['vinculos'] += acuerdo_vincular($acuerdosTxt[$k], [$res['id']], $uid, $res['estado'] !== 'nuevo');
            }
        }
        foreach (array_keys(CATALOGOS) as $t) {
            $nuevo = (int)q("SELECT COUNT(*) FROM `$t`")->fetchColumn() - $catAntes[$t];
            if ($nuevo > 0) $r['catalogos_nuevos'][CATALOGOS[$t]] = $nuevo;
        }
        if ($simular) {
            $pdo->rollBack();
            // la simulación no debe "gastar" números de ID: se reajusta el contador al máximo existente
            foreach (array_merge(['equipos', 'movimientos', 'acuerdos'], array_keys(CATALOGOS)) as $t) {
                $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
            }
        } else {
            $pdo->commit();
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    return $r;
}
