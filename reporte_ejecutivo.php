<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/reportes.php';
require_login();

[$desde, $hasta] = periodo();
$f = flujos_periodo($desde, $hasta);

$total = (int)q('SELECT COUNT(*) FROM equipos')->fetchColumn();
$etapas = q('SELECT et.*, (SELECT COUNT(*) FROM equipos e WHERE e.etapa_id = et.id) AS total,
    (SELECT COUNT(*) FROM equipos e WHERE e.etapa_id = et.id AND e.fecha_etapa < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS estancados
    FROM etapas et WHERE et.activo = 1 ORDER BY et.orden')->fetchAll();
$sinEtapa = (int)q('SELECT COUNT(*) FROM equipos WHERE etapa_id IS NULL')->fetchColumn();
$finales = array_sum(array_map(fn($e) => $e['es_final'] ? (int)$e['total'] : 0, $etapas));
$extrav = (int)q('SELECT COUNT(*) FROM equipos WHERE extraviado = 1')->fetchColumn();
$recep = array_column(array_filter($etapas, fn($e) => $e['registra_recepcion']), 'nombre');
$recibidos = array_sum(array_map(fn($n) => $f['entradas'][$n] ?? 0, $recep));
$cerrados = array_sum(array_map(fn($e) => $e['es_final'] ? ($f['entradas'][$e['nombre']] ?? 0) : 0, $etapas));

// Región x etapa
$pivot = [];
foreach (q("SELECT COALESCE(rg.nombre, '(SIN REGIÓN)') AS region, e.etapa_id, COUNT(*) n FROM equipos e
    LEFT JOIN ubicaciones u ON u.id = e.ubicacion_id LEFT JOIN regiones rg ON rg.id = u.region_id
    GROUP BY region, e.etapa_id")->fetchAll() as $r) {
    $pivot[$r['region']][(int)$r['etapa_id']] = (int)$r['n'];
}
ksort($pivot);
$colsPivot = array_filter($etapas, fn($e) => $e['total'] > 0);

$acVencidos = q("SELECT a.*, p.nombre AS responsable, (SELECT COUNT(*) FROM acuerdo_equipo ae WHERE ae.acuerdo_id = a.id) eq FROM acuerdos a LEFT JOIN personas p ON p.id = a.responsable_id WHERE a.estatus = 'abierto' AND a.fecha_compromiso < CURDATE() ORDER BY a.fecha_compromiso")->fetchAll();
$acProximos = q("SELECT a.*, p.nombre AS responsable, (SELECT COUNT(*) FROM acuerdo_equipo ae WHERE ae.acuerdo_id = a.id) eq FROM acuerdos a LEFT JOIN personas p ON p.id = a.responsable_id WHERE a.estatus = 'abierto' AND (a.fecha_compromiso IS NULL OR a.fecha_compromiso >= CURDATE()) ORDER BY a.fecha_compromiso IS NULL, a.fecha_compromiso LIMIT 10")->fetchAll();
$acCumplidos = q("SELECT a.*, p.nombre AS responsable FROM acuerdos a LEFT JOIN personas p ON p.id = a.responsable_id WHERE a.estatus = 'cumplido' AND a.cerrado_at BETWEEN ? AND ? ORDER BY a.cerrado_at", [$desde . ' 00:00:00', $hasta . ' 23:59:59'])->fetchAll();

$sub = 'Periodo: ' . fecha($desde, false) . ' al ' . fecha($hasta, false);
$titulo = 'Reporte ejecutivo';
require __DIR__ . '/inc/header.php';
barra_reporte('Reporte ejecutivo de trazabilidad');
form_periodo($desde, $hasta);
encabezado_impresion('Reporte ejecutivo de trazabilidad de equipos', $sub);
?>
<div class="reporte">
<p class="no-print text-body-secondary"><?= e($sub) ?></p>

<div class="row g-2 mb-3 text-center">
  <?php foreach ([
      ['Equipos totales', $total, ''], ['Pendientes de cerrar', $total - $finales, ''], ['Extraviados', $extrav, 'text-danger'],
      ['Movidos en el periodo', $f['equipos'], 'text-primary'], ['Recibidos en almacén', $recibidos, 'text-success'], ['Cerrados (destruidos/entregados)', $cerrados, ''],
      ['Localizados', $f['localizados'], 'text-success'],
  ] as [$l, $v, $c]): ?>
    <div class="col"><div class="border rounded p-2 h-100"><div class="fs-4 fw-bold <?= $c ?>"><?= num($v) ?></div><div class="small text-body-secondary"><?= e($l) ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <h2 class="h6">Equipos por etapa</h2>
    <table class="table table-sm table-bordered table-tp">
      <thead><tr><th>Etapa</th><th class="text-end">Actual</th><th class="text-end">%</th><th class="text-end">Entraron</th><th class="text-end">Salieron</th><th class="text-end" title="Más de 7 días en la etapa">+7 días</th></tr></thead>
      <tbody>
      <?php foreach ($etapas as $et): ?>
        <tr><td><?= etapa_badge($et['nombre'], $et['color']) ?></td><td class="text-end fw-semibold"><?= num($et['total']) ?></td>
          <td class="text-end small"><?= $total ? round($et['total'] * 100 / $total, 1) : 0 ?>%</td>
          <td class="text-end text-success"><?= ($f['entradas'][$et['nombre']] ?? 0) ? '+' . num($f['entradas'][$et['nombre']]) : '·' ?></td>
          <td class="text-end text-danger"><?= ($f['salidas'][$et['nombre']] ?? 0) ? '−' . num($f['salidas'][$et['nombre']]) : '·' ?></td>
          <td class="text-end"><?= (!$et['es_final'] && !$et['marca_extraviado'] && $et['estancados']) ? '<span class="text-warning-emphasis fw-semibold">' . num($et['estancados']) . '</span>' : '·' ?></td></tr>
      <?php endforeach; ?>
      <?php if ($sinEtapa): ?><tr><td>SIN ETAPA</td><td class="text-end"><?= num($sinEtapa) ?></td><td colspan="4"></td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr class="fw-bold"><td>Total</td><td class="text-end"><?= num($total) ?></td><td></td><td class="text-end"><?= num(array_sum($f['entradas'])) ?></td><td class="text-end"><?= num(array_sum($f['salidas'])) ?></td><td></td></tr></tfoot>
    </table>
    <?php if ($f['por_usuario']): ?>
      <p class="small mb-1"><b>Registros en bitácora del periodo:</b> <?= num($f['movimientos']) ?> —
        <?php $i = 0; foreach ($f['por_usuario'] as $u => $n) { if ($i++ >= 6) break; echo e($u) . ' (' . num($n) . ') '; } ?></p>
    <?php endif; ?>
  </div>
  <div class="col-lg-6">
    <h2 class="h6">Equipos por región y etapa</h2>
    <div class="table-responsive">
    <table class="table table-sm table-bordered table-tp small">
      <thead><tr><th>Región</th><?php foreach ($colsPivot as $c): ?><th class="text-end" style="writing-mode:vertical-rl;transform:rotate(180deg);max-height:120px"><?= e($c['nombre']) ?></th><?php endforeach; ?><th class="text-end">Total</th></tr></thead>
      <tbody>
      <?php foreach ($pivot as $reg => $fila): ?>
        <tr><td class="fw-semibold"><?= e($reg) ?></td><?php foreach ($colsPivot as $c): ?><td class="text-end"><?= ($fila[(int)$c['id']] ?? 0) ?: '<span class="text-body-tertiary">·</span>' ?></td><?php endforeach; ?><td class="text-end fw-bold"><?= num(array_sum($fila)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<div class="row g-3 mt-1 salto-evitar">
  <div class="col-lg-6">
    <h2 class="h6 text-danger">Acuerdos vencidos (<?= count($acVencidos) ?>)</h2>
    <?php if (!$acVencidos): ?><p class="small text-body-secondary">Ninguno.</p><?php else: ?>
    <table class="table table-sm table-tp small"><thead><tr><th>#</th><th>Acuerdo</th><th>Venció</th><th>Responsable</th><th class="text-end">Eq.</th></tr></thead><tbody>
      <?php foreach ($acVencidos as $a): ?><tr><td><?= (int)$a['id'] ?></td><td><?= e(mb_strimwidth($a['descripcion'], 0, 90, '…')) ?></td><td class="text-nowrap text-danger"><?= fecha($a['fecha_compromiso'], false) ?></td><td><?= e($a['responsable'] ?? '—') ?></td><td class="text-end"><?= (int)$a['eq'] ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
  </div>
  <div class="col-lg-6">
    <h2 class="h6">Acuerdos próximos</h2>
    <?php if (!$acProximos): ?><p class="small text-body-secondary">Ninguno abierto.</p><?php else: ?>
    <table class="table table-sm table-tp small"><thead><tr><th>#</th><th>Acuerdo</th><th>Compromiso</th><th>Responsable</th><th class="text-end">Eq.</th></tr></thead><tbody>
      <?php foreach ($acProximos as $a): ?><tr><td><?= (int)$a['id'] ?></td><td><?= e(mb_strimwidth($a['descripcion'], 0, 90, '…')) ?></td><td class="text-nowrap"><?= $a['fecha_compromiso'] ? fecha($a['fecha_compromiso'], false) : 'Sin fecha' ?></td><td><?= e($a['responsable'] ?? '—') ?></td><td class="text-end"><?= (int)$a['eq'] ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
    <?php if ($acCumplidos): ?><p class="small"><b class="text-success">Cumplidos en el periodo:</b> <?php foreach ($acCumplidos as $a) echo '#' . (int)$a['id'] . ' ' . e(mb_strimwidth($a['descripcion'], 0, 60, '…')) . '; '; ?></p><?php endif; ?>
  </div>
</div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
