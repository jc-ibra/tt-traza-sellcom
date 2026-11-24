<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

// ================= Datos =================
$total = (int)q('SELECT COUNT(*) FROM equipos')->fetchColumn();
$extrav = (int)q('SELECT COUNT(*) FROM equipos WHERE extraviado = 1')->fetchColumn();
$localiz = $total - $extrav;
$pctLoc = $total ? round($localiz * 100 / $total) : 0;
$acAbiertos = (int)q("SELECT COUNT(*) FROM acuerdos WHERE estatus = 'abierto'")->fetchColumn();
$acVencidos = acuerdos_vencidos_count();
$estancados = (int)q("SELECT COUNT(*) FROM equipos e JOIN etapas et ON et.id = e.etapa_id WHERE et.es_final = 0 AND et.marca_extraviado = 0 AND e.fecha_etapa < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$transitoLargo = (int)q("SELECT COUNT(*) FROM equipos e JOIN etapas et ON et.id = e.etapa_id WHERE et.nombre = 'EN TRANSITO' AND e.fecha_etapa < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$nTipos = (int)q('SELECT COUNT(DISTINCT tipo_id) FROM equipos')->fetchColumn();
$nResp = (int)q('SELECT COUNT(DISTINCT seguimiento_id) FROM equipos')->fetchColumn();
$proximo = q("SELECT a.* FROM acuerdos a WHERE a.estatus = 'abierto' AND a.fecha_compromiso >= CURDATE() ORDER BY a.fecha_compromiso LIMIT 1")->fetch();

$etapas = q("SELECT et.*,
        (SELECT COUNT(*) FROM equipos e WHERE e.etapa_id = et.id) AS total,
        (SELECT COUNT(*) FROM equipos e WHERE e.etapa_id = et.id AND e.extraviado = 1) AS extrav,
        (SELECT COUNT(*) FROM equipos e WHERE e.etapa_id = et.id AND e.fecha_etapa < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS estanc
    FROM etapas et WHERE et.activo = 1 ORDER BY et.orden")->fetchAll();
$sinEtapa = (int)q('SELECT COUNT(*) FROM equipos WHERE etapa_id IS NULL')->fetchColumn();
$sem = array_fill_keys(array_keys(SEMAFORO), ['n' => 0, 'ids' => []]);
foreach ($etapas as $et) {
    $g = semaforo_etapa($et);
    $sem[$g]['n'] += (int)$et['total'];
    $sem[$g]['ids'][] = (int)$et['id'];
}
$sem['negro']['n'] += $sinEtapa;

$acPorEtapa = [];
foreach (q("SELECT e.etapa_id, a.id, a.descripcion, a.fecha_compromiso, p.nombre AS responsable
    FROM acuerdo_equipo ae JOIN acuerdos a ON a.id = ae.acuerdo_id JOIN equipos e ON e.id = ae.equipo_id LEFT JOIN personas p ON p.id = a.responsable_id
    WHERE a.estatus = 'abierto' GROUP BY e.etapa_id, a.id, a.descripcion, a.fecha_compromiso, p.nombre
    ORDER BY a.fecha_compromiso IS NULL, a.fecha_compromiso")->fetchAll() as $r) {
    $acPorEtapa[(int)$r['etapa_id']] ??= $r;   // el más próximo
}
$entradas = [];
foreach (q("SELECT cambios FROM movimientos WHERE fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND origen NOT IN ('importacion','sistema') AND cambios LIKE '%\"etapa_id\"%'")->fetchAll(PDO::FETCH_COLUMN) as $cj) {
    foreach (json_decode($cj, true) ?: [] as $c) {
        if ($c['campo'] === 'etapa_id' && $c['despues'] !== null) $entradas[$c['despues']] = ($entradas[$c['despues']] ?? 0) + 1;
    }
}

function agrupado(string $col, string $tabla): array
{
    return q("SELECT e.$col AS id, COALESCE(x.nombre, '(Sin asignar)') AS nombre, COUNT(*) AS total, SUM(e.extraviado) AS extrav
              FROM equipos e LEFT JOIN `$tabla` x ON x.id = e.$col GROUP BY e.$col, x.nombre ORDER BY total DESC")->fetchAll();
}
$porSeg = agrupado('seguimiento_id', 'personas');
$porCoord = agrupado('coordinador_id', 'personas');
$porTipo = agrupado('tipo_id', 'tipos_equipo');
$porRegion = q("SELECT rg.id, COALESCE(rg.nombre, '(Sin región)') AS nombre, COUNT(*) AS total, SUM(e.extraviado) AS extrav
    FROM equipos e LEFT JOIN ubicaciones u ON u.id = e.ubicacion_id LEFT JOIN regiones rg ON rg.id = u.region_id
    GROUP BY rg.id, rg.nombre ORDER BY total DESC")->fetchAll();
$acuerdos = q("SELECT a.*, p.nombre AS responsable, (SELECT COUNT(*) FROM acuerdo_equipo ae WHERE ae.acuerdo_id = a.id) AS equipos
               FROM acuerdos a LEFT JOIN personas p ON p.id = a.responsable_id
               WHERE a.estatus = 'abierto' ORDER BY a.fecha_compromiso IS NULL, a.fecha_compromiso LIMIT 10")->fetchAll();
$ultimos = q("SELECT m.*, u.nombre AS usuario, e.serie, t.nombre AS tipo
              FROM movimientos m JOIN equipos e ON e.id = m.equipo_id
              LEFT JOIN usuarios u ON u.id = m.usuario_id LEFT JOIN tipos_equipo t ON t.id = e.tipo_id
              WHERE m.origen NOT IN ('importacion', 'sistema') ORDER BY m.fecha DESC, m.id DESC LIMIT 10")->fetchAll();

// ================= Utilidades de presentación =================
/** Número enlazado o "0" atenuado */
function lnk(array $q, $n, string $cls = ''): string
{
    return (int)$n ? '<a class="' . $cls . '" href="equipos.php?' . e(http_build_query($q)) . '">' . num($n) . '</a>' : '<span class="cero">0</span>';
}

/** Tabla estándar: Nombre | Equipos | Extrav. | No extrav. | barra */
/** Conteo por grupo y año: [grupo][anio] => n */
function anios_grupo(string $expr, string $join = ''): array
{
    $r = [];
    foreach (q("SELECT $expr AS g, e.anio, COUNT(*) n FROM equipos e $join WHERE e.anio IS NOT NULL GROUP BY g, e.anio")->fetchAll() as $f) {
        $r[$f['g'] ?? 'null'][(int)$f['anio']] = (int)$f['n'];
    }
    return $r;
}
function seg_anios(array $mapa, $id, array $anios): array
{
    $seg = [];
    foreach ($anios as $a) $seg[$a] = $mapa[$id ?? 'null'][$a] ?? 0;
    return $seg;
}

/** Tabla estándar: Nombre | Total | Extrav. | No extr. | Proporción por año */
function tabla_grupo(array $filas, string $filtro, array $porAnio, array $anios): void
{
    $tt = $tx = 0;
    $tseg = array_fill_keys($anios, 0);
    echo '<table class="tbl"><colgroup><col><col style="width:56px"><col style="width:64px"><col style="width:68px"><col style="width:38%"></colgroup>'
        . '<thead><tr><th>Nombre</th><th class="n">Total</th><th class="n">Extrav.</th><th class="n">No extr.</th><th class="c">' . e(implode(' vs ', $anios)) . '</th></tr></thead><tbody>';
    foreach ($filas as $f) {
        $v = $f['id'] === null ? 'null' : $f['id'];
        $x = (int)$f['extrav'];
        $no = (int)$f['total'] - $x;
        $tt += (int)$f['total'];
        $tx += $x;
        $seg = seg_anios($porAnio, $f['id'], $anios);
        foreach ($seg as $a => $n) $tseg[$a] += $n;
        echo '<tr><td class="nom" title="' . e($f['nombre']) . '"><a href="equipos.php?' . e(http_build_query([$filtro => $v])) . '">' . e($f['nombre']) . '</a></td>'
            . '<td class="n fw-semibold">' . num($f['total']) . '</td>'
            . '<td class="n">' . lnk([$filtro => $v, 'extraviado' => 1], $x, 'txt-x') . '</td>'
            . '<td class="n">' . lnk([$filtro => $v, 'extraviado' => 0], $no, 'txt-o') . '</td>'
            . '<td>' . pill_anios($seg) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td>Total</td><td class="n">' . num($tt) . '</td><td class="n txt-x">' . num($tx) . '</td><td class="n txt-o">' . num($tt - $tx) . '</td><td>' . pill_anios($tseg) . '</td></tr></tfoot></table>';
}

// Filtros de la tabla por concepto
$aniosEq = array_map('intval', q('SELECT DISTINCT anio FROM equipos WHERE anio IS NOT NULL ORDER BY anio DESC')->fetchAll(PDO::FETCH_COLUMN));
$anioC = in_array((int)g('ca'), $aniosEq, true) ? (int)g('ca') : null;
$extC = in_array(g('ce'), ['1', '0'], true) ? (int)g('ce') : null;
function url_concepto(array $cambios): string
{
    global $anioC, $extC;
    $q = array_filter(array_merge(['ca' => $anioC, 'ce' => $extC], $cambios), fn($v) => $v !== null);
    return '?' . http_build_query($q) . '#por-concepto';
}

/**
 * Tabla por concepto con tamaño fijo: siempre muestra todos los conceptos y todas las columnas de año;
 * lo que queda fuera del filtro se ve en gris (0 / —) en lugar de desaparecer, para que la pantalla no se mueva.
 */
function tabla_concepto(array $anios, ?int $anioSel, ?int $extSel): void
{
    // lista fija de conceptos (sin filtros) para que el número de renglones no cambie
    $todos = q("SELECT e.concepto_id AS id, COALESCE(c.nombre, '(Sin asignar)') AS nombre, COUNT(*) AS total
        FROM equipos e LEFT JOIN conceptos c ON c.id = e.concepto_id GROUP BY e.concepto_id, c.nombre ORDER BY total DESC, nombre")->fetchAll();
    if (!$todos) { echo '<p class="text-body-secondary small m-3">Sin equipos.</p>'; return; }
    $maxGlobal = max(array_column($todos, 'total') ?: [1]);

    $wExt = $extSel !== null ? ' AND e.extraviado = ' . $extSel : '';
    $wAnio = $anioSel ? ' AND e.anio = ' . $anioSel : '';
    $cols = '';
    foreach ($anios as $a) $cols .= ", SUM(e.anio = $a AND e.extraviado = 1) AS `x$a`, SUM(e.anio = $a AND e.extraviado = 0) AS `n$a`";
    $vals = [];
    foreach (q("SELECT e.concepto_id AS id, COUNT(*) AS total, SUM(e.extraviado) AS extrav FROM equipos e WHERE 1=1 $wAnio $wExt GROUP BY e.concepto_id")->fetchAll() as $r) {
        $vals[$r['id'] ?? 'null'] = $r;
    }
    $porAnio = [];
    foreach (q("SELECT e.concepto_id AS id $cols FROM equipos e WHERE 1=1 $wExt GROUP BY e.concepto_id")->fetchAll() as $r) {
        $porAnio[$r['id'] ?? 'null'] = $r;
    }
    $acs = [];
    foreach (q("SELECT e.concepto_id, a.id, a.descripcion, a.fecha_compromiso, COUNT(*) AS n
        FROM acuerdo_equipo ae JOIN acuerdos a ON a.id = ae.acuerdo_id JOIN equipos e ON e.id = ae.equipo_id
        WHERE a.estatus = 'abierto' $wAnio $wExt GROUP BY e.concepto_id, a.id, a.descripcion, a.fecha_compromiso ORDER BY a.fecha_compromiso IS NULL, a.fecha_compromiso")->fetchAll() as $a) {
        $venc = $a['fecha_compromiso'] && $a['fecha_compromiso'] < date('Y-m-d') ? ' VENCIDO' : '';
        $acs[$a['concepto_id'] ?? 'null'][] = 'Acuerdo #' . $a['id'] . ' (' . ($a['fecha_compromiso'] ? fecha($a['fecha_compromiso'], false) : 'sin fecha') . $venc . ', ' . $a['n'] . ' eq.): ' . mb_strimwidth($a['descripcion'], 0, 160, '…');
    }
    $base = ($anioSel ? ['anio' => $anioSel] : []);
    $baseExt = $extSel !== null ? ['extraviado' => $extSel] : [];

    echo '<div class="table-responsive"><table class="tbl" style="min-width:' . (820 + 120 * count($anios)) . 'px"><colgroup><col><col style="width:76px"><col style="width:76px"><col style="width:84px">';
    foreach ($anios as $a) echo '<col style="width:120px">';
    echo '<col style="width:30%"></colgroup><thead><tr><th>Concepto</th><th class="n">Total</th><th class="n">Extrav.</th><th class="n">No extr.</th>';
    foreach ($anios as $a) echo '<th class="c"' . ($anioSel && $anioSel !== $a ? ' style="opacity:.4"' : '') . '>' . $a . ' · ext / no</th>';
    echo '<th class="c">Proporción ' . e(implode(' vs ', $anios)) . '</th></tr></thead><tbody>';
    $tot = ['t' => 0, 'x' => 0];
    foreach ($todos as $c) {
        $v = $c['id'] === null ? 'null' : $c['id'];
        $f = $vals[$v] ?? ['total' => 0, 'extrav' => 0];
        $t = (int)$f['total'];
        $x = (int)$f['extrav'];
        $no = $t - $x;
        $tot['t'] += $t;
        $tot['x'] += $x;
        $ley = num($x) . ' extraviados / ' . num($no) . ' no extraviados' . "\n" . (!empty($acs[$v]) ? implode("\n", $acs[$v]) : 'Sin acuerdos abiertos');
        echo '<tr title="' . e($ley) . '"' . ($t ? '' : ' style="opacity:.45"') . '><td class="nom"><a href="equipos.php?' . e(http_build_query(['concepto' => $v] + $base + $baseExt)) . '">' . e($c['nombre']) . '</a>'
            . (!empty($acs[$v]) ? ' <i class="bi bi-calendar-check text-warning-emphasis"></i>' : '') . '</td>'
            . '<td class="n fw-semibold">' . ($t ? num($t) : '<span class="cero">0</span>') . '</td>'
            . '<td class="n">' . lnk(['concepto' => $v, 'extraviado' => 1] + $base, $x, 'txt-x fw-semibold') . '</td>'
            . '<td class="n">' . lnk(['concepto' => $v, 'extraviado' => 0] + $base, $no, 'txt-o fw-semibold') . '</td>';
        foreach ($anios as $a) {
            if ($anioSel && $anioSel !== $a) { echo '<td class="c"><span class="cero">—</span></td>'; continue; }
            $xa = (int)($porAnio[$v]["x$a"] ?? 0);
            $na = (int)($porAnio[$v]["n$a"] ?? 0);
            $tot["x$a"] = ($tot["x$a"] ?? 0) + $xa;
            $tot["n$a"] = ($tot["n$a"] ?? 0) + $na;
            echo '<td class="c">' . lnk(['concepto' => $v, 'anio' => $a, 'extraviado' => 1], $xa, 'txt-x') . ' <span class="cero">/</span> ' . lnk(['concepto' => $v, 'anio' => $a, 'extraviado' => 0], $na, 'txt-o') . '</td>';
        }
        // barra gruesa dividida por año (respeta los filtros de año y de extraviados)
        $seg = [];
        foreach ($anios as $a) {
            $seg[$a] = ($anioSel && $anioSel !== $a) ? 0 : (int)($porAnio[$v]["x$a"] ?? 0) + (int)($porAnio[$v]["n$a"] ?? 0);
        }
        echo '<td>' . pill_anios($seg, $maxGlobal) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td>Total</td><td class="n">' . num($tot['t']) . '</td><td class="n">' . lnk(['extraviado' => 1] + $base, $tot['x'], 'txt-x') . '</td><td class="n">' . lnk(['extraviado' => 0] + $base, $tot['t'] - $tot['x'], 'txt-o') . '</td>';
    foreach ($anios as $a) {
        if ($anioSel && $anioSel !== $a) { echo '<td class="c"><span class="cero">—</span></td>'; continue; }
        echo '<td class="c">' . lnk(['anio' => $a, 'extraviado' => 1], $tot["x$a"] ?? 0, 'txt-x') . ' / ' . lnk(['anio' => $a, 'extraviado' => 0], $tot["n$a"] ?? 0, 'txt-o') . '</td>';
    }
    echo '<td></td></tr></tfoot></table></div>';
}

/** Barra 100% apilada: proporción de cada año dentro del concepto (verde 2026, rojo 2025...). */
function pill_anios(array $seg, int $max = 0): string
{
    $tot = array_sum($seg);
    if (!$tot) return '<div class="pill-bar vacia"></div>';
    $arriba = [];
    $h = '<div class="pill-bar">';
    $i = 0;
    foreach ($seg as $anio => $n) {
        $cls = 'p' . ($i++ % 4);
        if (!$n) {   // año sin equipos: tramo mínimo con su etiqueta arriba
            $h .= '<span class="' . $cls . ' cero" title="0% (' . $anio . ')"></span>';
            $arriba[] = '0% (' . $anio . ')';
            continue;
        }
        $pct = round($n * 100 / $tot);
        $txt = $pct . '% (' . $anio . ')';
        $etq = $pct >= 33 ? $txt : ($pct >= 10 ? $pct . '%' : '');   // en tramos chicos solo el % (o nada)
        $h .= '<span class="' . $cls . '" style="flex-grow:' . $n . '" title="' . e($txt . ' · ' . num($n) . ' equipos') . '">' . e($etq) . '</span>';
    }
    $h .= '</div>';
    $izq = reset($seg) === 0;   // si el año en 0 es el primero, su tramo (y etiqueta) va a la izquierda
    return ($arriba ? '<div class="pill-arriba' . ($izq ? ' izq' : '') . '">' . e(implode(' · ', $arriba)) . '</div>' : '') . $h;
}

/** Contenido de la tarjeta "Por concepto" (también se devuelve solo, para actualizar sin recargar la página). */
function tarjeta_concepto(array $aniosEq, ?int $anioC, ?int $extC): void
{
    ?>
  <div class="card-header"><span>Por concepto / motivo</span><span class="extra"><span class="dot" style="background:var(--sem-rojo)"></span>Extraviados <span class="dot ms-2" style="background:var(--tp-azul2)"></span>No extraviados</span></div>
  <div class="rs-toolbar">
    <div class="btn-group btn-group-sm">
      <a class="btn <?= $anioC ? 'btn-outline-secondary' : 'btn-secondary' ?>" href="<?= e(url_concepto(['ca' => null])) ?>">Todos los años</a>
      <?php foreach ($aniosEq as $a): ?><a class="btn <?= $anioC === $a ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= e(url_concepto(['ca' => $a])) ?>"><?= $a ?></a><?php endforeach; ?>
    </div>
    <div class="btn-group btn-group-sm">
      <a class="btn <?= $extC === null ? 'btn-dark' : 'btn-outline-dark' ?>" href="<?= e(url_concepto(['ce' => null])) ?>">Todos</a>
      <a class="btn <?= $extC === 1 ? 'btn-danger' : 'btn-outline-danger' ?>" href="<?= e(url_concepto(['ce' => 1])) ?>">Extraviados</a>
      <a class="btn <?= $extC === 0 ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(url_concepto(['ce' => 0])) ?>">No extraviados</a>
    </div>
    <span class="small text-body-secondary ms-auto"><i class="bi bi-calendar-check text-warning-emphasis"></i> tiene acuerdo · pasa el mouse sobre la fila para verlo</span>
  </div>
  <?php
    tabla_concepto($aniosEq, $anioC, $extC);
}

// Actualización parcial (botones de filtro sin recargar la página)
if (g('parcial') === 'concepto') {
    tarjeta_concepto($aniosEq, $anioC, $extC);
    exit;
}

function badge_acuerdo(?array $ac): string
{
    if (!$ac) return '<span class="cero">—</span>';
    $f = $ac['fecha_compromiso'];
    if ($f && $f < date('Y-m-d')) return '<span class="rs-badge" style="background:linear-gradient(135deg,var(--sem-rojo),var(--sem-rojo-2))">Vencido</span>';
    if ($f && $f <= date('Y-m-d', strtotime('+3 days'))) return '<span class="rs-badge" style="background:linear-gradient(135deg,var(--sem-naranja),var(--sem-naranja-2))">Por vencer</span>';
    return '<span class="rs-badge" style="background:linear-gradient(135deg,var(--sem-verde),var(--sem-verde-2))">En tiempo</span>';
}

$aniosEtapa = anios_grupo('e.etapa_id');
$porGlpi = q("SELECT g.id, g.nombre, g.estado_pulsott, g.orden, COUNT(e.id) AS total, COALESCE(SUM(e.extraviado), 0) AS extrav
    FROM estados_glpi g LEFT JOIN equipos e ON e.estado_glpi_id = g.id GROUP BY g.id, g.nombre, g.estado_pulsott, g.orden ORDER BY g.orden")->fetchAll();
$aniosGlpi = anios_grupo('e.estado_glpi_id');
$porPulsott = [];
foreach ($porGlpi as $g) {
    $k = $g['estado_pulsott'] ?: '(sin equivalencia)';
    $porPulsott[$k] ??= ['total' => 0, 'extrav' => 0, 'glpi' => []];
    $porPulsott[$k]['total'] += (int)$g['total'];
    $porPulsott[$k]['extrav'] += (int)$g['extrav'];
    if ($g['total']) $porPulsott[$k]['glpi'][] = $g['nombre'];
    if ($g['total']) $porPulsott[$k]['detalle'][] = $g['nombre'] . ' ' . num($g['total']);
}
uasort($porPulsott, fn($a, $b) => $b['total'] <=> $a['total']);
$conFolioGlpi = (int)q('SELECT COUNT(*) FROM equipos WHERE folio_glpi IS NOT NULL')->fetchColumn();
$extDef = (int)q('SELECT COUNT(*) FROM equipos e JOIN etapas et ON et.id = e.etapa_id WHERE e.extraviado = 1 AND et.marca_extraviado = 1')->fetchColumn();
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$titulo = 'Resumen integrado';
require __DIR__ . '/inc/header.php';
?>
<!-- ============ Encabezado ============ -->
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
  <div>
    <h1 class="rs-titulo">Resumen Integrado de Trazabilidad</h1>
    <div class="rs-sub">Situación de todos los equipos, corte al <?= date('j') ?> de <?= $meses[(int)date('n')] ?> de <?= date('Y') ?>.</div>
  </div>
  <div class="d-flex flex-wrap gap-2 rs-acciones no-print">
    <a class="btn btn-tp-out" href="reporte_extraviados.php"><i class="bi bi-exclamation-triangle"></i> Extraviados</a>
    <?php if (puede('editor')): require_once __DIR__ . '/inc/glpi_api.php'; ?>
      <?php if (glpi_configurado()): ?><form method="post" action="glpi.php" class="m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="sincronizar"><input type="hidden" name="volver" value="index.php"><button class="btn btn-glpi"><i class="bi bi-cloud-download"></i> Actualizar GLPI</button></form>
      <?php elseif (puede('admin')): ?><a class="btn btn-glpi" href="glpi.php" title="Falta capturar la dirección y los tokens de GLPI"><i class="bi bi-cloud-download"></i> Actualizar GLPI</a><?php endif; ?>
    <?php endif; ?>
    <a class="btn btn-tp" href="reporte_ejecutivo.php"><i class="bi bi-clipboard-data"></i> Reporte ejecutivo</a>
  </div>
</div>

<?php if ($total === 0): ?>
  <div class="alert alert-info">Aún no hay equipos registrados. <?php if (puede('admin')): ?><a href="importar.php">Importa tu Excel</a>.<?php endif; ?></div>
<?php endif; ?>

<!-- ============ Indicadores: grupos que siempre suman el total ============ -->
<?php
$extPorLoc = (int)q("SELECT COUNT(*) FROM equipos e JOIN etapas et ON et.id = e.etapa_id WHERE e.extraviado = 1 AND et.marca_extraviado = 0")->fetchColumn();
$grupos = [
    'rojo'     => ['Extraviados', 'Definitivos (etapa Extraviado)'],
    'negro'    => ['Por localizar', $extPorLoc ? num($extPorLoc) . ' marcados extraviados' : 'Sin ubicación confirmada'],
    'amarillo' => ['En proceso', 'Sucursal, tránsito o frontera' . ($estancados ? ' · ' . num($estancados) . ' estancados' : '')],
    'verde'    => ['Controlados', 'En almacén o para destrucción'],
    'gris'     => ['Cerrados', 'Destruidos o entregados'],
];
$colKpi = ['rojo' => 'var(--sem-rojo)', 'negro' => '#2b2f36', 'amarillo' => '#f59f00', 'verde' => 'var(--sem-verde)', 'gris' => '#868e96'];
?>
<div class="rs-pulsott rs-6 mb-1">
  <a class="rs-kpi" style="--c:var(--tp-azul2)" href="equipos.php"><div class="etq">Equipos</div><div class="num"><?= num($total) ?></div>
    <div class="sub" title="<?= num($extrav) ?> equipos marcados como extraviados"><?= $pctLoc ?>% localizados</div></a>
  <?php foreach ($grupos as $k => [$nom, $sub]): ?>
    <a class="rs-kpi" style="--c:<?= $colKpi[$k] ?><?= $sem[$k]['n'] ? '' : ';opacity:.55' ?>" href="equipos.php?etapas=<?= e(implode(',', $sem[$k]['ids']) ?: '0') ?>" title="<?= e(SEMAFORO[$k][2]) ?>">
      <div class="etq"><?= e($nom) ?> <span class="fw-normal">· <?= $total ? round($sem[$k]['n'] * 100 / $total) : 0 ?>%</span></div>
      <div class="num"><?= num($sem[$k]['n']) ?></div>
      <div class="sub"><?= e($sub) ?></div></a>
  <?php endforeach; ?>
</div>
<div class="d-flex flex-wrap justify-content-between gap-2 small text-body-secondary mb-3 px-1">
  <span><b><?= num($total) ?></b> = <?= implode(' + ', array_map(fn($k) => '<span style="color:' . $colKpi[$k] . ';font-weight:600">' . num($sem[$k]['n']) . '</span> ' . e(mb_strtolower($grupos[$k][0])), array_keys($grupos))) ?></span>
  <span><i class="bi bi-calendar-check"></i> Próximo compromiso: <b><?= $proximo ? e(fecha_corta($proximo['fecha_compromiso'])) : '—' ?></b>
    · <?= num($acAbiertos) ?> acuerdos abiertos<?php if ($acVencidos): ?> · <a class="text-danger fw-semibold" href="acuerdos.php?vista=vencidos"><?= $acVencidos ?> vencido<?= $acVencidos > 1 ? 's' : '' ?></a><?php endif; ?></span>
</div>

<!-- ============ Estado Pulsott: real (GLPI) vs sugerido (TrazaPulso) ============ -->
<?php
$ordenPulsott = ['PENDIENTE' => '#f59f00', 'PROGRAMADO' => 'var(--tp-azul2)', 'REPROGRAMADO' => 'var(--sem-morado)', 'EN PROCESO' => '#0ca678',
    'EN PAUSA' => 'var(--sem-rojo)', 'EJECUTADO' => 'var(--sem-verde)', 'CANCELADO' => '#868e96'];
/** Conteo Pulsott por columna de estado GLPI (calculado o real) */
function pulsott_conteo(string $col): array
{
    $r = [];
    foreach (q("SELECT g.estado_pulsott AS p, g.nombre, COUNT(*) n FROM equipos e JOIN estados_glpi g ON g.id = e.$col GROUP BY g.estado_pulsott, g.nombre, g.orden ORDER BY g.orden")->fetchAll() as $f) {
        $k = $f['p'] ?: '(sin equivalencia)';
        $r[$k]['total'] = ($r[$k]['total'] ?? 0) + (int)$f['n'];
        $r[$k]['detalle'][] = $f['nombre'] . ' ' . num($f['n']);
    }
    return $r;
}
// Conversión GLPI → categoría TrazaPulso (tabla en Conexión GLPI)
$catColor = [];
foreach ($etapas as $et) $catColor[$et['nombre']] = SEMAFORO[semaforo_etapa($et)][1];
$catColor += ['ATENDIENDOSE' => '#0ca678', 'SIN EVIDENCIA' => '#868e96'];
$categorias = array_keys($catColor);
/** Conteo por categoría TrazaPulso de una columna de estado GLPI */
function categoria_conteo(string $col): array
{
    $r = [];
    foreach (q("SELECT COALESCE(g.categoria_tp, '(sin conversión)') c, g.nombre, COUNT(*) n FROM equipos e JOIN estados_glpi g ON g.id = e.$col GROUP BY c, g.nombre, g.orden ORDER BY g.orden")->fetchAll() as $f) {
        $r[$f['c']]['total'] = ($r[$f['c']]['total'] ?? 0) + (int)$f['n'];
        $r[$f['c']]['detalle'][] = 'GLPI "' . $f['nombre'] . '" ' . num($f['n']);
    }
    return $r;
}
$cReal = categoria_conteo('estado_glpi_real_id');
$cSab = categoria_conteo('estado_glpi_sabana_id');
foreach (array_merge(array_keys($cReal), array_keys($cSab)) as $k) if (!isset($catColor[$k])) { $catColor[$k] = '#495057'; $categorias[] = $k; }
$conc = q("SELECT SUM(estado_glpi_real_id IS NOT NULL AND estado_glpi_sabana_id IS NOT NULL AND estado_glpi_real_id = estado_glpi_sabana_id) ok,
                  SUM(estado_glpi_real_id IS NOT NULL AND estado_glpi_sabana_id IS NOT NULL AND estado_glpi_real_id <> estado_glpi_sabana_id) dif,
                  SUM(estado_glpi_real_id IS NULL OR estado_glpi_sabana_id IS NULL) sin,
                  SUM(estado_glpi_real_id IS NOT NULL) con_glpi, SUM(estado_glpi_sabana_id IS NOT NULL) con_sabana,
                  MAX(estado_glpi_real_fecha) ult FROM equipos")->fetch();
$fis = q("SELECT SUM(EXISTS(SELECT 1 FROM estados_glpi g JOIN etapas et ON et.nombre = g.categoria_tp WHERE g.id = e.estado_glpi_real_id AND et.id = e.etapa_id)) ok,
                 COUNT(*) total FROM equipos e WHERE e.estado_glpi_real_id IS NOT NULL")->fetch();
$ultSabana = q('SELECT MAX(fecha) FROM sabanas')->fetchColumn();
// Calidad de los folios GLPI
$fq = q("SELECT SUM(folio_glpi IS NOT NULL) con_folio, SUM(folio_glpi IS NULL) sin_folio, SUM(glpi_existe = 1) existe, SUM(glpi_existe = 0) noexiste,
         SUM(glpi_existe = 1 AND glpi_corresponde = 1) corresponde, SUM(glpi_existe = 1 AND glpi_corresponde = 0) nocorresponde,
         SUM(folio_glpi IS NOT NULL AND glpi_existe IS NULL) sinconsultar FROM equipos")->fetch();
$repetidos = (int)q('SELECT COALESCE(SUM(n), 0) FROM (SELECT COUNT(*) n FROM equipos WHERE folio_glpi IS NOT NULL GROUP BY folio_glpi HAVING COUNT(*) > 1) x')->fetchColumn();
$ultSab = q('SELECT folios_glpi FROM sabanas WHERE folios_glpi IS NOT NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
$foliosSab = $ultSab ? (json_decode($ultSab, true) ?: []) : [];
$enSistema = $foliosSab ? array_flip(q('SELECT folio_glpi FROM equipos WHERE folio_glpi IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)) : [];
$noRegistrados = array_values(array_filter($foliosSab, fn($f) => !isset($enSistema[$f])));
$proyOtros = q("SELECT TRIM(SUBSTRING_INDEX(glpi_titulo, ' - ', 1)) proyecto, COUNT(*) n FROM equipos
    WHERE glpi_existe = 1 AND glpi_corresponde = 0 AND glpi_titulo IS NOT NULL GROUP BY proyecto ORDER BY n DESC")->fetchAll();
$conReal = (int)$conc['con_glpi'];

function fila_categorias(array $cats, array $colores, array $datos, string $param): void
{
    echo '<div class="rs-pulsott rs-6 mb-2">';
    foreach ($cats as $k) {
        $pp = $datos[$k] ?? ['total' => 0, 'detalle' => []];
        $det = implode(' · ', $pp['detalle'] ?? []) ?: 'Sin equipos';
        echo '<a class="rs-kpi rs-kpi-sm" style="--c:' . $colores[$k] . ($pp['total'] ? '' : ';opacity:.45') . '" href="equipos.php?' . $param . '=' . urlencode($k) . '" title="' . e($det) . '">'
            . '<div class="etq">' . e($k) . '</div><div class="num">' . num($pp['total']) . '</div><div class="sub">' . e($det) . '</div></a>';
    }
    echo '</div>';
}

function fila_pulsott(array $orden, array $datos, string $param, string $sinTexto): void
{
    echo '<div class="rs-pulsott mb-2">';
    foreach ($orden as $k => $col) {
        $pp = $datos[$k] ?? ['total' => 0, 'detalle' => []];
        $det = implode(' · ', $pp['detalle'] ?? []) ?: $sinTexto;
        echo '<a class="rs-kpi rs-kpi-sm" style="--c:' . $col . ($pp['total'] ? '' : ';opacity:.5') . '" href="equipos.php?' . $param . '=' . urlencode($k) . '" title="' . e($det) . '">'
            . '<div class="etq">' . e($k) . '</div><div class="num">' . num($pp['total']) . '</div><div class="sub">' . e($det) . '</div></a>';
    }
    echo '</div>';
}
?>
<div class="card rs-card mb-3">
  <div class="card-header"><span class="d-flex align-items-center gap-3 flex-wrap"><span>Estado GLPI → TrazaPulso</span>
    <span class="tp-selector" role="group" aria-label="Ver indicadores con">
      <span class="etq">Ver indicadores con:</span>
      <button type="button" class="opc" data-ver="sabana">Sábana</button>
      <button type="button" class="opc" data-ver="glpi">GLPI</button>
    </span></span>
    <span class="d-flex align-items-center gap-3"><a class="extra" href="glpi.php#conversion">Tabla de conversión</a>
    <?php if (puede('editor')): require_once __DIR__ . '/inc/glpi_api.php'; ?>
      <?php if (glpi_configurado()): ?><form method="post" action="glpi.php" class="m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="sincronizar"><input type="hidden" name="volver" value="index.php"><button class="btn btn-sm btn-tp"><i class="bi bi-cloud-download"></i> Actualizar desde GLPI</button></form>
      <?php elseif (puede('admin')): ?><a class="btn btn-sm btn-tp-out" href="glpi.php"><i class="bi bi-cloud-slash"></i> Configurar GLPI</a><?php endif; ?>
    <?php endif; ?></span></div>
  <div class="card-body pb-2">
    <section class="rs-sub-bloque mb-3">
    <div class="rs-paso"><i class="bi bi-ticket-detailed"></i><b>Folios GLPI</b> <span class="text-body-secondary">— ¿los folios reportados en la sábana se localizan en GLPI y coinciden con el equipo?</span></div>
    <div class="rs-folios mb-3">
      <a class="rs-kpi rs-kpi-sm" style="--c:#2f3b52" href="equipos.php" title="Folios GLPI distintos que venían en la última sábana aplicada"><div class="etq">Reportados en la sábana</div><div class="num"><?= num($foliosSab ? count($foliosSab) : $fq['con_folio']) ?></div><div class="sub"><?= $noRegistrados ? num(count($noRegistrados)) . ' no quedaron en el sistema' : 'folios GLPI' ?></div></a>
      <a class="rs-kpi rs-kpi-sm" style="--c:var(--tp-azul2)" href="equipos.php?folio_glpi_calidad=existe"><div class="etq">Localizados en GLPI</div><div class="num"><?= num($fq['existe']) ?></div><div class="sub">el ticket existe en GLPI</div></a>
      <a class="rs-kpi rs-kpi-sm" style="--c:var(--sem-rojo)<?= (int)$fq['noexiste'] ? '' : ';opacity:.5' ?>" href="equipos.php?folio_glpi_calidad=noexiste"><div class="etq">No localizados</div><div class="num"><?= num($fq['noexiste']) ?></div><div class="sub">no existen en GLPI o sin permiso</div></a>
      <a class="rs-kpi rs-kpi-sm" style="--c:var(--sem-verde)<?= (int)$fq['corresponde'] ? '' : ';opacity:.5' ?>" href="equipos.php?folio_glpi_calidad=corresponde"><div class="etq">Coinciden</div><div class="num"><?= num($fq['corresponde']) ?></div><div class="sub">con lo reportado (es ese equipo)</div></a>
      <a class="rs-kpi rs-kpi-sm" style="--c:var(--sem-naranja)<?= (int)$fq['nocorresponde'] ? '' : ';opacity:.5' ?>" href="equipos.php?folio_glpi_calidad=nocorresponde"><div class="etq">No coinciden</div><div class="num"><?= num($fq['nocorresponde']) ?></div><div class="sub">son de otro proyecto</div></a>
      <a class="rs-kpi rs-kpi-sm" style="--c:#868e96<?= ((int)$fq['sin_folio'] + $repetidos + (int)$fq['sinconsultar'] + count($noRegistrados)) ? '' : ';opacity:.5' ?>" href="equipos.php?folio_glpi_calidad=<?= $repetidos ? 'repetido' : ((int)$fq['sin_folio'] ? 'sinfolio' : 'sinconsultar') ?>"
         title="<?= e(($noRegistrados ? 'Folios de la sábana que no quedaron: ' . implode(', ', array_slice($noRegistrados, 0, 20)) . '. ' : '') . 'Repetidos: ' . $repetidos . ' · Sin folio: ' . (int)$fq['sin_folio'] . ' · Sin consultar: ' . (int)$fq['sinconsultar']) ?>">
        <div class="etq">Por revisar</div><div class="num"><?= num((int)$fq['sin_folio'] + $repetidos + (int)$fq['sinconsultar'] + count($noRegistrados)) ?></div>
        <div class="sub"><?= e(implode(' · ', array_filter([$noRegistrados ? count($noRegistrados) . ' fuera del sistema' : '', $repetidos ? $repetidos . ' repetidos' : '', (int)$fq['sin_folio'] ? $fq['sin_folio'] . ' sin folio' : '', (int)$fq['sinconsultar'] ? $fq['sinconsultar'] . ' sin consultar' : ''])) ?: 'nada pendiente') ?></div></a>
    </div>

    <?php if ((int)$fq['existe'] || (int)$fq['noexiste']): ?>
    <div class="rs-mensaje-folios mb-3 <?= (int)$fq['nocorresponde'] ? 'alerta' : 'ok' ?>">
      <div>
        <i class="bi <?= (int)$fq['nocorresponde'] ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' ?>"></i>
        Se localizaron <b><?= num($fq['existe']) ?> tickets en GLPI</b><?php if ((int)$fq['noexiste']): ?> (<b><?= num($fq['noexiste']) ?></b> no se encontraron)<?php endif; ?>.
        <?php if ((int)$fq['nocorresponde'] && !(int)$fq['corresponde']): ?>
          Pero <b>ninguno coincide con lo reportado</b> en la sábana: son tickets <b>de otro proyecto</b>.
        <?php elseif ((int)$fq['nocorresponde']): ?>
          <b><?= num($fq['corresponde']) ?></b> coinciden con lo reportado y <b><?= num($fq['nocorresponde']) ?></b> no coinciden: son tickets <b>de otro proyecto</b>.
        <?php else: ?>
          Todos coinciden con lo reportado en la sábana.
        <?php endif; ?>
      </div>
      <?php if ($proyOtros): ?>
      <div class="proyectos">
        <span class="small text-body-secondary">Los tickets que no coinciden pertenecen a:</span>
        <?php foreach (array_slice($proyOtros, 0, 8) as $po): ?>
          <a href="equipos.php?<?= e(http_build_query(['glpi_proyecto' => $po['proyecto'], 'folio_glpi_calidad' => 'nocorresponde'])) ?>"><b><?= num($po['n']) ?></b> <?= e($po['proyecto']) ?></a>
        <?php endforeach; ?>
        <?php if (count($proyOtros) > 8): ?><span class="small text-body-secondary">y <?= count($proyOtros) - 8 ?> más</span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    </section>

    <section class="rs-sub-bloque">
    <div class="rs-paso"><i class="bi bi-diagram-3"></i><b>Estado de los tickets</b> <span class="text-body-secondary">— según la opción elegida arriba (Sábana o GLPI), convertido a categorías de TrazaPulso</span></div>
    <div class="ind-bloque" data-ind="sabana">
    <div class="rs-paso"><span class="dot" style="background:#0ca678"></span><b>Según la sábana</b> <span class="text-body-secondary">— ESTATUS DE GLPI de tu Excel, convertido a categorías de TrazaPulso</span>
      <span class="ms-auto small text-body-secondary"><?= num($conc['con_sabana']) ?> de <?= num($total) ?> equipos<?= $ultSabana ? ' · sábana del ' . fecha($ultSabana) : '' ?></span></div>
    <?php if (!(int)$conc['con_sabana']): ?>
      <div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle"></i> Aún no hay estados en la sábana. Llena la columna <b>ESTATUS DE GLPI</b> en tu Excel y súbelo en <a href="sabana.php">Actualizar sábana</a>.</div>
    <?php else: fila_categorias($categorias, $catColor, $cSab, 'cat_sabana'); endif; ?>
    </div>

    <div class="ind-bloque" data-ind="glpi">
    <div class="rs-paso"><span class="dot" style="background:var(--tp-azul2)"></span><b>Según GLPI</b> <span class="text-body-secondary">— consultado en el servidor con "Actualizar GLPI", convertido a categorías de TrazaPulso</span>
      <span class="ms-auto small text-body-secondary"><?= num($conReal) ?> de <?= num($total) ?> equipos<?= $conc['ult'] ? ' · consultado ' . fecha($conc['ult']) : '' ?></span></div>
    <?php if (!$conReal): ?>
      <div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle"></i> Aún no se ha consultado GLPI. Da clic en <b>Actualizar GLPI</b>.</div>
    <?php else: fila_categorias($categorias, $catColor, $cReal, 'cat_glpi'); endif; ?>
    </div>

    <div class="rs-concilia mt-2 mb-2">
      <span class="fw-semibold"><i class="bi bi-arrow-left-right"></i> Sábana vs GLPI:</span>
      <a class="ok" href="equipos.php?concilia=ok"><b><?= num($conc['ok']) ?></b> iguales</a>
      <a class="dif" href="equipos.php?concilia=dif"><b><?= num($conc['dif']) ?></b> diferentes</a>
      <a class="sin" href="equipos.php?concilia=sin"><b><?= num($conc['sin']) ?></b> sin dato en alguno</a>
    </div>
    <?php if ($conReal): ?>
    <div class="rs-concilia mb-2">
      <span class="fw-semibold"><i class="bi bi-geo-alt"></i> GLPI vs dónde está físicamente:</span>
      <a class="ok" href="equipos.php?fisico=ok"><b><?= num($fis['ok']) ?></b> coinciden</a>
      <a class="dif" href="equipos.php?fisico=dif"><b><?= num((int)$fis['total'] - (int)$fis['ok']) ?></b> no coinciden</a>
      <span class="small text-body-secondary ms-auto">la categoría que da GLPI comparada con la etapa del equipo en TrazaPulso</span>
    </div>
    <?php endif; ?>
    </section>
  </div>
</div>

<!-- ============ Flujo por etapa ============ -->
<div class="card rs-card mb-3">
  <div class="card-header"><span>Flujo de equipos por etapa</span><span class="extra">Acuerdo más próximo: <b><?= $proximo ? e(fecha_corta($proximo['fecha_compromiso'])) : '—' ?></b></span></div>
  <div class="rs-toolbar">
    <div class="flex-grow-1" style="min-width:220px">
      <div class="rs-dist">
        <?php foreach (SEMAFORO as $k => [$nom, $col]): if (!$sem[$k]['n']) continue; ?><span style="background:<?= $col ?>;width:<?= round($sem[$k]['n'] * 100 / max(1, $total), 2) ?>%" title="<?= e($nom) ?>: <?= num($sem[$k]['n']) ?>"></span><?php endforeach; ?>
      </div>
    </div>
    <div class="rs-leyenda">
      <?php foreach (SEMAFORO as $k => [$nom, $col, $desc]): ?>
        <a href="equipos.php?etapas=<?= e(implode(',', $sem[$k]['ids']) ?: '0') ?>" title="<?= e($desc) ?>"><span class="dot" style="background:<?= $col ?>"></span><?= e($nom) ?> <b><?= num($sem[$k]['n']) ?></b></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="tbl" style="min-width:1240px">
      <colgroup><col style="width:240px"><col style="width:66px"><col style="width:60px"><col style="width:200px"><col style="width:76px"><col style="width:84px"><col style="width:76px"><col style="width:84px"><col style="width:150px"><col style="width:92px"><col></colgroup>
      <thead><tr><th>Etapa</th><th class="n">Total</th><th class="n" title="Porcentaje del total de equipos">%</th><th class="c"><?= e(implode(' vs ', $aniosEq)) ?></th><th class="n">Extrav.</th><th class="n">No extr.</th><th class="n">+7 días</th><th class="n" title="Entraron en los últimos 7 días">Nuevos</th><th>Acuerdo</th><th class="c">Fecha</th><th>Responsable</th></tr></thead>
      <tbody>
      <?php foreach ($etapas as $et):
          $col = SEMAFORO[semaforo_etapa($et)][1];
          $pct = $total ? round($et['total'] * 100 / $total) : 0;
          $ac = $acPorEtapa[(int)$et['id']] ?? null;
          $no = (int)$et['total'] - (int)$et['extrav']; ?>
        <tr>
          <td class="nom" title="<?= e($et['nombre']) ?>"><span class="dot" style="background:<?= $col ?>"></span><a href="equipos.php?etapa=<?= (int)$et['id'] ?>"><?= e($et['nombre']) ?></a></td>
          <td class="n fw-bold"><?= num($et['total']) ?></td>
          <td class="n fw-semibold"><?= $pct ?>%</td>
          <td><?= pill_anios(seg_anios($aniosEtapa, $et['id'], $aniosEq)) ?></td>
          <td class="n"><?= lnk(['etapa' => $et['id'], 'extraviado' => 1], $et['extrav'], 'txt-x fw-semibold') ?></td>
          <td class="n"><?= lnk(['etapa' => $et['id'], 'extraviado' => 0], $no, 'txt-o fw-semibold') ?></td>
          <td class="n"><?= (!$et['es_final'] && !$et['marca_extraviado']) ? lnk(['etapa' => $et['id'], 'dias_etapa' => 7], $et['estanc'], 'text-warning-emphasis fw-semibold') : '<span class="cero">—</span>' ?></td>
          <td class="n"><?= !empty($entradas[$et['nombre']]) ? '<span class="text-success fw-semibold">+' . (int)$entradas[$et['nombre']] . '</span>' : '<span class="cero">0</span>' ?></td>
          <td title="<?= e($ac['descripcion'] ?? '') ?>"><?= badge_acuerdo($ac) ?><?php if ($ac): ?> <a href="acuerdo.php?id=<?= (int)$ac['id'] ?>" class="small fw-semibold">#<?= (int)$ac['id'] ?></a><?php endif; ?></td>
          <td class="c"><?= $ac && $ac['fecha_compromiso'] ? e(fecha_corta($ac['fecha_compromiso'])) : '<span class="cero">—</span>' ?></td>
          <td class="small" title="<?= e($ac['responsable'] ?? '') ?>"><?= e($ac['responsable'] ?? '') ?: '<span class="cero">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($sinEtapa): ?><tr><td class="nom"><span class="dot" style="background:var(--sem-negro)"></span><a href="equipos.php?etapa=null">SIN ETAPA</a></td><td class="n fw-bold"><?= num($sinEtapa) ?></td><td colspan="9"></td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><td>Total</td><td class="n"><?= num($total) ?></td><td class="n">100%</td><td><?= pill_anios(seg_anios(['t' => array_reduce($aniosEtapa, function ($c, $m) { foreach ($m as $a => $n) $c[$a] = ($c[$a] ?? 0) + $n; return $c; }, [])], 't', $aniosEq)) ?></td><td class="n txt-x"><?= num($extrav) ?></td><td class="n txt-o"><?= num($localiz) ?></td><td class="n"><?= num($estancados) ?></td><td class="n"><?= num(array_sum($entradas)) ?></td><td colspan="3"></td></tr></tfoot>
    </table>
  </div>
</div>

<!-- ============ Estados GLPI y Pulsott ============ -->
<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="card rs-card h-100">
      <div class="card-header"><span>Estado GLPI</span><span class="extra"><?= num($conFolioGlpi) ?> de <?= num($total) ?> equipos con folio GLPI</span></div>
      <table class="tbl"><colgroup><col><col style="width:120px"><col style="width:56px"><col style="width:60px"><col style="width:64px"><col style="width:68px"><col style="width:30%"></colgroup>
        <thead><tr><th>Estado GLPI</th><th>Pulsott</th><th class="n">Total</th><th class="n">%</th><th class="n">Extrav.</th><th class="n">No extr.</th><th class="c"><?= e(implode(' vs ', $aniosEq)) ?></th></tr></thead>
        <tbody>
        <?php $tg = array_fill_keys($aniosEq, 0); foreach ($porGlpi as $g): $x = (int)$g['extrav']; $no = (int)$g['total'] - $x; $sg = seg_anios($aniosGlpi, $g['id'], $aniosEq); foreach ($sg as $a => $n) $tg[$a] += $n; ?>
          <tr<?= $g['total'] ? '' : ' style="opacity:.45"' ?>>
            <td class="nom"><a href="equipos.php?glpi=<?= (int)$g['id'] ?>"><?= e($g['nombre']) ?></a></td>
            <td class="small"><a class="text-body-secondary" href="equipos.php?pulsott=<?= urlencode((string)$g['estado_pulsott']) ?>"><?= e($g['estado_pulsott'] ?? '—') ?></a></td>
            <td class="n fw-semibold"><?= $g['total'] ? num($g['total']) : '<span class="cero">0</span>' ?></td>
            <td class="n small"><?= $total ? round($g['total'] * 100 / $total) : 0 ?>%</td>
            <td class="n"><?= lnk(['glpi' => $g['id'], 'extraviado' => 1], $x, 'txt-x') ?></td>
            <td class="n"><?= lnk(['glpi' => $g['id'], 'extraviado' => 0], $no, 'txt-o') ?></td>
            <td><?= pill_anios($sg) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td></td><td class="n"><?= num($total) ?></td><td class="n">100%</td><td class="n txt-x"><?= num($extrav) ?></td><td class="n txt-o"><?= num($localiz) ?></td><td><?= pill_anios($tg) ?></td></tr></tfoot>
      </table>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card rs-card h-100">
      <div class="card-header"><span>Estado GLPI → TrazaPulso</span><a class="extra" href="catalogos.php?t=estados_glpi">Equivalencias</a></div>
      <table class="tbl"><colgroup><col style="width:130px"><col style="width:56px"><col style="width:60px"><col></colgroup>
        <thead><tr><th>Pulsott</th><th class="n">Total</th><th class="n">%</th><th>Estados GLPI que incluye</th></tr></thead>
        <tbody>
        <?php foreach ($porPulsott as $k => $pp): ?>
          <tr<?= $pp['total'] ? '' : ' style="opacity:.45"' ?>>
            <td class="nom"><a href="equipos.php?pulsott=<?= urlencode($k) ?>"><?= e($k) ?></a></td>
            <td class="n fw-semibold"><?= $pp['total'] ? num($pp['total']) : '<span class="cero">0</span>' ?></td>
            <td class="n small"><?= $total ? round($pp['total'] * 100 / $total) : 0 ?>%</td>
            <td class="small text-body-secondary" title="<?= e(implode(', ', $pp['glpi'])) ?>"><?= e(implode(', ', $pp['glpi']) ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td class="n"><?= num($total) ?></td><td class="n">100%</td><td></td></tr></tfoot>
      </table>
    </div>
  </div>
</div>

<!-- ============ Por concepto ============ -->
<div class="card rs-card mb-3" id="por-concepto"><?php tarjeta_concepto($aniosEq, $anioC, $extC); ?></div>

<!-- ============ Responsable · Coordinador ============ -->
<div class="row g-3 mb-3">
  <div class="col-xl-6"><div class="card rs-card h-100"><div class="card-header"><span>Por responsable de seguimiento</span></div><?php tabla_grupo($porSeg, 'seguimiento', anios_grupo('e.seguimiento_id'), $aniosEq); ?></div></div>
  <div class="col-xl-6"><div class="card rs-card h-100"><div class="card-header"><span>Por coordinador</span></div><?php tabla_grupo($porCoord, 'coordinador', anios_grupo('e.coordinador_id'), $aniosEq); ?></div></div>
</div>

<!-- ============ Región · Tipo de equipo ============ -->
<div class="row g-3 mb-3">
  <div class="col-xl-6"><div class="card rs-card h-100"><div class="card-header"><span>Por región</span></div><?php tabla_grupo($porRegion, 'region', anios_grupo('u.region_id', 'LEFT JOIN ubicaciones u ON u.id = e.ubicacion_id'), $aniosEq); ?></div></div>
  <div class="col-xl-6"><div class="card rs-card h-100"><div class="card-header"><span>Por tipo de equipo</span></div><div class="tbl-scroll"><?php tabla_grupo($porTipo, 'tipo', anios_grupo('e.tipo_id'), $aniosEq); ?></div></div></div>
</div>

<!-- ============ Acuerdos abiertos ============ -->
<div class="card rs-card mb-3">
  <div class="card-header"><span>Acuerdos abiertos</span><a class="extra" href="acuerdos.php">Ver todos</a></div>
  <div class="tbl-scroll">
    <table class="tbl"><colgroup><col style="width:48px"><col><col style="width:110px"><col style="width:70px"><col style="width:120px"><col style="width:190px"></colgroup>
      <thead><tr><th>#</th><th>Acuerdo</th><th class="c">Fecha</th><th class="n">Eq.</th><th class="c">Estado</th><th>Responsable</th></tr></thead>
      <tbody>
      <?php if (!$acuerdos): ?><tr><td colspan="6" class="text-body-secondary">Sin acuerdos abiertos.</td></tr><?php endif; ?>
      <?php foreach ($acuerdos as $a): ?>
        <tr><td><a href="acuerdo.php?id=<?= (int)$a['id'] ?>"><?= (int)$a['id'] ?></a></td>
          <td title="<?= e($a['descripcion']) ?>"><a class="text-body" href="acuerdo.php?id=<?= (int)$a['id'] ?>"><?= e($a['descripcion']) ?></a></td>
          <td class="c"><?= $a['fecha_compromiso'] ? e(fecha_corta($a['fecha_compromiso'])) : '<span class="cero">—</span>' ?></td>
          <td class="n"><a href="equipos.php?acuerdo=<?= (int)$a['id'] ?>"><?= num($a['equipos']) ?></a></td>
          <td class="c"><?= badge_acuerdo($a) ?></td>
          <td class="small"><?= e($a['responsable'] ?? '') ?: '<span class="cero">—</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============ Últimos movimientos ============ -->
<div class="card rs-card">
  <div class="card-header"><span>Últimos movimientos</span><a class="extra" href="bitacora.php">Ver bitácora completa</a></div>
  <div class="table-responsive">
    <table class="tbl" style="min-width:760px"><colgroup><col style="width:140px"><col style="width:220px"><col><col style="width:150px"></colgroup>
      <thead><tr><th>Fecha</th><th>Equipo</th><th>Movimiento</th><th>Usuario</th></tr></thead>
      <tbody>
      <?php if (!$ultimos): ?><tr><td colspan="4" class="text-body-secondary">Aún no hay movimientos registrados por usuarios.</td></tr><?php endif; ?>
      <?php foreach ($ultimos as $m): ?>
        <tr>
          <td><?= fecha($m['fecha']) ?></td>
          <td><a href="equipo.php?id=<?= (int)$m['equipo_id'] ?>" class="mono"><?= e($m['serie'] ?: codigo_equipo((int)$m['equipo_id'])) ?></a> <span class="small text-body-secondary"><?= e($m['tipo']) ?></span></td>
          <td class="small" style="white-space:normal"><?php require __DIR__ . '/inc/cambios_resumen.php'; ?></td>
          <td class="small"><?= e($m['usuario'] ?? 'Sistema') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
// Filtros de "Por concepto": se actualiza solo la tarjeta, sin recargar ni mover la pantalla
(() => {
  const card = document.getElementById('por-concepto');
  if (!card) return;
  card.addEventListener('click', async ev => {
    const a = ev.target.closest('.rs-toolbar a.btn');
    if (!a) return;
    ev.preventDefault();
    const url = new URL(a.href, location.href);
    url.hash = '';
    const parcial = new URL(url); parcial.searchParams.set('parcial', 'concepto');
    card.style.minHeight = card.offsetHeight + 'px';
    card.style.opacity = '.6';
    try {
      const r = await fetch(parcial, { credentials: 'same-origin' });
      if (!r.ok) throw new Error(r.status);
      card.innerHTML = await r.text();
      history.replaceState(null, '', url.pathname + url.search);
    } catch (e) { location.href = a.href; }
    finally { card.style.opacity = ''; card.style.minHeight = ''; }
  });
})();
</script>
<script>
// Selector "Ver indicadores con: Sábana | GLPI" (sin recargar; recuerda la última elección)
(() => {
  const opcs = document.querySelectorAll('.tp-selector .opc');
  if (!opcs.length) return;
  const mostrar = v => {
    document.querySelectorAll('.ind-bloque').forEach(b => b.hidden = b.dataset.ind !== v);
    opcs.forEach(o => o.classList.toggle('activa', o.dataset.ver === v));
    try { localStorage.setItem('tp_ver_ind', v); } catch (e) {}
  };
  let ini = 'sabana';
  try { ini = localStorage.getItem('tp_ver_ind') || 'sabana'; } catch (e) {}
  opcs.forEach(o => o.addEventListener('click', () => mostrar(o.dataset.ver)));
  mostrar(ini);
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
