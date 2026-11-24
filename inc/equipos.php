<?php
declare(strict_types=1);

const CATALOGOS = [
    'etapas'       => 'Etapas (flujo)',
    'conceptos'    => 'Conceptos / motivo',
    'tipos_equipo' => 'Tipos de equipo',
    'ubicaciones'  => 'Ubicaciones',
    'regiones'     => 'Regiones',
    'personas'     => 'Personas (seguimiento / coordinación)',
    'paqueterias'  => 'Paqueterías',
    'proyectos'    => 'Proyectos',
    'estados_glpi' => 'Estados GLPI / Pulsott',
];

/** Dónde se usa cada catálogo: tabla => [columnas] */
const CATALOGO_USOS = [
    'etapas'       => [['equipos', 'etapa_id'], ['actas', 'etapa_id']],
    'tipos_equipo' => [['equipos', 'tipo_id']],
    'conceptos'    => [['equipos', 'concepto_id']],
    'ubicaciones'  => [['equipos', 'ubicacion_id']],
    'regiones'     => [['ubicaciones', 'region_id']],
    'personas'     => [['equipos', 'seguimiento_id'], ['equipos', 'coordinador_id'], ['acuerdos', 'responsable_id']],
    'paqueterias'  => [['equipos', 'paqueteria_id']],
    'proyectos'    => [['equipos', 'proyecto_id']],
    'estados_glpi' => [['equipos', 'estado_glpi_id'], ['equipos', 'estado_glpi_real_id'], ['equipos', 'estado_glpi_sabana_id'], ['etapas', 'estado_glpi_id']],
];

/** campo => [etiqueta, catálogo | 'bool' | 'date' | null] */
const CAMPOS_EQUIPO = [
    'serie'           => ['Serie', null],
    'inventario'      => ['Inventario', null],
    'folio'           => ['Folio', null],
    'folio_glpi'      => ['Folio GLPI', null],
    'tipo_id'         => ['Tipo de equipo', 'tipos_equipo'],
    'proyecto_id'     => ['Proyecto', 'proyectos'],
    'etapa_id'        => ['Etapa', 'etapas'],
    'concepto_id'     => ['Concepto', 'conceptos'],
    'ubicacion_id'    => ['Ubicación', 'ubicaciones'],
    'sucursal'        => ['Sucursal', null],
    'guia'            => ['Guía', null],
    'paqueteria_id'   => ['Paquetería', 'paqueterias'],
    'fecha_recepcion' => ['Fecha de recepción', 'date'],
    'seguimiento_id'  => ['Seguimiento con', 'personas'],
    'coordinador_id'  => ['Coordinador', 'personas'],
    'extraviado'      => ['Extraviado', 'bool'],
    'estado_glpi_sabana_id' => ['Estado GLPI (sábana)', 'estados_glpi'],
    'estado_glpi_real_id' => ['Estado GLPI (consultado en GLPI)', 'estados_glpi'],
    'anio'            => ['Año', null],
    'comentario'      => ['Comentario / nota', null],
];

const ORIGENES = [
    'alta'        => ['Alta', 'success'],
    'edicion'     => ['Edición', 'primary'],
    'masivo'      => ['Cambio masivo', 'info'],
    'importacion' => ['Importación', 'secondary'],
    'comentario'  => ['Comentario', 'light'],
    'acuerdo'     => ['Acuerdo', 'warning'],
    'acta'        => ['Acta', 'dark'],
    'sistema'     => ['Sistema', 'secondary'],
    'sabana'      => ['Sábana', 'success'],
    'glpi'        => ['GLPI', 'primary'],
];

const TIPOS_ACTA = [
    'entrega'     => 'Acta de entrega',
    'recepcion'   => 'Acta de recepción',
    'destruccion' => 'Acta de destrucción',
];

/** Palabra clave en el nombre de la ubicación => región */
const REGION_CLAVES = [
    'NOROESTE' => ['TIJUANA', 'MEXICALI', 'HERMOSILLO', 'NOGALES', 'CULIACAN', 'MAZATLAN', 'LOS CABOS', 'ENSENADA', 'OBREGON', 'LOS MOCHIS', 'SAN LUIS RIO', 'SELLCOM - LA PAZ', '=LA PAZ'],
    'NORTE'    => ['CHIHUAHUA', 'JUAREZ', 'DURANGO', 'TORREON', 'DELICIAS', 'PARRAL'],
    'NORESTE'  => ['MONTERREY', 'REYNOSA', 'MATAMOROS', 'SALTILLO', 'TAMPICO', 'NUEVO LAREDO', 'MONCLOVA', 'VICTORIA', 'PIEDRAS NEGRAS'],
    'CENTRO'   => ['CDMX', 'CIUDAD DE MEXICO', 'GAVILANES', 'TOLUCA', 'QUERETARO'],
];

