<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/reportes.php';
require_login();

$rol = in_array(g('rol'), ['seguimiento', 'coordinador', 'ambos'], true) ? g('rol') : 'ambos';
$persona = g('persona', '');
$soloPend = g('todos_estatus') ? false : true;

$condRol = match ($rol) {
    'seguimiento' => 'e.seguimiento_id = ?',
    'coordinador' => 'e.coordinador_id = ?',
    default       => '(e.seguimiento_id = ? OR e.coordinador_id = ?)',
};
$nParams = $rol === 'ambos' ? 2 : 1;
$condPend = $soloPend ? ' AND COALESCE(et.es_final, 0) = 0' : '';

// personas con equipos o acuerdos
$personas = q("SELECT p.id, p.nombre,
    (SELECT COUNT(*) FROM equipos e LEFT JOIN etapas et ON et.id = e.etapa_id WHERE " . str_replace('?', 'p.id', $condRol) . $condPend . ") AS equipos,
    (SELECT COUNT(*) FROM acuerdos a WHERE a.responsable_id = p.id AND a.estatus = 'abierto') AS acuerdos
    FROM personas p WHERE p.activo = 1 HAVING equipos > 0 OR acuerdos > 0 ORDER BY p.nombre")->fetchAll();

$ids = $persona === 'todos' ? array_column($personas, 'id') : ($persona ? [(int)$persona] : []);

function equipos_de(int $pid, string $condRol, int $nParams, string $condPend): array
{
    return q(SQL_EQUIPO_SELECT . " WHERE $condRol $condPend ORDER BY et.orden, e.fecha_etapa, t.nombre, e.serie", array_fill(0, $nParams, $pid))->fetchAll();
}

if (g('xlsx') && $ids) {
    $filas = (function () use ($ids, $condRol, $nParams, $condPend) {
        foreach ($ids as $pid) {
            $pn = catalogo_nombre('personas', $pid);
            foreach (equipos_de($pid, $condRol, $nParams, $condPend) as $e) {
                yield [$pn, codigo_equipo((int)$e['id']), $e['serie'], $e['inventario'], $e['folio'], $e['tipo'], $e['etapa'],
                    $e['etapa_final'] ? null : dias_desde($e['fecha_etapa']), $e['concepto'], $e['ubicacion'], $e['region'], $e['sucursal'],
                    trim(($e['paqueteria'] ?? '') . ' ' . ($e['guia'] ?? '')), $e['seguimiento'], $e['coordinador'], $e['comentario']];
            }
        }
    })();
    enviar_xlsx('Pendientes_por_responsable', 'PENDIENTES',
        ['Responsable', 'ID', 'Serie', 'Inventario', 'Folio', 'Tipo', 'Etapa', 'Días en etapa', 'Concepto', 'Ubicación', 'Región', 'Sucursal', 'Envío', 'Seguimiento', 'Coordinador', 'Comentario'],
        $filas, [20, 10, 16, 12, 16, 22, 24, 8, 30, 28, 12, 18, 24, 18, 16, 50]);
}

$titulo = 'Pendientes por responsable';
require __DIR__ . '/inc/header.php';
barra_reporte('Pendientes por responsable', $ids ? ['<a class="btn btn-sm btn-outline-success" href="?' . e(qs(['xlsx' => 1])) . '"><i class="bi bi-file-earmark-excel"></i> Excel</a>'] : []);
?>
<form class="card card-body p-2 mb-3 no-print auto-submit" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label">Persona</label>
      <select name="persona" class="form-select form-select-sm">
        <option value="">— Elige —</option>
        <option value="todos" <?= $persona === 'todos' ? 'selected' : '' ?>>TODAS (una hoja por persona)</option>
        <?php foreach ($personas as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (string)$persona === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?> (<?= (int)$p['equipos'] ?> eq. · <?= (int)$p['acuerdos'] ?> ac.)</option><?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label">Como</label>
      <select name="rol" class="form-select form-select-sm">
        <option value="ambos" <?= $rol === 'ambos' ? 'selected' : '' ?>>Seguimiento o coordinador</option>
        <option value="seguimiento" <?= $rol === 'seguimiento' ? 'selected' : '' ?>>Solo seguimiento</option>
        <option value="coordinador" <?= $rol === 'coordinador' ? 'selected' : '' ?>>Solo coordinador</option>
      </select></div>
    <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="todos_estatus" value="1" id="te" <?= $soloPend ? '' : 'checked' ?> onchange="this.form.submit()"><label class="form-check-label small" for="te">Incluir destruidos/entregados</label></div></div>
  </div>
</form>

<?php if (!$ids): ?>
  <div class="card"><div class="table-responsive"><table class="table table-sm table-hover table-tp mb-0">
    <thead><tr><th>Persona</th><th class="text-end">Equipos pendientes</th><th class="text-end">Acuerdos abiertos</th><th></th></tr></thead><tbody>
    <?php foreach ($personas as $p): ?><tr><td><?= e($p['nombre']) ?></td><td class="text-end"><?= num($p['equipos']) ?></td><td class="text-end"><?= num($p['acuerdos']) ?></td><td class="text-end"><a href="?<?= e(qs(['persona' => $p['id']])) ?>">Ver reporte</a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>

<?php foreach ($ids as $i => $pid):
    $pn = catalogo_nombre('personas', $pid);
    $eqs = equipos_de($pid, $condRol, $nParams, $condPend);
    $acs = q("SELECT a.*, (SELECT COUNT(*) FROM acuerdo_equipo ae WHERE ae.acuerdo_id = a.id) eq FROM acuerdos a WHERE a.responsable_id = ? AND a.estatus = 'abierto' ORDER BY a.fecha_compromiso IS NULL, a.fecha_compromiso", [$pid])->fetchAll();
    $porEtapa = [];
    foreach ($eqs as $e) $porEtapa[$e['etapa'] ?? 'SIN ETAPA'][] = $e;
    $estanc = count(array_filter($eqs, fn($e) => !$e['etapa_final'] && dias_desde($e['fecha_etapa']) > 7 && $e['etapa'] !== 'EXTRAVIADO'));
?>
<div class="card mb-4 <?= $i > 0 ? 'salto-pagina' : '' ?>"><div class="card-body">
  <?php encabezado_impresion('Pendientes de ' . $pn, ($rol === 'ambos' ? 'Seguimiento y coordinación' : ucfirst($rol)) . ' · corte ' . date('d/m/Y')); ?>
  <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 no-print"><h2 class="h5 mb-1"><?= e($pn) ?></h2><span class="small text-body-secondary">corte <?= date('d/m/Y H:i') ?></span></div>
  <div class="d-flex flex-wrap gap-3 mb-2 small">
    <span><b><?= num(count($eqs)) ?></b> equipos</span>
    <span class="text-warning-emphasis"><b><?= num($estanc) ?></b> con más de 7 días sin avanzar</span>
    <span><b><?= num(count($acs)) ?></b> acuerdos abiertos</span>
    <?php foreach ($porEtapa as $et => $l): ?><span><?= e($et) ?>: <b><?= count($l) ?></b></span><?php endforeach; ?>
  </div>

  <?php if ($acs): ?>
  <h3 class="h6 mt-2">Acuerdos a su cargo</h3>
  <table class="table table-sm table-bordered table-tp small"><thead><tr><th>#</th><th>Acuerdo</th><th>Compromiso</th><th class="text-end">Eq.</th><th>Estado</th></tr></thead><tbody>
    <?php foreach ($acs as $a): ?><tr><td><?= (int)$a['id'] ?></td><td><?= e($a['descripcion']) ?></td><td class="text-nowrap"><?= $a['fecha_compromiso'] ? fecha($a['fecha_compromiso'], false) : 'Sin fecha' ?></td><td class="text-end"><?= (int)$a['eq'] ?></td><td><?= acuerdo_estado_badge($a) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>

  <?php foreach ($porEtapa as $et => $lista): ?>
    <h3 class="h6 mt-3"><?= etapa_badge($et, $lista[0]['etapa_color'] ?? null) ?> <span class="text-body-secondary fw-normal small"><?= count($lista) ?> equipos</span></h3>
    <table class="table table-sm table-bordered table-tp small mb-2">
      <thead><tr><th>Serie</th><th>Inventario</th><th>Folio</th><th>Tipo</th><th class="text-end">Días</th><th>Ubicación / sucursal</th><th>Envío</th><th>Nota</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $e): $de = $e['etapa_final'] ? null : dias_desde($e['fecha_etapa']); ?>
        <tr><td class="mono"><a href="equipo.php?id=<?= (int)$e['id'] ?>"><?= e($e['serie'] ?: codigo_equipo((int)$e['id'])) ?></a></td><td class="mono"><?= e($e['inventario']) ?></td><td class="mono"><?= e($e['folio']) ?></td><td><?= e($e['tipo']) ?></td>
          <td class="text-end <?= $de > 7 ? 'text-danger fw-semibold' : '' ?>"><?= $de ?? '' ?></td>
          <td><?= e($e['ubicacion']) ?><?= $e['sucursal'] ? ' / ' . e($e['sucursal']) : '' ?></td>
          <td class="text-nowrap"><?= e(trim(($e['paqueteria'] ?? '') . ' ' . ($e['guia'] ?? ''))) ?></td>
          <td><?= e(mb_strimwidth((string)$e['comentario'], 0, 80, '…')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
  <?php if (!$eqs && !$acs): ?><p class="text-body-secondary">Sin pendientes.</p><?php endif; ?>
</div></div>
<?php endforeach; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
