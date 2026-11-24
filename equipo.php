<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$id = (int)g('id', 0);
$eq = $id ? equipo_get($id) : null;
if ($id && !$eq) {
    flash('danger', 'El equipo no existe.');
    redirect('equipos.php');
}

if (is_post()) {
    csrf_check();
    $accion = p('accion');
    try {
        if ($accion === 'guardar') {
            require_rol('editor');
            $d = [];
            foreach (CAMPOS_EQUIPO as $k => $_) {
                if ($k === 'extraviado') $d[$k] = isset($_POST['extraviado']) ? 1 : 0;
                elseif (array_key_exists($k, $_POST)) $d[$k] = p($k);
            }
            foreach (['serie', 'inventario', 'folio', 'guia', 'sucursal'] as $k) {
                if (isset($d[$k]) && $d[$k] !== null) $d[$k] = mb_strtoupper(trim((string)$d[$k]));
            }
            if (empty($d['serie']) && empty($d['inventario'])) throw new RuntimeException('Captura al menos la serie o el inventario.');
            if (!empty($d['anio']) && ((int)$d['anio'] < 2000 || (int)$d['anio'] > 2100)) throw new RuntimeException('Año inválido.');

            db()->beginTransaction();
            $res = equipo_guardar($id ?: null, $d, $id ? 'edicion' : 'alta', p('mov_comentario'), uid());
            $mov = $res['mov'];
            if (!$mov && hay_archivos('evidencias')) {
                $mov = movimiento_insert($res['id'], 'comentario', [], 'Evidencia agregada', uid());
            }
            $nEv = evidencias_guardar('evidencias', [$res['id'] => $mov], p('ev_descripcion'), uid());
            db()->commit();

            if (!empty($d['serie'])) {
                $dups = (int)q('SELECT COUNT(*) FROM equipos WHERE serie = ? AND id <> ?', [$d['serie'], $res['id']])->fetchColumn();
                if ($dups) flash('warning', "Atención: hay $dups equipo(s) más con la serie {$d['serie']}.");
            }
            $msg = match ($res['estado']) {
                'nuevo' => 'Equipo registrado como ' . codigo_equipo($res['id']) . '.',
                'actualizado' => 'Cambios guardados y registrados en la bitácora.',
                default => $mov ? 'Movimiento registrado.' : 'No hubo cambios.',
            };
            flash($res['estado'] === 'sin_cambios' && !$mov ? 'info' : 'success', $msg . ($nEv ? " ($nEv evidencia(s) adjunta(s))" : ''));
            redirect('equipo.php?id=' . $res['id']);
        }
        if ($accion === 'vincular' && $id) {
            require_rol('editor');
            $aid = (int)p('acuerdo_id');
            if (!$aid && p('nuevo_acuerdo')) {
                q('INSERT INTO acuerdos (descripcion, fecha_compromiso, responsable_id, created_by) VALUES (?,?,?,?)',
                    [p('nuevo_acuerdo'), p('nuevo_fecha') ?: null, p('nuevo_responsable') ?: null, uid()]);
                $aid = (int)db()->lastInsertId();
            }
            if (!$aid) throw new RuntimeException('Selecciona un acuerdo o escribe uno nuevo.');
            acuerdo_vincular($aid, [$id], uid());
            flash('success', 'Equipo vinculado al acuerdo #' . $aid . '.');
            redirect('equipo.php?id=' . $id);
        }
        if ($accion === 'desvincular' && $id) {
            require_rol('editor');
            $aid = (int)p('acuerdo_id');
            if (q('DELETE FROM acuerdo_equipo WHERE acuerdo_id = ? AND equipo_id = ?', [$aid, $id])->rowCount()) {
                movimiento_insert($id, 'acuerdo', [], 'Desvinculado del acuerdo #' . $aid, uid());
            }
            flash('success', 'Equipo desvinculado del acuerdo.');
            redirect('equipo.php?id=' . $id);
        }
        if ($accion === 'eliminar' && $id) {
            require_rol('admin');
            q('DELETE FROM equipos WHERE id = ?', [$id]);
            flash('success', 'Equipo ' . codigo_equipo($id) . ' eliminado definitivamente.');
            redirect('equipos.php');
        }
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'Error: ' . $ex->getMessage());
        redirect('equipo.php' . ($id ? '?id=' . $id : ''));
    }
}