function sin_acentos(string $s): string
{
    return strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Ñ' => 'N']);
}

function region_sugerida(?string $ubicacion): ?string
{
    if (!$ubicacion) return null;
    $u = str_replace('.', ' ', sin_acentos(mb_strtoupper($ubicacion)));
    foreach (REGION_CLAVES as $region => $claves) {
        foreach ($claves as $c) {
            if ($c[0] === '=' ? trim($u) === substr($c, 1) : str_contains($u, $c)) return $region;
        }
    }
    return null;
}

/** Etapa inicial a partir de los datos heredados del Excel. */
function etapa_sugerida(?string $concepto, int $extraviado, ?string $comentario): string
{
    $c = sin_acentos(mb_strtoupper((string)$concepto));
    $m = sin_acentos(mb_strtoupper((string)$comentario));
    if ($extraviado) return 'EXTRAVIADO';
    if (str_contains($c, 'DESTRUCC')) return 'PARA DESTRUCCION';
    if (str_contains($c, 'ALMACEN') || str_contains($m, 'EQUIPO EN ALMACEN CDMX')) return 'EN ALMACEN CDMX';
    if (str_contains($c, 'FRONTERA') || str_contains($m, 'ADUANA')) return 'EN FRONTERA / ADUANA';
    if (str_contains($m, 'TRAYECTO')) return 'EN TRANSITO';
    if (str_contains($c, 'RETORN') || str_contains($c, 'INSTALACION') || str_contains($c, 'CIERRES') || str_contains($c, 'NO ENVIADOS')) return 'EN SUCURSAL / CON TECNICO';
    return 'POR LOCALIZAR';
}

/**
 * Extrae guías, paquetería y fecha de confirmación de un comentario libre.
 * @return array{guia:?string, paqueteria:?string, fecha:?string}
 */
