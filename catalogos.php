<?php
require __DIR__ . '/inc/bootstrap.php';
require_rol('editor');

$t = g('t', 'etapas');
if (!catalogo_valido($t)) $t = 'etapas';
const COLORES = ['secondary' => 'Gris', 'info' => 'Celeste', 'primary' => 'Azul', 'warning' => 'Amarillo', 'success' => 'Verde', 'dark' => 'Negro', 'danger' => 'Rojo', 'light' => 'Blanco'];
const FILTRO_CAT = ['tipo' => 'tipos_equipo', 'concepto' => 'conceptos', 'ubicacion' => 'ubicaciones', 'proyecto' => 'proyectos', 'etapa' => 'etapas', 'paqueteria' => 'paqueterias', 'region' => 'regiones'];

function usos(string $t, int $id): int
{
    $n = 0;
    foreach (CATALOGO_USOS[$t] as [$tabla, $col]) $n += (int)q("SELECT COUNT(*) FROM `$tabla` WHERE `$col` = ?", [$id])->fetchColumn();
    return $n;
}

if (is_post()) {
    csrf_check();
    $accion = p('accion');
    $id = (int)p('id');
    try {
        if ($accion === 'agregar') {
            $n = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)p('nombre'))));
            if ($n === '') throw new RuntimeException('Escribe un nombre.');
            if (q("SELECT id FROM `$t` WHERE nombre = ?", [$n])->fetchColumn()) throw new RuntimeException("\"$n\" ya existe (se ignoran mayúsculas y acentos).");
            q("INSERT INTO `$t` (nombre) VALUES (?)", [$n]);
            flash('success', "\"$n\" agregado.");
        } elseif ($accion === 'renombrar') {
            require_rol('admin');
            $n = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)p('nombre'))));
            if ($n === '') throw new RuntimeException('Escribe un nombre.');
            $otro = q("SELECT id FROM `$t` WHERE nombre = ? AND id <> ?", [$n, $id])->fetchColumn();
            if ($otro) throw new RuntimeException("Ya existe \"$n\". Usa \"Fusionar\" para unirlos.");
            q("UPDATE `$t` SET nombre = ? WHERE id = ?", [$n, $id]);
            flash('success', 'Nombre actualizado.');
        } elseif ($accion === 'activo') {
            require_rol('admin');
            q("UPDATE `$t` SET activo = 1 - activo WHERE id = ?", [$id]);
            flash('success', 'Estado actualizado.');
        } elseif ($accion === 'fusionar') {
            require_rol('admin');
            $destino = (int)p('destino');
            if (!$destino || $destino === $id) throw new RuntimeException('Elige un destino distinto.');
            $nOrig = catalogo_nombre($t, $id);
            $nDest = catalogo_nombre($t, $destino);
            db()->beginTransaction();
            $afectados = 0;
            foreach (CATALOGO_USOS[$t] as [$tabla, $col]) {
                if ($tabla === 'equipos') {
                    $eids = q("SELECT id FROM equipos WHERE `$col` = ?", [$id])->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($eids as $eid) {
                        movimiento_insert((int)$eid, 'edicion', [['campo' => $col, 'antes' => $nOrig, 'despues' => $nDest]], 'Unificación de catálogo', uid());
                    }
                    $afectados += count($eids);
                }
                q("UPDATE `$tabla` SET `$col` = ? WHERE `$col` = ?", [$destino, $id]);
            }
            q("DELETE FROM `$t` WHERE id = ?", [$id]);
            db()->commit();
            flash('success', "\"$nOrig\" se fusionó en \"$nDest\" ($afectados equipos actualizados y registrados en bitácora).");
        } elseif ($accion === 'etapa' && $t === 'etapas') {
            require_rol('admin');
            $color = isset(COLORES[p('color')]) ? p('color') : 'secondary';
            q('UPDATE etapas SET orden = ?, color = ?, es_final = ?, marca_extraviado = ?, registra_recepcion = ?, estado_glpi_id = ? WHERE id = ?',
                [(int)p('orden'), $color, p('es_final') ? 1 : 0, p('marca_extraviado') ? 1 : 0, p('registra_recepcion') ? 1 : 0, p('estado_glpi_id') ?: null, $id]);
            glpi_recalcular();
            flash('success', 'Etapa actualizada.');
        } elseif ($accion === 'pulsott' && $t === 'estados_glpi') {
            require_rol('admin');
            q('UPDATE estados_glpi SET estado_pulsott = ?, orden = ? WHERE id = ?', [mb_strtoupper(trim((string)p('estado_pulsott'))) ?: null, (int)p('orden'), $id]);
            flash('success', 'Equivalencia Pulsott actualizada.');
        } elseif ($accion === 'region' && $t === 'ubicaciones') {
            require_rol('admin');
            q('UPDATE ubicaciones SET region_id = ? WHERE id = ?', [p('region_id') ?: null, $id]);
            flash('success', 'Región asignada.');
        } elseif ($accion === 'eliminar') {
            require_rol('admin');
            if (usos($t, $id)) throw new RuntimeException('No se puede eliminar porque está en uso. Desactívalo o fusiónalo.');
            q("DELETE FROM `$t` WHERE id = ?", [$id]);
            flash('success', 'Eliminado.');
        }
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', $ex->getMessage());
    }
    redirect('catalogos.php?t=' . $t);
}

