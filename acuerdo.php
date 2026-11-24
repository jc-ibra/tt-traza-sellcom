<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$id = (int)g('id');
$a = q('SELECT a.*, p.nombre AS responsable, u.nombre AS creador FROM acuerdos a LEFT JOIN personas p ON p.id = a.responsable_id LEFT JOIN usuarios u ON u.id = a.created_by WHERE a.id = ?', [$id])->fetch();
if (!$a) {
    flash('danger', 'Acuerdo no encontrado.');
    redirect('acuerdos.php');
}

if (is_post()) {
    csrf_check();
    require_rol('editor');
    $accion = p('accion');
    $ids = q('SELECT equipo_id FROM acuerdo_equipo WHERE acuerdo_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    if ($accion === 'editar') {
        if (!p('descripcion')) {
            flash('danger', 'La descripción es obligatoria.');
        } else {
            $cambios = [];
            if (p('descripcion') !== $a['descripcion']) $cambios[] = 'descripción';
            if ((p('fecha_compromiso') ?: null) !== $a['fecha_compromiso']) $cambios[] = 'fecha compromiso ' . (fecha($a['fecha_compromiso'], false) ?: 'sin fecha') . ' → ' . (p('fecha_compromiso') ? fecha(p('fecha_compromiso'), false) : 'sin fecha');
            if ((string)(p('responsable_id') ?: '') !== (string)($a['responsable_id'] ?? '')) $cambios[] = 'responsable → ' . (catalogo_nombre('personas', p('responsable_id')) ?? 'ninguno');
            q('UPDATE acuerdos SET descripcion = ?, fecha_compromiso = ?, responsable_id = ? WHERE id = ?',
                [p('descripcion'), p('fecha_compromiso') ?: null, p('responsable_id') ?: null, $id]);
            if ($cambios) {
                foreach ($ids as $eid) movimiento_insert((int)$eid, 'acuerdo', [], "Acuerdo #$id modificado: " . implode('; ', $cambios), uid());
            }
            flash('success', 'Acuerdo actualizado.');
        }
    } elseif (in_array($accion, ['cumplido', 'cancelado', 'reabrir'], true)) {
        $nuevo = $accion === 'reabrir' ? 'abierto' : $accion;
        q('UPDATE acuerdos SET estatus = ?, comentario_cierre = ?, cerrado_at = ? WHERE id = ?',
            [$nuevo, p('comentario_cierre') ?: null, $nuevo === 'abierto' ? null : date('Y-m-d H:i:s'), $id]);
        $txt = ['cumplido' => 'marcado como CUMPLIDO', 'cancelado' => 'CANCELADO', 'reabrir' => 'REABIERTO'][$accion];
        db()->beginTransaction();
        foreach ($ids as $eid) movimiento_insert((int)$eid, 'acuerdo', [], "Acuerdo #$id $txt" . (p('comentario_cierre') ? ': ' . p('comentario_cierre') : ''), uid());
        db()->commit();
        flash('success', "Acuerdo $txt. Se registró en la bitácora de " . count($ids) . ' equipos.');
    } elseif ($accion === 'eliminar') {
        require_rol('admin');
        foreach ($ids as $eid) movimiento_insert((int)$eid, 'acuerdo', [], "Acuerdo #$id eliminado", uid());
        q('DELETE FROM acuerdos WHERE id = ?', [$id]);
        flash('success', "Acuerdo #$id eliminado.");
        redirect('acuerdos.php');
    }
    redirect('acuerdo.php?id=' . $id);
}

$equipos = q(SQL_EQUIPO_SELECT . ' JOIN acuerdo_equipo ae ON ae.equipo_id = e.id WHERE ae.acuerdo_id = ? ORDER BY c.nombre, t.nombre, e.serie', [$id])->fetchAll();
$resumen = [];
foreach ($equipos as $e) $resumen[$e['concepto'] ?? '(Sin concepto)'] = ($resumen[$e['concepto'] ?? '(Sin concepto)'] ?? 0) + 1;