function extraer_envio(?string $comentario): array
{
    $r = ['guia' => null, 'paqueteria' => null, 'fecha' => null];
    if (!$comentario) return $r;
    $t = sin_acentos(mb_strtoupper($comentario));
    if (preg_match('/GUIA(?:S)?(?:\s+DE\s+RETORNO)?(?:\s+(?:FEDEX|DHL|ESTAFETA))?\s*:?\s*((?:[A-Z]{0,4}\d{7,15})(?:\s*[,Y\/]\s*[A-Z]{0,4}\d{7,15})*)/', $t, $m)) {
        preg_match_all('/[A-Z]{0,4}\d{7,15}/', $m[1], $g);
        $r['guia'] = implode(', ', array_unique($g[0]));
    }
    foreach (['FEDEX' => 'FEDEX', 'DHL' => 'DHL', 'ESTAFETA' => 'ESTAFETA', 'TRES GUERRAS' => 'TRES GUERRAS', 'PAQUETEXPRESS' => 'PAQUETEXPRESS', 'REDPACK' => 'REDPACK'] as $k => $v) {
        if (str_contains($t, $k)) { $r['paqueteria'] = $v; break; }
    }
    if (preg_match('/FECHA DE CONFIRMACION\s*:?\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/', $t, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        $r['fecha'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return $r;
}

/** Datos de una etapa (con caché). */
function etapa_info($id): ?array
{
    static $mem = null;
    if ($mem === null) $mem = q('SELECT * FROM etapas')->fetchAll(PDO::FETCH_UNIQUE);
    return ($id !== null && isset($mem[(int)$id])) ? $mem[(int)$id] : null;
}

function etapa_badge(?string $nombre, ?string $color = null): string
{
    if (!$nombre) return '<span class="text-body-tertiary">—</span>';
    $color = $color ?: 'secondary';
    $cls = $color === 'light' ? 'text-bg-light border' : "text-bg-$color";
    return '<span class="badge ' . $cls . ' text-wrap text-start">' . e($nombre) . '</span>';
}

/**
 * Calcula y guarda el estado GLPI de uno o todos los equipos. Prioridad:
 * (Es el estado que SUGIERE TrazaPulso; el estado real de cada ticket se guarda aparte en estado_glpi_real_id.)
 *  2. "No cerrado": etapa final (destruido/entregado) sin acta ni evidencia
 *  3. "Atendiendose": actividad registrada por un usuario en las últimas 24 h (etapas no finales ni extraviado)
 *  4. "Nuevo": etapa POR LOCALIZAR sin responsable de seguimiento
 *  5. El estado GLPI asignado a la etapa (Catálogos → Etapas)
 */
function glpi_recalcular(?int $id = null): int
{
    $ids = array_change_key_case(q('SELECT nombre, id FROM estados_glpi')->fetchAll(PDO::FETCH_KEY_PAIR), CASE_LOWER);
    $gid = fn(string $n) => isset($ids[mb_strtolower($n)]) ? (int)$ids[mb_strtolower($n)] : null;
    $where = $id ? 'WHERE e.id = ' . (int)$id : '';
    $rows = q("SELECT e.id, e.estado_glpi_id, e.estado_glpi_manual_id, e.seguimiento_id, et.nombre AS etapa, et.es_final, et.marca_extraviado, et.estado_glpi_id AS glpi_etapa,
            (EXISTS(SELECT 1 FROM acta_equipo ae WHERE ae.equipo_id = e.id) OR EXISTS(SELECT 1 FROM evidencias ev WHERE ev.equipo_id = e.id)) AS con_evidencia,
            EXISTS(SELECT 1 FROM movimientos m WHERE m.equipo_id = e.id AND m.fecha >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   AND m.origen NOT IN ('importacion', 'sistema', 'sabana', 'glpi')) AS reciente
        FROM equipos e LEFT JOIN etapas et ON et.id = e.etapa_id $where")->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        if ($r['es_final'] && !$r['con_evidencia']) $g = $gid('No cerrado');
        elseif ($r['reciente'] && $r['etapa'] && !$r['es_final'] && !$r['marca_extraviado']) $g = $gid('Atendiendose');
        elseif ($r['etapa'] && str_contains(sin_acentos(mb_strtoupper($r['etapa'])), 'LOCALIZAR') && !$r['seguimiento_id']) $g = $gid('Nuevo');
        else $g = $r['glpi_etapa'] ? (int)$r['glpi_etapa'] : $gid('Nuevo');
        if ((string)$g !== (string)$r['estado_glpi_id']) {
            q('UPDATE equipos SET estado_glpi_id = ? WHERE id = ?', [$g, $r['id']]);
            $n++;
        }
    }
    return $n;
}

/** Recalcula todos los estados GLPI como máximo cada 10 minutos (por la regla de 24 h). */
function glpi_recalcular_periodico(): void
{
    if (PHP_SAPI !== 'cli' && isset($_SESSION['glpi_calc']) && $_SESSION['glpi_calc'] > time() - 600) return;
    try { glpi_recalcular(); } catch (Throwable $e) { return; }
    if (PHP_SAPI !== 'cli') $_SESSION['glpi_calc'] = time();
}

/** Grupo del semáforo al que pertenece una etapa. */
const SEMAFORO = [
    'verde'    => ['Controlado', 'var(--sem-verde)', 'En almacén o para destrucción'],
    'amarillo' => ['En proceso', 'var(--sem-amarillo)', 'En sucursal, tránsito o frontera'],
    'rojo'     => ['Extraviado', 'var(--sem-rojo)', 'Marcados como extraviados'],
    'negro'    => ['Por localizar', 'var(--sem-negro)', 'Sin ubicación confirmada'],
    'gris'     => ['Cerrado', 'var(--sem-gris)', 'Destruidos o entregados'],
];
function semaforo_etapa(?array $et): string
{
    if (!$et) return 'negro';
    $n = sin_acentos(mb_strtoupper($et['nombre']));
    if ($et['marca_extraviado']) return 'rojo';
    if ($et['es_final']) return 'gris';
    if (str_contains($n, 'ALMACEN') || str_contains($n, 'DESTRUCC')) return 'verde';
    if (str_contains($n, 'LOCALIZAR')) return 'negro';
    return 'amarillo';
}

function fecha_corta(?string $f): string
{
    if (!$f) return '';
    $m = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $t = strtotime($f);
    return date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . date('y', $t);
}

/** Días transcurridos desde una fecha. */
function dias_desde(?string $dt): ?int
{
    if (!$dt) return null;
    return (int)floor((time() - strtotime($dt)) / 86400);
}

function catalogo_valido(string $t): bool
{
    return isset(CATALOGOS[$t]);
}

/** id => nombre */
function catalogo(string $tabla, bool $soloActivos = false): array
{
    static $cache = [];
    if (!catalogo_valido($tabla)) throw new InvalidArgumentException('Catálogo inválido');
    $k = $tabla . ($soloActivos ? '1' : '0');
    if (!isset($cache[$k])) {
        $sql = "SELECT id, nombre FROM `$tabla`" . ($soloActivos ? ' WHERE activo = 1' : '') . (in_array($tabla, ['etapas', 'estados_glpi'], true) ? ' ORDER BY orden, nombre' : ' ORDER BY nombre');
        $cache[$k] = q($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache[$k];
}

/** Busca (sin distinguir mayúsculas/acentos) o crea un elemento de catálogo. */
function catalogo_id(string $tabla, ?string $nombre, bool $crear = true): ?int
{
    static $mem = [];
    if ($nombre === null || trim($nombre) === '') return null;
    if (!catalogo_valido($tabla)) throw new InvalidArgumentException('Catálogo inválido');
    $nombre = trim(preg_replace('/\s+/u', ' ', $nombre));
    $key = $tabla . '|' . mb_strtoupper($nombre);
    if (isset($mem[$key])) return $mem[$key];
    $id = q("SELECT id FROM `$tabla` WHERE nombre = ? LIMIT 1", [$nombre])->fetchColumn();
    if (!$id && $crear) {
        q("INSERT INTO `$tabla` (nombre) VALUES (?)", [$nombre]);
        $id = db()->lastInsertId();
        if ($tabla === 'ubicaciones' && ($reg = region_sugerida($nombre))) {
            q('UPDATE ubicaciones SET region_id = (SELECT id FROM regiones WHERE nombre = ?) WHERE id = ?', [$reg, $id]);
        }
    }
    return $mem[$key] = ($id ? (int)$id : null);
}

function catalogo_nombre(string $tabla, $id): ?string
{
    static $mem = [];
    if ($id === null || $id === '') return null;
    if (!catalogo_valido($tabla)) throw new InvalidArgumentException('Catálogo inválido');
    $k = $tabla . '|' . (int)$id;
    if (!array_key_exists($k, $mem)) {
        $n = q("SELECT nombre FROM `$tabla` WHERE id = ?", [(int)$id])->fetchColumn();
        $mem[$k] = $n === false ? null : $n;
    }
    return $mem[$k];
}

function opciones(string $tabla, $sel = null, string $vacio = '—', bool $soloActivos = true): string
{
    $h = $vacio !== '' ? '<option value="">' . e($vacio) . '</option>' : '';
    $items = catalogo($tabla, $soloActivos);
    // asegurar que el seleccionado aparezca aunque esté inactivo
    if ($sel && !isset($items[(int)$sel])) {
        $n = catalogo_nombre($tabla, $sel);
        if ($n !== null) $items[(int)$sel] = $n . ' (inactivo)';
    }
    foreach ($items as $id => $n) {
        $h .= '<option value="' . (int)$id . '"' . ((string)$sel === (string)$id ? ' selected' : '') . '>' . e($n) . '</option>';
    }
    return $h;
}

function codigo_equipo(int $id): string
{
    return 'TP-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/** Acepta "TP-00012", "tp12" o "12" */
function id_desde_codigo(?string $c): ?int
{
    if ($c === null) return null;
    if (preg_match('/^\s*(?:TP-?)?0*(\d+)\s*$/i', $c, $m)) return (int)$m[1];
    return null;
}

const SQL_EQUIPO_SELECT = "SELECT e.*, t.nombre AS tipo, pr.nombre AS proyecto, c.nombre AS concepto, u.nombre AS ubicacion,
        ps.nombre AS seguimiento, pc.nombre AS coordinador, et.nombre AS etapa, et.color AS etapa_color, et.orden AS etapa_orden,
        et.es_final AS etapa_final, pq.nombre AS paqueteria, rg.nombre AS region,
        gl.nombre AS estado_glpi, gl.estado_pulsott AS estado_pulsott,
        glr.nombre AS estado_glpi_real, glr.estado_pulsott AS pulsott_real,
        gsb.nombre AS estado_glpi_sabana, gsb.estado_pulsott AS pulsott_sabana
    FROM equipos e
    LEFT JOIN tipos_equipo t ON t.id = e.tipo_id
    LEFT JOIN proyectos pr ON pr.id = e.proyecto_id
    LEFT JOIN conceptos c ON c.id = e.concepto_id
    LEFT JOIN ubicaciones u ON u.id = e.ubicacion_id
    LEFT JOIN regiones rg ON rg.id = u.region_id
    LEFT JOIN etapas et ON et.id = e.etapa_id
    LEFT JOIN paqueterias pq ON pq.id = e.paqueteria_id
    LEFT JOIN estados_glpi gl ON gl.id = e.estado_glpi_id
    LEFT JOIN estados_glpi glr ON glr.id = e.estado_glpi_real_id
    LEFT JOIN estados_glpi gsb ON gsb.id = e.estado_glpi_sabana_id
    LEFT JOIN personas ps ON ps.id = e.seguimiento_id
    LEFT JOIN personas pc ON pc.id = e.coordinador_id";

function equipo_get(int $id): ?array
{
    return q(SQL_EQUIPO_SELECT . ' WHERE e.id = ?', [$id])->fetch() ?: null;
}

function equipo_normalizar(array $d): array
{
    $out = [];
    foreach (CAMPOS_EQUIPO as $k => [, $tipo]) {
        if (!array_key_exists($k, $d)) continue;
        $v = $d[$k];
        if ($tipo === 'bool') {
            $v = ($v === true || $v === 1 || $v === '1' || $v === 'on') ? 1 : 0;
        } elseif ($tipo === 'date') {
            $v = ($v === null || $v === '') ? null : date('Y-m-d', strtotime((string)$v));
        } elseif ($tipo !== null || $k === 'anio') {
            $v = ($v === null || $v === '') ? null : (int)$v;
        } else {
            $v = $v === null ? null : trim((string)$v);
            if ($v === '') $v = null;
        }
        $out[$k] = $v;
    }
    return $out;
}

function valor_legible(string $campo, $v): ?string
{
    if ($v === null) return null;
    $tipo = CAMPOS_EQUIPO[$campo][1] ?? null;
    if ($tipo === 'bool') return ((int)$v) ? 'SI' : 'NO';
    if ($tipo === 'date') return date('d/m/Y', strtotime((string)$v));
    if ($tipo) return catalogo_nombre($tipo, $v) ?? ('#' . $v);
    return (string)$v;
}

function movimiento_insert(int $equipoId, string $origen, array $cambios, ?string $comentario, ?int $uid): int
{
    q('INSERT INTO movimientos (equipo_id, usuario_id, origen, cambios, comentario) VALUES (?,?,?,?,?)', [
        $equipoId, $uid, $origen,
        $cambios ? json_encode($cambios, JSON_UNESCAPED_UNICODE) : null,
        ($comentario !== null && trim($comentario) !== '') ? trim($comentario) : null,
    ]);
    return (int)db()->lastInsertId();
}

/**
 * Crea o actualiza un equipo registrando en la bitácora cada diferencia.
 * Solo se comparan/actualizan los campos presentes en $d.
 * @return array{id:int, mov:?int, estado:string}
 */
function equipo_guardar(?int $id, array $d, string $origen, ?string $comentario, ?int $uid): array
{
    $d = equipo_normalizar($d);
    $comentario = ($comentario !== null && trim($comentario) !== '') ? trim($comentario) : null;
    $act = $id ? q('SELECT * FROM equipos WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$act) throw new RuntimeException('El equipo no existe.');

    // --- Reglas automáticas al cambiar de etapa ---
    $etapaAntes = $act['etapa_id'] ?? null;
    if (array_key_exists('etapa_id', $d) && (string)$d['etapa_id'] !== (string)$etapaAntes) {
        $nueva = etapa_info($d['etapa_id']);
        $vieja = etapa_info($etapaAntes);
        $d['fecha_etapa'] = date('Y-m-d H:i:s');
        if ($nueva && $nueva['marca_extraviado']) {
            $d['extraviado'] = 1;
        } elseif ($vieja && $vieja['marca_extraviado'] && !($nueva && str_contains(sin_acentos(mb_strtoupper($nueva['nombre'])), 'LOCALIZAR'))) {
            $d['extraviado'] = 0; // localizado (pasar a POR LOCALIZAR no lo localiza: se sigue buscando)
        }
        if ($nueva && $nueva['registra_recepcion'] && empty($d['fecha_recepcion']) && empty($act['fecha_recepcion'])) {
            $d['fecha_recepcion'] = date('Y-m-d');
        }
    }

    if (array_key_exists('estado_glpi_real_id', $d) && (string)$d['estado_glpi_real_id'] !== (string)($act['estado_glpi_real_id'] ?? '')) {
        $d['estado_glpi_real_fecha'] = $d['estado_glpi_real_id'] ? date('Y-m-d H:i:s') : null;
    }

    if (!$id) {
        if (!empty($d['etapa_id']) && empty($d['fecha_etapa'])) $d['fecha_etapa'] = date('Y-m-d H:i:s');
        $cols = array_keys($d);
        $cols[] = 'created_by';
        $vals = array_values($d);
        $vals[] = $uid;
        $sql = 'INSERT INTO equipos (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        q($sql, $vals);
        $id = (int)db()->lastInsertId();
        $cambios = [];
        foreach ($d as $k => $v) {
            if ($v === null || ($k === 'extraviado' && (int)$v === 0) || !isset(CAMPOS_EQUIPO[$k])) continue;
            $cambios[] = ['campo' => $k, 'antes' => null, 'despues' => valor_legible($k, $v)];
        }
        $mov = movimiento_insert($id, $origen === 'importacion' ? 'importacion' : 'alta', $cambios, $comentario, $uid);
        glpi_recalcular($id);
        return ['id' => $id, 'mov' => $mov, 'estado' => 'nuevo'];
    }

    $cambios = [];
    $set = [];
    $vals = [];
    foreach ($d as $k => $v) {
        if ($k === 'fecha_etapa' || $k === 'estado_glpi_real_fecha') {   // campos técnicos: se guardan pero no se listan
            $set[] = "`$k` = ?";
            $vals[] = $v;
            continue;
        }
        $tipo = CAMPOS_EQUIPO[$k][1];
        $a = $act[$k];
        if ($a !== null) {
            $a = ($tipo === 'date') ? (string)$a : (($tipo !== null || $k === 'anio') ? (int)$a : (string)$a);
        }
        if ($a !== $v) {
            $cambios[] = ['campo' => $k, 'antes' => valor_legible($k, $a), 'despues' => valor_legible($k, $v)];
            $set[] = "`$k` = ?";
            $vals[] = $v;
        }
    }
    if (!$cambios) { $set = []; $vals = []; }
    if ($set) {
        $vals[] = $id;
        q('UPDATE equipos SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ?', $vals);
    }
    $mov = null;
    if ($cambios || $comentario) {
        $mov = movimiento_insert($id, $cambios ? $origen : 'comentario', $cambios, $comentario, $uid);
        if (!$set) q('UPDATE equipos SET updated_at = NOW() WHERE id = ?', [$id]);
    }
    glpi_recalcular($id);
    return ['id' => $id, 'mov' => $mov, 'estado' => $cambios ? 'actualizado' : 'sin_cambios'];
}

// ---------- Filtros del listado ----------
const FILTROS_EQUIPO = [
    'tipo' => 'e.tipo_id', 'concepto' => 'e.concepto_id', 'ubicacion' => 'e.ubicacion_id',
    'seguimiento' => 'e.seguimiento_id', 'coordinador' => 'e.coordinador_id', 'proyecto' => 'e.proyecto_id',
    'etapa' => 'e.etapa_id', 'paqueteria' => 'e.paqueteria_id', 'glpi' => 'e.estado_glpi_id', 'glpi_real' => 'e.estado_glpi_real_id', 'glpi_sabana' => 'e.estado_glpi_sabana_id',
];

/** @return array{0:string,1:array} WHERE + parámetros */
function equipos_filtro(array $f): array
{
    $w = ['1=1'];
    $p = [];
    $qtxt = trim((string)($f['q'] ?? ''));
    if ($qtxt !== '') {
        $tid = preg_match('/^\s*TP-?\d+\s*$/i', $qtxt) ? id_desde_codigo($qtxt) : null;
        if ($tid) {
            $w[] = 'e.id = ?';
            $p[] = $tid;
        } else {
            $w[] = '(e.serie LIKE ? OR e.inventario LIKE ? OR e.folio LIKE ? OR e.folio_glpi = ? OR e.comentario LIKE ? OR e.guia LIKE ? OR e.sucursal LIKE ?)';
            $like = '%' . $qtxt . '%';
            array_push($p, $like, $like, $like, $qtxt, $like, $like, $like);
        }
    }
    foreach (FILTROS_EQUIPO as $k => $col) {
        $v = $f[$k] ?? '';
        if ($v === '' || $v === null) continue;
        if ($v === 'null') {
            $w[] = "$col IS NULL";
        } else {
            $w[] = "$col = ?";
            $p[] = (int)$v;
        }
    }
    if (isset($f['extraviado']) && $f['extraviado'] !== '') {
        $w[] = 'e.extraviado = ?';
        $p[] = (int)$f['extraviado'];
    }
    if (!empty($f['anio'])) {
        $w[] = 'e.anio = ?';
        $p[] = (int)$f['anio'];
    }
    if (!empty($f['acuerdo'])) {
        $w[] = 'e.id IN (SELECT equipo_id FROM acuerdo_equipo WHERE acuerdo_id = ?)';
        $p[] = (int)$f['acuerdo'];
    }
    if (isset($f['region']) && $f['region'] !== '') {
        if ($f['region'] === 'null') {
            $w[] = '(e.ubicacion_id IS NULL OR e.ubicacion_id IN (SELECT id FROM ubicaciones WHERE region_id IS NULL))';
        } else {
            $w[] = 'e.ubicacion_id IN (SELECT id FROM ubicaciones WHERE region_id = ?)';
            $p[] = (int)$f['region'];
        }
    }
    if (!empty($f['etapas'])) {   // varias etapas: "1,2,3"
        $ids = array_filter(array_map('intval', explode(',', (string)$f['etapas'])));
        if ($ids) $w[] = 'e.etapa_id IN (' . implode(',', $ids) . ')';
    }
    if (!empty($f['concilia'])) {
        // comparación entre lo que dice la sábana y lo que dice GLPI
        if ($f['concilia'] === 'ok') $w[] = 'e.estado_glpi_real_id = e.estado_glpi_sabana_id';
        elseif ($f['concilia'] === 'dif') $w[] = 'e.estado_glpi_real_id IS NOT NULL AND e.estado_glpi_sabana_id IS NOT NULL AND e.estado_glpi_real_id <> e.estado_glpi_sabana_id';
        elseif ($f['concilia'] === 'sin') $w[] = '(e.estado_glpi_real_id IS NULL OR e.estado_glpi_sabana_id IS NULL)';
    }
    foreach (['cat_glpi' => 'estado_glpi_real_id', 'cat_sabana' => 'estado_glpi_sabana_id'] as $fk => $col) {
        if (!empty($f[$fk])) {
            $w[] = "e.$col IN (SELECT id FROM estados_glpi WHERE categoria_tp = ?)";
            $p[] = (string)$f[$fk];
        }
    }
    if (!empty($f['fisico']) && in_array($f['fisico'], ['ok', 'dif'], true)) {   // GLPI convertido vs etapa física
        $w[] = "e.estado_glpi_real_id IS NOT NULL AND " . ($f['fisico'] === 'ok' ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM estados_glpi g JOIN etapas et ON et.nombre = g.categoria_tp WHERE g.id = e.estado_glpi_real_id AND et.id = e.etapa_id)";
    }
    $calidad = ['existe' => 'e.glpi_existe = 1', 'noexiste' => 'e.glpi_existe = 0', 'corresponde' => 'e.glpi_corresponde = 1',
        'nocorresponde' => 'e.glpi_existe = 1 AND e.glpi_corresponde = 0', 'sinconsultar' => 'e.folio_glpi IS NOT NULL AND e.glpi_existe IS NULL',
        'sinfolio' => 'e.folio_glpi IS NULL', 'repetido' => 'e.folio_glpi IN (SELECT folio_glpi FROM equipos WHERE folio_glpi IS NOT NULL GROUP BY folio_glpi HAVING COUNT(*) > 1)'];
    if (!empty($f['folio_glpi_calidad']) && isset($calidad[$f['folio_glpi_calidad']])) $w[] = $calidad[$f['folio_glpi_calidad']];
    if (!empty($f['glpi_proyecto'])) {
        $w[] = "TRIM(SUBSTRING_INDEX(e.glpi_titulo, ' - ', 1)) = ?";
        $p[] = (string)$f['glpi_proyecto'];
    }
    if (!empty($f['pulsott_sabana'])) {
        $w[] = 'e.estado_glpi_sabana_id IN (SELECT id FROM estados_glpi WHERE estado_pulsott = ?)';
        $p[] = (string)$f['pulsott_sabana'];
    }
    if (!empty($f['pulsott_real'])) {
        $w[] = 'e.estado_glpi_real_id IN (SELECT id FROM estados_glpi WHERE estado_pulsott = ?)';
        $p[] = (string)$f['pulsott_real'];
    }
    if (!empty($f['pulsott'])) {
        $w[] = 'e.estado_glpi_id IN (SELECT id FROM estados_glpi WHERE estado_pulsott = ?)';
        $p[] = (string)$f['pulsott'];
    }
    if (!empty($f['pendientes'])) {
        $w[] = '(e.etapa_id IS NULL OR e.etapa_id IN (SELECT id FROM etapas WHERE es_final = 0))';
    }
    if (!empty($f['dias_etapa'])) {
        $w[] = 'e.fecha_etapa < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $p[] = (int)$f['dias_etapa'];
    }
    if (!empty($f['sin_mov_dias'])) {
        $w[] = 'e.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $p[] = (int)$f['sin_mov_dias'];
    }
    return [implode(' AND ', $w), $p];
}

// ---------- Evidencias ----------
/** Normaliza $_FILES['x'] múltiple a lista. */
function archivos_subidos(string $campo): array
{
    if (empty($_FILES[$campo])) return [];
    $f = $_FILES[$campo];
    $out = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $n) {
            if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
    } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE) {
        $out[] = $f;
    }
    return $out;
}

/** Valida y guarda un archivo. Devuelve [archivo_interno, nombre_original, tamaño]. */
function evidencia_almacenar(array $f): array
{
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir "' . $f['name'] . '" (código ' . $f['error'] . '). Revisa el tamaño máximo.');
    }
    if ($f['size'] > cfg('upload_max')) {
        throw new RuntimeException('"' . $f['name'] . '" excede el tamaño máximo de ' . round(cfg('upload_max') / 1048576) . ' MB.');
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, cfg('upload_ext'), true)) {
        throw new RuntimeException('Tipo de archivo no permitido: .' . $ext);
    }
    $dir = cfg('upload_dir') . '/' . date('Y/m');
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('No se pudo crear la carpeta de evidencias.');
    $interno = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], cfg('upload_dir') . '/' . $interno)) {
        throw new RuntimeException('No se pudo guardar "' . $f['name'] . '".');
    }
    $orig = mb_substr(preg_replace('/[^\p{L}\p{N}\s._()-]/u', '_', basename($f['name'])), 0, 200);
    return [$interno, $orig, (int)$f['size']];
}