$movs = $evs = $acs = $acAbiertos = [];
if ($eq) {
    $movs = q('SELECT m.*, u.nombre AS usuario FROM movimientos m LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.equipo_id = ? ORDER BY m.fecha DESC, m.id DESC', [$id])->fetchAll();
    $evRows = q('SELECT ev.*, u.nombre AS usuario FROM evidencias ev LEFT JOIN usuarios u ON u.id = ev.usuario_id WHERE ev.equipo_id = ? ORDER BY ev.created_at DESC', [$id])->fetchAll();
    foreach ($evRows as $r) $evs[$r['movimiento_id'] ?? 0][] = $r;
    $acs = q('SELECT a.*, p.nombre AS responsable FROM acuerdos a JOIN acuerdo_equipo ae ON ae.acuerdo_id = a.id LEFT JOIN personas p ON p.id = a.responsable_id WHERE ae.equipo_id = ? ORDER BY a.estatus, a.fecha_compromiso', [$id])->fetchAll();
    $acAbiertos = q("SELECT id, descripcion, fecha_compromiso FROM acuerdos WHERE estatus = 'abierto' AND id NOT IN (SELECT acuerdo_id FROM acuerdo_equipo WHERE equipo_id = ?) ORDER BY fecha_compromiso", [$id])->fetchAll();
    $dupSerie = ($eq['serie'] && !in_array($eq['serie'], ['ILEGIBLE', 'SIN SERIE', 'S/N'], true))
        ? q('SELECT id FROM equipos WHERE serie = ? AND id <> ?', [$eq['serie'], $id])->fetchAll(PDO::FETCH_COLUMN) : [];
}
$v = $eq ?? ['extraviado' => 0, 'anio' => date('Y')];
$editar = puede('editor');

