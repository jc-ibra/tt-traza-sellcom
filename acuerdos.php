<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (is_post()) {
    csrf_check();
    require_rol('editor');
    $desc = p('descripcion');
    if (!$desc) {
        flash('danger', 'Escribe la descripción del acuerdo.');
    } else {
        q('INSERT INTO acuerdos (descripcion, fecha_compromiso, responsable_id, created_by) VALUES (?,?,?,?)',
            [$desc, p('fecha_compromiso') ?: null, p('responsable_id') ?: null, uid()]);
        $nid = (int)db()->lastInsertId();
        flash('success', "Acuerdo #$nid creado. Ahora vincula los equipos (desde el listado de equipos → Registrar movimiento).");
        redirect('acuerdo.php?id=' . $nid);
    }
    redirect('acuerdos.php');
}

$vista = g('vista', 'abiertos');
$where = match ($vista) {
    'vencidos' => "a.estatus = 'abierto' AND a.fecha_compromiso < CURDATE()",
    'semana'   => "a.estatus = 'abierto' AND a.fecha_compromiso BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
    'cerrados' => "a.estatus <> 'abierto'",
    'todos'    => '1=1',
    default    => "a.estatus = 'abierto'",
};
$params = [];
if (g('responsable')) {
    $where .= ' AND a.responsable_id = ?';
    $params[] = (int)g('responsable');
}
$acuerdos = q("SELECT a.*, p.nombre AS responsable, u.nombre AS creador,
        (SELECT COUNT(*) FROM acuerdo_equipo ae WHERE ae.acuerdo_id = a.id) AS equipos
    FROM acuerdos a LEFT JOIN personas p ON p.id = a.responsable_id LEFT JOIN usuarios u ON u.id = a.created_by
    WHERE $where ORDER BY a.estatus = 'abierto' DESC, a.fecha_compromiso IS NULL, a.fecha_compromiso, a.id", $params)->fetchAll();

$cuentas = q("SELECT
    SUM(estatus = 'abierto') abiertos,
    SUM(estatus = 'abierto' AND fecha_compromiso < CURDATE()) vencidos,
    SUM(estatus = 'abierto' AND fecha_compromiso BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) semana,
    SUM(estatus <> 'abierto') cerrados, COUNT(*) todos FROM acuerdos")->fetch();

$titulo = 'Acuerdos';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0">Acuerdos y compromisos</h1>
  <?php if (puede('editor')): ?><button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#nuevo"><i class="bi bi-plus-lg"></i> Nuevo acuerdo</button><?php endif; ?>
</div>

<?php if (puede('editor')): ?>
<div class="collapse mb-3" id="nuevo">
  <form method="post" class="card card-body">
    <?= csrf_field() ?>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control form-control-sm" rows="2" required placeholder="Ej. Entregar equipos en bodega Gavilanes"></textarea></div>
      <div class="col-md-2"><label class="form-label">Fecha compromiso</label><input type="date" name="fecha_compromiso" class="form-control form-control-sm"></div>
      <div class="col-md-3"><label class="form-label">Responsable</label><select name="responsable_id" class="form-select form-select-sm"><?= opciones('personas', null) ?></select></div>
      <div class="col-md-1 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100">Crear</button></div>
    </div>
  </form>
</div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
  <?php foreach (['abiertos' => 'Abiertos', 'vencidos' => 'Vencidos', 'semana' => 'Vencen en 7 días', 'cerrados' => 'Cerrados', 'todos' => 'Todos'] as $k => $l): ?>
    <li class="nav-item"><a class="nav-link <?= $vista === $k ? 'active' : '' ?>" href="?vista=<?= $k ?>"><?= $l ?> <span class="badge <?= $k === 'vencidos' && $cuentas['vencidos'] ? 'text-bg-danger' : 'text-bg-light' ?>"><?= (int)$cuentas[$k] ?></span></a></li>
  <?php endforeach; ?>
</ul>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-tp mb-0">
      <thead><tr><th>#</th><th>Acuerdo</th><th>Compromiso</th><th>Responsable</th><th class="text-end">Equipos</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$acuerdos): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">No hay acuerdos en esta vista.</td></tr><?php endif; ?>
      <?php foreach ($acuerdos as $a): ?>
        <tr>
          <td><a href="acuerdo.php?id=<?= (int)$a['id'] ?>"><?= (int)$a['id'] ?></a></td>
          <td><a href="acuerdo.php?id=<?= (int)$a['id'] ?>" class="text-body"><?= e(mb_strimwidth($a['descripcion'], 0, 160, '…')) ?></a></td>
          <td class="text-nowrap"><?= $a['fecha_compromiso'] ? fecha($a['fecha_compromiso'], false) : '<span class="text-body-tertiary">Sin fecha</span>' ?></td>
          <td class="small"><?= e($a['responsable'] ?? '—') ?></td>
          <td class="text-end"><a href="equipos.php?acuerdo=<?= (int)$a['id'] ?>"><?= num($a['equipos']) ?></a></td>
          <td><?= acuerdo_estado_badge($a) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