/**
 * Guarda las evidencias subidas en $campo y las liga a cada equipo (mapa equipo_id => movimiento_id|null).
 * El archivo se almacena una sola vez aunque se ligue a varios equipos.
 */
function evidencias_guardar(string $campo, array $equipoMov, ?string $descripcion, ?int $uid): int
{
    $n = 0;
    foreach (archivos_subidos($campo) as $f) {
        [$interno, $orig, $tam] = evidencia_almacenar($f);
        foreach ($equipoMov as $eqId => $movId) {
            q('INSERT INTO evidencias (equipo_id, movimiento_id, archivo, nombre_original, tamano, descripcion, usuario_id) VALUES (?,?,?,?,?,?,?)',
                [$eqId, $movId, $interno, $orig, $tam, $descripcion ?: null, $uid]);
        }
        $n++;
    }
    return $n;
}

/** Asegura un movimiento para colgar evidencias cuando no hubo cambios. */
function hay_archivos(string $campo): bool
{
    return count(archivos_subidos($campo)) > 0;
}

// ---------- Acuerdos ----------
function acuerdo_vincular(int $acuerdoId, array $equipoIds, ?int $uid, bool $registrar = true): int
{
    $ac = q('SELECT id, descripcion FROM acuerdos WHERE id = ?', [$acuerdoId])->fetch();
    if (!$ac) throw new RuntimeException('Acuerdo no encontrado.');
    $n = 0;
    foreach ($equipoIds as $eid) {
        $st = q('INSERT IGNORE INTO acuerdo_equipo (acuerdo_id, equipo_id) VALUES (?,?)', [$acuerdoId, (int)$eid]);
        if ($st->rowCount() > 0) {
            $n++;
            if ($registrar) {
                movimiento_insert((int)$eid, 'acuerdo', [], 'Vinculado al acuerdo #' . $acuerdoId . ': ' . mb_strimwidth($ac['descripcion'], 0, 160, '…'), $uid);
            }
        }
    }
    return $n;
}

function acuerdo_estado_badge(array $a): string
{
    if ($a['estatus'] === 'cumplido') return '<span class="badge text-bg-success">Cumplido</span>';
    if ($a['estatus'] === 'cancelado') return '<span class="badge text-bg-secondary">Cancelado</span>';
    if ($a['fecha_compromiso'] && $a['fecha_compromiso'] < date('Y-m-d')) return '<span class="badge text-bg-danger">Vencido</span>';
    if ($a['fecha_compromiso'] && $a['fecha_compromiso'] <= date('Y-m-d', strtotime('+3 days'))) return '<span class="badge text-bg-warning">Por vencer</span>';
    return '<span class="badge text-bg-primary">Abierto</span>';
}

function acuerdos_vencidos_count(): int
{
    return (int)q("SELECT COUNT(*) FROM acuerdos WHERE estatus = 'abierto' AND fecha_compromiso < CURDATE()")->fetchColumn();
}