function ev_link(array $ev): string
{
    $ext = strtolower(pathinfo($ev['nombre_original'], PATHINFO_EXTENSION));
    $ico = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? 'bi-image' : ($ext === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-paperclip');
    return '<a href="evidencia.php?id=' . (int)$ev['id'] . '" target="_blank" class="me-2 small"><i class="bi ' . $ico . '"></i> ' . e($ev['nombre_original']) . '</a>';
}

$titulo = $eq ? ($eq['serie'] ?: codigo_equipo($id)) : 'Nuevo equipo';
require __DIR__ . '/inc/header.php';
?>
<nav class="small mb-2"><a href="equipos.php"><i class="bi bi-arrow-left"></i> Equipos</a></nav>

<?php if ($eq): ?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1"><span class="mono"><?= e($eq['serie'] ?: 'Sin serie') ?></span> <small class="text-body-secondary fs-6"><?= codigo_equipo($id) ?></small></h1>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="text-body-secondary"><?= e($eq['tipo'] ?? 'Tipo sin definir') ?></span>
      <?= etapa_badge($eq['etapa'], $eq['etapa_color']) ?>
      <?php if ($eq['folio_glpi'] || $eq['estado_glpi_real'] || $eq['estado_glpi_sabana']): $okG = $eq['estado_glpi_real'] && $eq['estado_glpi_real'] === $eq['estado_glpi_sabana']; ?>
      <span class="badge border <?= !$eq['estado_glpi_real'] || !$eq['estado_glpi_sabana'] ? 'text-bg-light' : ($okG ? 'text-bg-success' : 'text-bg-warning') ?>" title="Sábana = columna ESTATUS DE GLPI del Excel · GLPI = consultado directo en el servidor">
        <i class="bi bi-ticket-detailed"></i> GLPI<?= $eq['folio_glpi'] ? ' ' . e($eq['folio_glpi']) : '' ?> · Sábana: <b><?= e($eq['estado_glpi_sabana'] ?? 'sin dato') ?></b> · GLPI: <b><?= e($eq['estado_glpi_real'] ?? 'sin consultar') ?></b><?= $eq['estado_glpi_real'] && $eq['estado_glpi_sabana'] && !$okG ? ' ⚠' : '' ?></span>
      <?php endif; ?>
      <?php if ($eq['folio_glpi'] && $eq['glpi_existe'] !== null): ?>
        <span class="badge border <?= !$eq['glpi_existe'] ? 'text-bg-danger' : ($eq['glpi_corresponde'] ? 'text-bg-success' : 'text-bg-warning') ?>" title="<?= e($eq['glpi_titulo'] ?? '') ?>">
          <?= !$eq['glpi_existe'] ? 'El ticket ' . e($eq['folio_glpi']) . ' no existe en GLPI' : ($eq['glpi_corresponde'] ? '✓ El ticket corresponde al equipo' : '⚠ El ticket no menciona este equipo') ?></span>
        <?php if ($eq['glpi_titulo']): ?><div class="small text-body-secondary w-100 mt-1"><i class="bi bi-ticket-detailed"></i> Ticket <?= e($eq['folio_glpi']) ?>: <?= e($eq['glpi_titulo']) ?></div><?php endif; ?>
      <?php endif; ?>
      <?php if (puede('editor') && $eq['folio_glpi']): require_once __DIR__ . '/inc/glpi_api.php'; if (glpi_configurado()): ?>
        <form method="post" action="glpi.php" class="d-inline m-0 no-print"><?= csrf_field() ?><input type="hidden" name="accion" value="sincronizar"><input type="hidden" name="equipo_id" value="<?= (int)$id ?>"><input type="hidden" name="volver" value="equipo.php?id=<?= (int)$id ?>">
          <button class="btn btn-sm btn-tp-out py-0" title="Consultar en GLPI el ticket <?= e($eq['folio_glpi']) ?>"><i class="bi bi-cloud-download"></i> Consultar GLPI</button></form>
      <?php endif; endif; ?>
      <?php if ($eq['fecha_etapa'] && !$eq['etapa_final']): $de = dias_desde($eq['fecha_etapa']); ?><span class="badge <?= $de > 7 ? 'text-bg-danger' : 'text-bg-light border' ?>" title="Días en la etapa actual"><i class="bi bi-hourglass-split"></i> <?= $de ?> día<?= $de === 1 ? '' : 's' ?> en esta etapa</span><?php endif; ?>
      <?= badge($eq['concepto']) ?>
      <?php if ($eq['extraviado'] && $eq['etapa'] !== 'EXTRAVIADO'): ?><span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle"></i> EXTRAVIADO</span><?php endif; ?>
      <span class="badge text-bg-light border"><i class="bi bi-geo-alt"></i> <?= e($eq['ubicacion'] ?? 'Sin ubicación') ?><?= $eq['region'] ? ' · ' . e($eq['region']) : '' ?></span>
      <?php if ($eq['guia']): ?><span class="badge text-bg-light border"><i class="bi bi-truck"></i> <?= e(($eq['paqueteria'] ? $eq['paqueteria'] . ' ' : '') . $eq['guia']) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2 no-print">
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir ficha</button>
    <?php if (puede('admin')): ?>
    <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="eliminar">
      <button class="btn btn-sm btn-outline-danger" data-confirm="¿Eliminar DEFINITIVAMENTE este equipo y todo su historial? Si solo cambió de estatus, mejor registra un movimiento."><i class="bi bi-trash"></i></button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php if (!empty($dupSerie)): ?>
  <div class="alert alert-warning py-2"><i class="bi bi-files"></i> Hay otros equipos con la misma serie:
    <?php foreach ($dupSerie as $did): ?><a href="equipo.php?id=<?= (int)$did ?>"><?= codigo_equipo((int)$did) ?></a> <?php endforeach; ?></div>
<?php endif; ?>
<?php else: ?>
<h1 class="h4 mb-3">Nuevo equipo</h1>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><?= $editar ? ($eq ? '<i class="bi bi-pencil-square"></i> Datos actuales / registrar movimiento' : 'Datos del equipo') : 'Datos actuales' ?></div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="guardar">
          <fieldset <?= $editar ? '' : 'disabled' ?>>
          <div class="row g-2">
            <div class="col-6"><label class="form-label">Serie</label><input class="form-control form-control-sm mono" name="serie" value="<?= e($v['serie'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label">Inventario (SIAF)</label><input class="form-control form-control-sm mono" name="inventario" value="<?= e($v['inventario'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label">Folio</label><input class="form-control form-control-sm mono" name="folio" value="<?= e($v['folio'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label">Folio GLPI</label><input class="form-control form-control-sm mono" name="folio_glpi" value="<?= e($v['folio_glpi'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label">Estado GLPI (sábana) <span class="text-body-secondary fw-normal">· GLPI dice: <?= e($v['estado_glpi_real'] ?? 'sin consultar') ?></span></label><select class="form-select form-select-sm" name="estado_glpi_sabana_id"><?= opciones('estados_glpi', $v['estado_glpi_sabana_id'] ?? null, 'Sin dato') ?></select></div>
            <div class="col-6"><label class="form-label">Año</label><input class="form-control form-control-sm" type="number" name="anio" value="<?= e($v['anio'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label">Tipo de equipo</label><select class="form-select form-select-sm" name="tipo_id"><?= opciones('tipos_equipo', $v['tipo_id'] ?? null) ?></select></div>
            <div class="col-6"><label class="form-label">Proyecto</label><select class="form-select form-select-sm" name="proyecto_id"><?= opciones('proyectos', $v['proyecto_id'] ?? null) ?></select></div>
            <div class="col-12"><label class="form-label">Etapa <span class="text-body-secondary fw-normal">(dónde va el equipo en el flujo)</span></label><select class="form-select form-select-sm fw-semibold" name="etapa_id"><?= opciones('etapas', $v['etapa_id'] ?? null) ?></select></div>
            <div class="col-12"><label class="form-label">Concepto / motivo</label><select class="form-select form-select-sm" name="concepto_id"><?= opciones('conceptos', $v['concepto_id'] ?? null) ?></select></div>
            <div class="col-7"><label class="form-label">Ubicación</label><select class="form-select form-select-sm" name="ubicacion_id"><?= opciones('ubicaciones', $v['ubicacion_id'] ?? null) ?></select></div>
            <div class="col-5"><label class="form-label">Sucursal</label><input class="form-control form-control-sm" name="sucursal" list="dl-sucursales" value="<?= e($v['sucursal'] ?? '') ?>"></div>
            <div class="col-5"><label class="form-label">Paquetería</label><select class="form-select form-select-sm" name="paqueteria_id"><?= opciones('paqueterias', $v['paqueteria_id'] ?? null) ?></select></div>
            <div class="col-7"><label class="form-label">Guía(s)</label><input class="form-control form-control-sm mono" name="guia" value="<?= e($v['guia'] ?? '') ?>" placeholder="Separa varias con coma"></div>
            <div class="col-6"><label class="form-label">Fecha de recepción</label><input class="form-control form-control-sm" type="date" name="fecha_recepcion" value="<?= e($v['fecha_recepcion'] ?? '') ?>"></div>
            <div class="col-6"><label class="form-label">Seguimiento con</label><select class="form-select form-select-sm" name="seguimiento_id"><?= opciones('personas', $v['seguimiento_id'] ?? null) ?></select></div>
            <div class="col-6"><label class="form-label">Coordinador</label><select class="form-select form-select-sm" name="coordinador_id"><?= opciones('personas', $v['coordinador_id'] ?? null, 'Sin coordinador') ?></select></div>
            <div class="col-6 d-flex align-items-end"><div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" name="extraviado" id="extraviado" <?= !empty($v['extraviado']) ? 'checked' : '' ?>><label class="form-check-label" for="extraviado">Extraviado</label></div></div>
            <div class="col-12 small text-body-secondary"><i class="bi bi-magic"></i> Al pasar a <b>EXTRAVIADO</b> se marca extraviado; al salir de esa etapa se marca localizado. Al entrar a <b>EN ALMACEN CDMX</b> se pone la fecha de recepción de hoy si está vacía.</div>
            <div class="col-12"><label class="form-label">Nota actual del equipo</label><textarea class="form-control form-control-sm" name="comentario" rows="2"><?= e($v['comentario'] ?? '') ?></textarea></div>
          </div>
          <?php if ($editar): ?>
          <hr>
          <div class="mb-2"><label class="form-label"><i class="bi bi-chat-left-text"></i> Comentario del movimiento <span class="text-body-secondary fw-normal">(qué pasó, guía, quién confirmó…)</span></label>
            <textarea class="form-control form-control-sm" name="mov_comentario" rows="2" placeholder="Ej. Llegó a almacén CDMX, guía FedEx 773971798114, confirmó Miguel Álvarez"></textarea></div>
          <div class="mb-2"><label class="form-label"><i class="bi bi-paperclip"></i> Evidencias <span class="text-body-secondary fw-normal">(fotos, PDF, guías; máx. <?= round(cfg('upload_max') / 1048576) ?> MB c/u)</span></label>
            <input class="form-control form-control-sm" type="file" name="evidencias[]" multiple accept="<?= e('.' . implode(',.', cfg('upload_ext'))) ?>">
            <input class="form-control form-control-sm mt-1" name="ev_descripcion" placeholder="Descripción de la evidencia (opcional)"></div>
          <button class="btn btn-primary w-100"><i class="bi bi-save"></i> <?= $eq ? 'Guardar y registrar en bitácora' : 'Registrar equipo' ?></button>
          <?php endif; ?>
          </fieldset>
        </form>
        <datalist id="dl-sucursales"><?php foreach (q('SELECT DISTINCT sucursal FROM equipos WHERE sucursal IS NOT NULL ORDER BY sucursal LIMIT 500')->fetchAll(PDO::FETCH_COLUMN) as $su): ?><option value="<?= e($su) ?>"><?php endforeach; ?></datalist>
      </div>
    </div>

    <?php if ($eq): ?>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-calendar-check"></i> Acuerdos</div>
      <ul class="list-group list-group-flush">
        <?php if (!$acs): ?><li class="list-group-item text-body-secondary small">Sin acuerdos vinculados.</li><?php endif; ?>
        <?php foreach ($acs as $a): ?>
          <li class="list-group-item">
            <div class="d-flex justify-content-between gap-2">
              <a href="acuerdo.php?id=<?= (int)$a['id'] ?>" class="small">#<?= (int)$a['id'] ?> <?= e(mb_strimwidth($a['descripcion'], 0, 120, '…')) ?></a>
              <span class="text-nowrap"><?= acuerdo_estado_badge($a) ?></span>
            </div>
            <div class="d-flex justify-content-between small text-body-secondary">
              <span><?= $a['fecha_compromiso'] ? fecha($a['fecha_compromiso'], false) : 'Sin fecha' ?> · <?= e($a['responsable'] ?? 'Sin responsable') ?></span>
              <?php if ($editar): ?>
              <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="desvincular"><input type="hidden" name="acuerdo_id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-link btn-sm p-0 text-danger" data-confirm="¿Desvincular el equipo de este acuerdo?">Quitar</button></form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($editar): ?>
      <div class="card-body border-top">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="accion" value="vincular">
          <label class="form-label">Vincular a un acuerdo abierto</label>
          <select class="form-select form-select-sm mb-2" name="acuerdo_id">
            <option value="">— Nuevo acuerdo (abajo) —</option>
            <?php foreach ($acAbiertos as $a): ?><option value="<?= (int)$a['id'] ?>">#<?= (int)$a['id'] ?> · <?= e(mb_strimwidth($a['descripcion'], 0, 70, '…')) ?><?= $a['fecha_compromiso'] ? ' · ' . fecha($a['fecha_compromiso'], false) : '' ?></option><?php endforeach; ?>
          </select>
          <textarea class="form-control form-control-sm mb-2" name="nuevo_acuerdo" rows="2" placeholder="…o describe un nuevo acuerdo"></textarea>
          <div class="row g-2 mb-2">
            <div class="col-6"><input class="form-control form-control-sm" type="date" name="nuevo_fecha" title="Fecha compromiso"></div>
            <div class="col-6"><select class="form-select form-select-sm" name="nuevo_responsable"><?= opciones('personas', null, 'Responsable') ?></select></div>
          </div>
          <button class="btn btn-sm btn-outline-primary w-100">Vincular</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($eq): ?>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between"><span><i class="bi bi-clock-history"></i> Historial de trazabilidad</span><span class="text-body-secondary small"><?= count($movs) ?> registros</span></div>
      <div class="card-body">
        <div class="timeline">
        <?php foreach ($movs as $m): $o = ORIGENES[$m['origen']] ?? [$m['origen'], 'secondary']; $cambios = $m['cambios'] ? json_decode($m['cambios'], true) : []; ?>
          <div class="ev <?= e($m['origen']) ?>">
            <div class="d-flex flex-wrap justify-content-between gap-2">
              <div><span class="badge text-bg-<?= $o[1] ?>"><?= e($o[0]) ?></span> <span class="small fw-semibold"><?= fecha($m['fecha']) ?></span></div>
              <span class="small text-body-secondary"><i class="bi bi-person"></i> <?= e($m['usuario'] ?? 'Sistema') ?></span>
            </div>
            <?php if ($cambios): ?>
              <ul class="list-unstyled mb-1 mt-1 cambio">
              <?php foreach ($cambios as $c): ?>
                <li><span class="text-body-secondary"><?= e(CAMPOS_EQUIPO[$c['campo']][0] ?? $c['campo']) ?>:</span>
                  <?php if ($c['antes'] !== null): ?><span class="antes"><?= e($c['antes']) ?></span> <i class="bi bi-arrow-right"></i><?php endif; ?>
                  <?= $c['despues'] !== null ? '<span class="despues">' . e($c['despues']) . '</span>' : '<i class="text-body-secondary">(vacío)</i>' ?></li>
              <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($m['comentario']): ?><div class="small bg-body-tertiary rounded px-2 py-1 mt-1"><i class="bi bi-chat-left-text"></i> <?= nl2br(e($m['comentario'])) ?></div><?php endif; ?>
            <?php if (!empty($evs[$m['id']])): ?><div class="mt-1"><?php foreach ($evs[$m['id']] as $ev) echo ev_link($ev); ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
        <?php if (!empty($evs[0])): ?>
          <div class="border-top pt-2"><span class="small text-body-secondary">Otras evidencias:</span> <?php foreach ($evs[0] as $ev) echo ev_link($ev); ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