$titulo = 'Acuerdo #' . $id;
require __DIR__ . '/inc/header.php';
?>
<nav class="small mb-2"><a href="acuerdos.php"><i class="bi bi-arrow-left"></i> Acuerdos</a></nav>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Acuerdo #<?= $id ?> <?= acuerdo_estado_badge($a) ?></h1>
    <div class="text-body-secondary small">Creado <?= fecha($a['created_at']) ?> por <?= e($a['creador'] ?? 'Sistema') ?><?= $a['cerrado_at'] ? ' · Cerrado ' . fecha($a['cerrado_at']) : '' ?></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Detalle</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="accion" value="editar">
          <fieldset <?= puede('editor') ? '' : 'disabled' ?>>
            <div class="mb-2"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control form-control-sm" rows="4" required><?= e($a['descripcion']) ?></textarea></div>
            <div class="row g-2 mb-2">
              <div class="col-6"><label class="form-label">Fecha compromiso</label><input type="date" name="fecha_compromiso" class="form-control form-control-sm" value="<?= e($a['fecha_compromiso']) ?>"></div>
              <div class="col-6"><label class="form-label">Responsable</label><select name="responsable_id" class="form-select form-select-sm"><?= opciones('personas', $a['responsable_id']) ?></select></div>
            </div>
            <?php if (puede('editor')): ?><button class="btn btn-sm btn-outline-primary">Guardar cambios</button><?php endif; ?>
          </fieldset>
        </form>
      </div>
    </div>

    <?php if ($a['comentario_cierre']): ?><div class="alert alert-secondary small"><b>Comentario de cierre:</b> <?= nl2br(e($a['comentario_cierre'])) ?></div><?php endif; ?>

    <?php if (puede('editor')): ?>
    <div class="card mb-3">
      <div class="card-header">Cambiar estado</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <textarea name="comentario_cierre" class="form-control form-control-sm mb-2" rows="2" placeholder="Comentario (qué se hizo, evidencia, quién confirmó…)"></textarea>
          <div class="d-flex flex-wrap gap-2">
            <?php if ($a['estatus'] === 'abierto'): ?>
              <button name="accion" value="cumplido" class="btn btn-sm btn-success"><i class="bi bi-check2-circle"></i> Marcar cumplido</button>
              <button name="accion" value="cancelado" class="btn btn-sm btn-outline-secondary" data-confirm="¿Cancelar este acuerdo?">Cancelar acuerdo</button>
            <?php else: ?>
              <button name="accion" value="reabrir" class="btn btn-sm btn-outline-primary">Reabrir</button>
            <?php endif; ?>
            <?php if (puede('admin')): ?><button name="accion" value="eliminar" class="btn btn-sm btn-outline-danger ms-auto" data-confirm="¿Eliminar el acuerdo? Los equipos no se borran."><i class="bi bi-trash"></i></button><?php endif; ?>
          </div>
        </form>
        <div class="small text-body-secondary mt-2">El cambio de estado queda registrado en la bitácora de cada equipo vinculado.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Equipos vinculados (<?= count($equipos) ?>)</span>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-success" href="exportar.php?acuerdo=<?= $id ?>"><i class="bi bi-file-earmark-excel"></i> Exportar</a>
          <?php if (puede('editor') && $equipos): ?>
          <form method="post" action="masivo.php" class="m-0"><?= csrf_field() ?><input type="hidden" name="paso" value="formulario"><input type="hidden" name="filtro" value="acuerdo=<?= $id ?>">
            <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right"></i> Registrar movimiento a todos</button>
            <button class="btn btn-sm btn-outline-dark" formaction="actas.php" name="paso" value="nueva"><i class="bi bi-file-earmark-text"></i> Generar acta</button></form>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($resumen): ?><div class="card-body py-2 border-bottom small"><?php foreach ($resumen as $c => $n): ?><span class="me-3"><?= badge($c) ?> <b><?= $n ?></b></span><?php endforeach; ?></div><?php endif; ?>
      <div class="table-responsive tabla-scroll">
        <table class="table table-sm table-hover table-tp mb-0">
          <thead><tr><th>Serie</th><th>Inventario</th><th>Tipo</th><th>Concepto</th><th>Ubicación</th></tr></thead>
          <tbody>
          <?php if (!$equipos): ?><tr><td colspan="5" class="text-body-secondary text-center py-3">Sin equipos. Vincúlalos desde la ficha del equipo o con un movimiento masivo en el listado de equipos.</td></tr><?php endif; ?>
          <?php foreach ($equipos as $e): ?>
            <tr><td class="mono"><a href="equipo.php?id=<?= (int)$e['id'] ?>"><?= e($e['serie'] ?: codigo_equipo((int)$e['id'])) ?></a></td><td class="mono small"><?= e($e['inventario']) ?></td><td class="small"><?= e($e['tipo']) ?></td><td><?= badge($e['concepto']) ?></td><td class="small"><?= e($e['ubicacion']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
