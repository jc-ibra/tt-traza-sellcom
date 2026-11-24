<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/reportes.php';
require_login();

// Acepta los filtros del listado de equipos (región, tipo, seguimiento, año…), excepto "extraviado"
$filtros = $_GET;
unset($filtros['extraviado'], $filtros['tab']);
[$where, $params] = equipos_filtro($filtros);

$equipos = q(SQL_EQUIPO_SELECT . " WHERE $where ORDER BY t.nombre, e.serie", $params)->fetchAll();
$ext = array_values(array_filter($equipos, fn($e) => (int)$e['extraviado'] === 1));
$noExt = array_values(array_filter($equipos, fn($e) => (int)$e['extraviado'] === 0));
$anios = q('SELECT DISTINCT anio FROM equipos WHERE anio IS NOT NULL ORDER BY anio DESC')->fetchAll(PDO::FETCH_COLUMN);

/** Conteo por una columna: [valor => [extraviados, no extraviados]] */
function cruce(array $equipos, string $col, bool $ordenarPorClave = false): array
{
    $r = [];
    foreach ($equipos as $e) {
        $k = $e[$col] ?? '(Sin dato)';
        $r[$k] ??= [0, 0];
        $r[$k][(int)$e['extraviado'] === 1 ? 0 : 1]++;
    }
    if ($ordenarPorClave) krsort($r);
    else uasort($r, fn($a, $b) => ($b[0] + $b[1]) <=> ($a[0] + $a[1]));
    return $r;
}

function tabla_cruce(string $titulo, array $tabla, string $filtro = ''): void
{
    echo '<div class="card mb-3 salto-evitar"><div class="card-header">' . e($titulo) . '</div><div class="table-responsive"><table class="table table-sm table-tp mb-0">'
        . '<thead><tr><th></th><th class="text-end text-danger">Extraviados</th><th class="text-end">No extraviados</th><th class="text-end">Total</th><th style="width:28%">% extraviados</th></tr></thead><tbody>';
    $ta = $tb = 0;
    foreach ($tabla as $k => [$a, $b]) {
        $ta += $a; $tb += $b;
        $pct = round($a * 100 / max(1, $a + $b));
        $etq = $filtro && $k !== '(Sin dato)' ? '<a href="?' . e(qs([$filtro => $k, 'tab' => 'extraviados'])) . '">' . e($k) . '</a>' : e($k);
        echo '<tr><td>' . $etq . '</td><td class="text-end text-danger fw-semibold">' . num($a) . '</td><td class="text-end">' . num($b) . '</td><td class="text-end">' . num($a + $b) . '</td>'
            . '<td><div class="d-flex align-items-center gap-2"><div class="barra flex-grow-1"><span class="roja" style="width:' . $pct . '%"></span></div><span class="small">' . $pct . '%</span></div></td></tr>';
    }
    echo '</tbody><tfoot><tr class="fw-bold"><td>Total</td><td class="text-end text-danger">' . num($ta) . '</td><td class="text-end">' . num($tb) . '</td><td class="text-end">' . num($ta + $tb) . '</td><td></td></tr></tfoot></table></div></div>';
}