$usoSql = [];
foreach (CATALOGO_USOS[$t] as [$tabla, $col]) $usoSql[] = "(SELECT COUNT(*) FROM `$tabla` WHERE `$col` = x.id)";
$items = q("SELECT x.*, " . implode(' + ', $usoSql) . " AS usos FROM `$t` x ORDER BY x.activo DESC, " . ($t === 'etapas' ? 'x.orden, ' : ($t === 'ubicaciones' ? 'x.region_id IS NOT NULL, ' : '')) . "x.nombre")->fetchAll();
$extra = in_array($t, ['etapas', 'ubicaciones', 'estados_glpi'], true) && puede('admin');
$todos = catalogo($t);

$titulo = 'Catálogos';
require __DIR__ . '/inc/header.php';
?>
<h1 class="h4 mb-3">Catálogos</h1>
<ul class="nav nav-tabs mb-3">
  <?php foreach (CATALOGOS as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $t === $k ? 'active' : '' ?>" href="?t=<?= $k ?>"><?= e($l) ?></a></li><?php endforeach; ?>
</ul>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-sm table-hover table-tp mb-0">
          <thead><tr><th>Nombre</th><?php if ($extra): ?><th><?= ['etapas' => 'Orden · color · reglas · estado GLPI', 'ubicaciones' => 'Región', 'estados_glpi' => 'Orden · equivale en Pulsott'][$t] ?></th><?php endif; ?><th class="text-end">En uso</th><th>Estado</th><?php if (puede('admin')): ?><th class="text-end">Acciones</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr class="<?= $it['activo'] ? '' : 'text-body-tertiary' ?>">
              <td>
                <?php if (puede('admin')): ?>
                <form method="post" class="d-flex gap-1 m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="renombrar"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                  <input name="nombre" value="<?= e($it['nombre']) ?>" class="form-control form-control-sm border-0 bg-transparent px-1"><button class="btn btn-sm btn-link p-0 px-1" title="Guardar nombre"><i class="bi bi-check2"></i></button></form>
                <?php else: ?><?= e($it['nombre']) ?><?php endif; ?>
              </td>
              <?php if ($extra && $t === 'etapas'): ?>
              <td><form method="post" class="d-flex flex-wrap gap-1 align-items-center m-0 small"><?= csrf_field() ?><input type="hidden" name="accion" value="etapa"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <input type="number" name="orden" value="<?= (int)$it['orden'] ?>" class="form-control form-control-sm" style="width:70px" title="Orden en el flujo">
                <select name="color" class="form-select form-select-sm" style="width:105px"><?php foreach (COLORES as $ck => $cl): ?><option value="<?= $ck ?>" <?= $it['color'] === $ck ? 'selected' : '' ?>><?= $cl ?></option><?php endforeach; ?></select>
                <label class="form-check-label" title="Ya no requiere seguimiento (destruido, entregado)"><input type="checkbox" class="form-check-input" name="es_final" value="1" <?= $it['es_final'] ? 'checked' : '' ?>> Final</label>
                <label class="form-check-label" title="Al entrar, marca el equipo como extraviado"><input type="checkbox" class="form-check-input" name="marca_extraviado" value="1" <?= $it['marca_extraviado'] ? 'checked' : '' ?>> Extravío</label>
                <label class="form-check-label" title="Al entrar, pone fecha de recepción si está vacía"><input type="checkbox" class="form-check-input" name="registra_recepcion" value="1" <?= $it['registra_recepcion'] ? 'checked' : '' ?>> Recepción</label>
                <select name="estado_glpi_id" class="form-select form-select-sm" style="width:170px" title="Estado GLPI de esta etapa"><?= opciones('estados_glpi', $it['estado_glpi_id'], 'GLPI: —') ?></select>
                <button class="btn btn-sm btn-link p-0"><i class="bi bi-check2"></i></button>
                <?= etapa_badge($it['nombre'], $it['color']) ?></form></td>
              <?php elseif ($extra && $t === 'estados_glpi'): ?>
              <td><form method="post" class="d-flex gap-1 align-items-center m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="pulsott"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <input type="number" name="orden" value="<?= (int)$it['orden'] ?>" class="form-control form-control-sm" style="width:70px" title="Orden">
                <input name="estado_pulsott" value="<?= e($it['estado_pulsott']) ?>" class="form-control form-control-sm" style="width:170px" placeholder="Estado en Pulsott">
                <button class="btn btn-sm btn-link p-0"><i class="bi bi-check2"></i></button></form></td>
              <?php elseif ($extra && $t === 'ubicaciones'): ?>
              <td><form method="post" class="m-0 auto-submit"><?= csrf_field() ?><input type="hidden" name="accion" value="region"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <select name="region_id" class="form-select form-select-sm <?= $it['region_id'] ? '' : 'border-warning' ?>" style="width:140px"><?= opciones('regiones', $it['region_id'], 'Sin región') ?></select></form></td>
              <?php endif; ?>
              <td class="text-end"><?php $fk = array_search($t, FILTRO_CAT, true); if ($it['usos'] && $fk): ?><a href="equipos.php?<?= e(http_build_query([$fk => $it['id']])) ?>"><?= num($it['usos']) ?></a><?php else: ?><?= num($it['usos']) ?><?php endif; ?></td>
              <td><?= $it['activo'] ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Inactivo</span>' ?></td>
              <?php if (puede('admin')): ?>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="activo"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Activar / desactivar"><i class="bi bi-toggle-<?= $it['activo'] ? 'on' : 'off' ?>"></i></button></form>
                <form method="post" class="d-inline-flex gap-1"><?= csrf_field() ?><input type="hidden" name="accion" value="fusionar"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                  <select name="destino" class="form-select form-select-sm" style="width:170px"><option value="">Fusionar en…</option><?php foreach ($todos as $tid => $tn) if ($tid != $it['id']) echo '<option value="' . (int)$tid . '">' . e($tn) . '</option>'; ?></select>
                  <button class="btn btn-sm btn-outline-warning" data-confirm="¿Fusionar? Todos los registros pasarán al destino y este elemento se eliminará." title="Fusionar"><i class="bi bi-intersect"></i></button></form>
                <?php if (!$it['usos']): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="¿Eliminar?"><i class="bi bi-trash"></i></button></form><?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <form method="post" class="card card-body">
      <?= csrf_field() ?><input type="hidden" name="accion" value="agregar">
      <label class="form-label">Agregar a <?= e(CATALOGOS[$t]) ?></label>
      <div class="input-group input-group-sm"><input name="nombre" class="form-control" required><button class="btn btn-primary">Agregar</button></div>
    </form>
    <div class="card card-body mt-3 small text-body-secondary">
      <p class="mb-1"><b>Fusionar</b> sirve para unificar nombres duplicados (ej. "TIJUANA" y "PFS00017- SELLCOM - TIJUANA"). Los equipos afectados registran el cambio en su bitácora.</p>
      <p class="mb-0"><b>Desactivar</b> oculta la opción en los formularios sin perder el historial.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
