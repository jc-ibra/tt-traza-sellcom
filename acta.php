<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/reportes.php';
require_login();

$id = (int)g('id');
$a = q('SELECT a.*, u.nombre AS creador, et.nombre AS etapa FROM actas a LEFT JOIN usuarios u ON u.id = a.created_by LEFT JOIN etapas et ON et.id = a.etapa_id WHERE a.id = ?', [$id])->fetch();
if (!$a) {
    flash('danger', 'Acta no encontrada.');
    redirect('actas.php');
}
$folio = 'ACTA-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);

if (is_post() && p('accion') === 'eliminar') {
    csrf_check();
    require_rol('admin');
    foreach (q('SELECT equipo_id FROM acta_equipo WHERE acta_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        movimiento_insert((int)$eid, 'acta', [], "$folio eliminada (los cambios de etapa que aplicó no se revierten)", uid());
    }
    q('DELETE FROM actas WHERE id = ?', [$id]);
    flash('success', "$folio eliminada.");
    redirect('actas.php');
}

$equipos = q(SQL_EQUIPO_SELECT . ' JOIN acta_equipo ae ON ae.equipo_id = e.id WHERE ae.acta_id = ? ORDER BY t.nombre, e.serie', [$id])->fetchAll();
$porTipo = [];
foreach ($equipos as $e) $porTipo[$e['tipo'] ?? 'SIN TIPO'] = ($porTipo[$e['tipo'] ?? 'SIN TIPO'] ?? 0) + 1;

if (g('xlsx')) {
    enviar_xlsx($folio, mb_substr($folio, 0, 31), ['#', 'ID', 'Serie', 'Inventario', 'Folio', 'Tipo', 'Ubicación', 'Sucursal'],
        (function () use ($equipos) { $i = 0; foreach ($equipos as $e) yield [++$i, codigo_equipo((int)$e['id']), $e['serie'], $e['inventario'], $e['folio'], $e['tipo'], $e['ubicacion'], $e['sucursal']]; })(),
        [5, 10, 18, 14, 18, 26, 30, 20]);
}

$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$t = strtotime($a['fecha']);
$fechaLarga = date('j', $t) . ' de ' . $meses[(int)date('n', $t)] . ' de ' . date('Y', $t);
$accion = ['entrega' => 'la entrega', 'recepcion' => 'la recepción', 'destruccion' => 'la destrucción'][$a['tipo']] ?? 'el movimiento';

$titulo = $folio;
require __DIR__ . '/inc/header.php';
barra_reporte($folio . ' · ' . (TIPOS_ACTA[$a['tipo']] ?? ''), [
    '<a class="btn btn-sm btn-outline-success" href="?id=' . $id . '&xlsx=1"><i class="bi bi-file-earmark-excel"></i> Excel</a>',
    puede('admin') ? '<form method="post" class="m-0">' . csrf_field() . '<input type="hidden" name="accion" value="eliminar"><button class="btn btn-sm btn-outline-danger" data-confirm="¿Eliminar el acta? Los cambios de etapa ya aplicados NO se revierten."><i class="bi bi-trash"></i></button></form>' : '',
]);
?>
<div class="card acta"><div class="card-body p-4">
  <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-3">
    <div><div class="fw-bold fs-5 text-uppercase"><?= e(TIPOS_ACTA[$a['tipo']] ?? 'Acta') ?> de equipos</div><div class="small">Sellcom · Control de trazabilidad</div></div>
    <div class="text-end"><div class="fw-bold fs-5"><?= e($folio) ?></div><div class="small">Fecha: <?= fecha($a['fecha'], false) ?></div></div>
  </div>

  <p style="text-align:justify">En <?= e($a['lugar'] ?: '________________________') ?>, el día <?= e($fechaLarga) ?>, se hace constar <?= $accion ?> de
    <b><?= num(count($equipos)) ?> equipo<?= count($equipos) === 1 ? '' : 's' ?></b> que se enlistan a continuación, identificados por número de serie e inventario.
    <?php if ($a['entrega']): ?>Entrega: <b><?= e($a['entrega']) ?></b>.<?php endif; ?>
    <?php if ($a['recibe']): ?>Recibe: <b><?= e($a['recibe']) ?></b>.<?php endif; ?></p>

  <p class="small mb-2"><b>Resumen por tipo:</b> <?php foreach ($porTipo as $tp => $n) echo e($tp) . ' (' . $n . ') · '; ?></p>

  <table class="table table-sm table-bordered small">
    <thead class="table-light"><tr><th style="width:4%">#</th><th>Serie</th><th>Inventario</th><th>Folio</th><th>Tipo de equipo</th><th>Ubicación / sucursal</th><th style="width:16%">Observaciones</th></tr></thead>
    <tbody>
    <?php foreach ($equipos as $i => $e): ?>
      <tr><td><?= $i + 1 ?></td><td class="mono"><?= e($e['serie']) ?></td><td class="mono"><?= e($e['inventario']) ?></td><td class="mono"><?= e($e['folio']) ?></td><td><?= e($e['tipo']) ?></td><td><?= e($e['ubicacion']) ?><?= $e['sucursal'] ? ' / ' . e($e['sucursal']) : '' ?></td><td></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($a['observaciones']): ?><p class="small"><b>Observaciones:</b> <?= nl2br(e($a['observaciones'])) ?></p><?php endif; ?>

  <div class="row text-center mt-5 pt-4 salto-evitar">
    <div class="col-4"><div class="border-top border-dark pt-1 mx-3"><b>ENTREGA</b><br><span class="small"><?= e($a['entrega'] ?: 'Nombre y firma') ?></span></div></div>
    <div class="col-4"><div class="border-top border-dark pt-1 mx-3"><b>RECIBE</b><br><span class="small"><?= e($a['recibe'] ?: 'Nombre y firma') ?></span></div></div>
    <div class="col-4"><div class="border-top border-dark pt-1 mx-3"><b>Vo. Bo.</b><br><span class="small">Coordinación</span></div></div>
  </div>
  <div class="small text-body-secondary mt-4">Generado en TrazaPulso por <?= e($a['creador']) ?> el <?= fecha($a['created_at']) ?><?= $a['etapa'] ? ' · Etapa aplicada: ' . e($a['etapa']) : '' ?>.</div>
</div></div>
<?php require __DIR__ . '/inc/footer.php'; ?>