function lista_equipos(array $lista, string $id): void
{
    echo '<div class="card"><div class="card-body p-2 border-bottom no-print"><input type="search" class="form-control form-control-sm filtro-lista" data-tabla="' . $id . '" placeholder="Buscar en esta lista: serie, inventario, tipo, ubicación…"></div>'
        . '<div class="table-responsive tabla-scroll" style="max-height:65vh"><table class="table table-sm table-hover table-tp mb-0" id="' . $id . '">'
        . '<thead><tr><th>#</th><th>Serie</th><th>Inventario</th><th>Folio</th><th>Tipo</th><th>Año</th><th>Etapa</th><th>Concepto</th><th>Ubicación</th><th>Región</th><th>Seguimiento</th><th>Coordinador</th></tr></thead><tbody>';
    if (!$lista) echo '<tr><td colspan="12" class="text-center text-body-secondary py-4">Sin equipos.</td></tr>';
    foreach ($lista as $i => $e) {
        echo '<tr><td class="small text-body-secondary">' . ($i + 1) . '</td>'
            . '<td class="mono"><a href="equipo.php?id=' . (int)$e['id'] . '">' . e($e['serie'] ?: codigo_equipo((int)$e['id'])) . '</a></td>'
            . '<td class="mono small">' . e($e['inventario']) . '</td><td class="mono small">' . e($e['folio']) . '</td>'
            . '<td class="small">' . e($e['tipo']) . '</td><td class="small">' . e($e['anio']) . '</td><td class="small">' . e($e['etapa']) . '</td><td class="small">' . e($e['concepto']) . '</td>'
            . '<td class="small">' . e($e['ubicacion']) . '</td><td class="small">' . e($e['region']) . '</td>'
            . '<td class="small">' . e($e['seguimiento']) . '</td><td class="small">' . e($e['coordinador'] ?? '—') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

$hayFiltro = $where !== '1=1';
$tab = in_array(g('tab'), ['extraviados', 'no', 'resumen'], true) ? g('tab') : 'extraviados';
$nombresTab = ['extraviados' => 'extraviados', 'no' => 'no extraviados', 'resumen' => 'extraviados y no extraviados (resumen)'];

$titulo = 'Extraviados y no extraviados';
require __DIR__ . '/inc/header.php';
encabezado_impresion('Equipos ' . $nombresTab[$tab], (g('anio') ? 'Año ' . (int)g('anio') . ' · ' : '') . ($hayFiltro ? 'Con filtros aplicados' : 'Todos los equipos'));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
  <h1 class="h4 mb-0"><i class="bi bi-exclamation-triangle"></i> Equipos extraviados y no extraviados</h1>
  <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
</div>

<form class="card card-body p-2 mb-2 no-print auto-submit" method="get">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label">Año</label>
      <select name="anio" class="form-select form-select-sm"><option value="">Todos</option><?php foreach ($anios as $a): ?><option <?= (string)g('anio') === (string)$a ? 'selected' : '' ?>><?= (int)$a ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label">Región</label><select name="region" class="form-select form-select-sm"><?= opciones('regiones', g('region'), 'Todas') ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label">Tipo de equipo</label><select name="tipo" class="form-select form-select-sm"><?= opciones('tipos_equipo', g('tipo'), 'Todos') ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label">Seguimiento con</label><select name="seguimiento" class="form-select form-select-sm"><?= opciones('personas', g('seguimiento'), 'Todos') ?></select></div>
    <div class="col-12 col-md-2"><?php if ($hayFiltro): ?><a href="?tab=<?= e($tab) ?>" class="btn btn-sm btn-link">Quitar filtros</a><?php endif; ?></div>
  </div>
</form>

<div class="d-flex flex-wrap gap-2 mb-3 no-print small align-items-center">
  <span class="text-body-secondary">Año:</span>
  <a class="btn btn-sm <?= !g('anio') ? 'btn-primary' : 'btn-outline-primary' ?>" href="?<?= e(qs(['anio' => null])) ?>">Todos</a>
  <?php foreach ($anios as $a): ?><a class="btn btn-sm <?= (string)g('anio') === (string)$a ? 'btn-primary' : 'btn-outline-primary' ?>" href="?<?= e(qs(['anio' => $a])) ?>"><?= (int)$a ?></a><?php endforeach; ?>
</div>

<ul class="nav nav-tabs mb-0 no-print">
  <li class="nav-item"><a class="nav-link <?= $tab === 'extraviados' ? 'active fw-semibold' : '' ?>" href="?<?= e(qs(['tab' => 'extraviados'])) ?>"><i class="bi bi-x-octagon text-danger"></i> Extraviados <span class="badge text-bg-danger"><?= num(count($ext)) ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'no' ? 'active fw-semibold' : '' ?>" href="?<?= e(qs(['tab' => 'no'])) ?>"><i class="bi bi-check-circle text-success"></i> No extraviados <span class="badge text-bg-success"><?= num(count($noExt)) ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'resumen' ? 'active fw-semibold' : '' ?>" href="?<?= e(qs(['tab' => 'resumen'])) ?>"><i class="bi bi-bar-chart"></i> Resumen</a></li>
</ul>
<div class="pt-3">
<?php if ($tab === 'extraviados'): ?>
  <?php lista_equipos($ext, 'tabla-ext'); ?>
<?php elseif ($tab === 'no'): ?>
  <?php lista_equipos($noExt, 'tabla-noext'); ?>
<?php else: ?>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card kpi h-100"><div class="card-body"><div class="etq">Extraviados</div><div class="valor text-danger"><?= num(count($ext)) ?></div><div class="small text-body-secondary"><?= count($equipos) ? round(count($ext) * 100 / count($equipos), 1) : 0 ?>% del total</div></div></div></div>
    <div class="col-md-4"><div class="card kpi h-100"><div class="card-body"><div class="etq">No extraviados</div><div class="valor text-success"><?= num(count($noExt)) ?></div><div class="small text-body-secondary"><?= count($equipos) ? round(count($noExt) * 100 / count($equipos), 1) : 0 ?>% del total</div></div></div></div>
    <div class="col-md-4"><div class="card kpi h-100"><div class="card-body"><div class="etq">Total</div><div class="valor"><?= num(count($equipos)) ?></div></div></div></div>
  </div>
  <div class="row g-3">
    <div class="col-lg-6"><?php tabla_cruce('Por año', cruce($equipos, 'anio', true), 'anio'); tabla_cruce('Por tipo de equipo', cruce($equipos, 'tipo')); ?></div>
    <div class="col-lg-6"><?php tabla_cruce('Por concepto', cruce($equipos, 'concepto')); tabla_cruce('Por región', cruce($equipos, 'region')); tabla_cruce('Por responsable de seguimiento', cruce($equipos, 'seguimiento')); ?></div>
  </div>
<?php endif; ?>
</div>
<script>
document.querySelectorAll('.filtro-lista').forEach(f => f.addEventListener('input', () => {
  const t = f.value.toLowerCase();
  document.querySelectorAll('#' + f.dataset.tabla + ' tbody tr').forEach(tr => tr.style.display = tr.textContent.toLowerCase().includes(t) ? '' : 'none');
}));
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
