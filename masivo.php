<?php
require __DIR__ . '/inc/bootstrap.php';
require_rol('editor');

if (!is_post()) redirect('equipos.php');
csrf_check();

// --- obtener ids ---
$ids = [];
if (p('ids_csv')) {
    $ids = array_map('intval', explode(',', p('ids_csv')));
} elseif (!empty($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
} elseif (p('filtro') !== null) {
    parse_str((string)p('filtro'), $f);
    [$where, $params] = equipos_filtro($f);
    $ids = q("SELECT e.id FROM equipos e WHERE $where", $params)->fetchAll(PDO::FETCH_COLUMN);
}
$ids = array_values(array_unique(array_filter($ids)));
if (!$ids) {
    flash('warning', 'No seleccionaste equipos.');
    redirect('equipos.php');
}

/** Convierte cualquier URL en una ruta local de la app (evita redirecciones externas). */
function volver_local(string $url): string
{
    $u = parse_url($url) ?: [];
    $pag = basename($u['path'] ?? '');
    if (!preg_match('/^[a-z_]+\.php$/', $pag) || $pag === 'masivo.php') return 'equipos.php';
    return $pag . (isset($u['query']) ? '?' . $u['query'] : '');
}

// campos que se pueden cambiar en bloque
$campos = ['etapa_id' => 'etapas', 'paqueteria_id' => 'paqueterias', 'concepto_id' => 'conceptos', 'ubicacion_id' => 'ubicaciones', 'seguimiento_id' => 'personas', 'coordinador_id' => 'personas', 'tipo_id' => 'tipos_equipo', 'proyecto_id' => 'proyectos'];

if (p('paso') === 'aplicar') {
    $d = [];
    foreach ($campos as $k => $_) {
        $v = p($k, '__');
        if ($v === '__') continue;          // no cambiar
        $d[$k] = $v === '__null' ? null : $v;
    }
    foreach (['guia', 'sucursal'] as $k) {
        if (p($k) !== null && p($k) !== '') $d[$k] = mb_strtoupper((string)p($k));
    }
    if (p('fecha_recepcion')) $d['fecha_recepcion'] = p('fecha_recepcion');
    $ex = p('extraviado', '__');
    if ($ex === '1' || $ex === '0') $d['extraviado'] = (int)$ex;
    $coment = p('mov_comentario');
    $acuerdoId = (int)p('acuerdo_id');
    $nuevoAc = p('nuevo_acuerdo');

    if (!$d && !$coment && !$acuerdoId && !$nuevoAc && !hay_archivos('evidencias')) {
        flash('warning', 'No indicaste ningún cambio, comentario, acuerdo ni evidencia.');
        redirect('equipos.php');
    }
    try {
        db()->beginTransaction();
        $mapa = [];
        $cambiados = 0;
        $conEvidencia = hay_archivos('evidencias');
        foreach ($ids as $eid) {
            $res = equipo_guardar($eid, $d, 'masivo', $coment, uid());
            $mov = $res['mov'];
            if (!$mov && $conEvidencia) $mov = movimiento_insert($eid, 'comentario', [], 'Evidencia agregada (masivo)', uid());
            $mapa[$eid] = $mov;
            if ($res['estado'] === 'actualizado') $cambiados++;
        }
        $nEv = evidencias_guardar('evidencias', $mapa, p('ev_descripcion'), uid());
        $vinc = 0;
        if (!$acuerdoId && $nuevoAc) {
            q('INSERT INTO acuerdos (descripcion, fecha_compromiso, responsable_id, created_by) VALUES (?,?,?,?)',
                [$nuevoAc, p('nuevo_fecha') ?: null, p('nuevo_responsable') ?: null, uid()]);
            $acuerdoId = (int)db()->lastInsertId();
        }
        if ($acuerdoId) $vinc = acuerdo_vincular($acuerdoId, $ids, uid());
        db()->commit();
        flash('success', sprintf('Movimiento masivo aplicado: %d equipos procesados, %d con cambios%s%s.',
            count($ids), $cambiados, $nEv ? ", $nEv evidencia(s)" : '', $acuerdoId ? ", $vinc vinculados al acuerdo #$acuerdoId" : ''));
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', 'No se aplicó ningún cambio: ' . $ex->getMessage());
    }
    redirect(volver_local((string)p('volver')));
}

// --- formulario ---
$muestra = q(SQL_EQUIPO_SELECT . ' WHERE e.id IN (' . implode(',', $ids) . ') ORDER BY t.nombre, e.serie LIMIT 200')->fetchAll();
$resumen = q('SELECT et.nombre, et.color, COUNT(*) n FROM equipos e LEFT JOIN etapas et ON et.id = e.etapa_id WHERE e.id IN (' . implode(',', $ids) . ') GROUP BY et.nombre, et.color, et.orden ORDER BY et.orden')->fetchAll();
$acAbiertos = q("SELECT id, descripcion, fecha_compromiso FROM acuerdos WHERE estatus = 'abierto' ORDER BY fecha_compromiso")->fetchAll();
$volver = volver_local($_SERVER['HTTP_REFERER'] ?? '');

function sel_masivo(string $name, string $tabla, bool $permiteVacio = true): string
{
    $h = '<select class="form-select form-select-sm" name="' . $name . '"><option value="__">— No cambiar —</option>';
    if ($permiteVacio) $h .= '<option value="__null">(Dejar vacío)</option>';
    return $h . preg_replace('/^<option value="">[^<]*<\/option>/', '', opciones($tabla, null, '')) . '</select>';
}

$titulo = 'Movimiento masivo';
require __DIR__ . '/inc/header.php';
?>
<nav class="small mb-2"><a href="<?= e($volver) ?>"><i class="bi bi-arrow-left"></i> Volver</a></nav>
<h1 class="h4">Registrar movimiento a <?= num(count($ids)) ?> equipos</h1>
<p class="text-body-secondary">Cada equipo recibirá su propio registro en la bitácora con el antes → después. Deja en "No cambiar" lo que no se modifica.</p>

<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="card card-body">
      <?= csrf_field() ?>
      <input type="hidden" name="paso" value="aplicar">
      <input type="hidden" name="ids_csv" value="<?= e(implode(',', $ids)) ?>">
      <input type="hidden" name="volver" value="<?= e($volver) ?>">
      <div class="row g-2">
        <div class="col-12"><label class="form-label">Nueva etapa</label><?= sel_masivo('etapa_id', 'etapas', false) ?></div>
        <div class="col-12"><label class="form-label">Nuevo concepto / motivo</label><?= sel_masivo('concepto_id', 'conceptos', false) ?></div>
        <div class="col-12"><label class="form-label">Nueva ubicación</label><?= sel_masivo('ubicacion_id', 'ubicaciones', false) ?></div>
        <div class="col-6"><label class="form-label">Paquetería</label><?= sel_masivo('paqueteria_id', 'paqueterias') ?></div>
        <div class="col-6"><label class="form-label">Guía(s)</label><input class="form-control form-control-sm mono" name="guia" placeholder="Vacío = no cambiar"></div>
        <div class="col-6"><label class="form-label">Fecha de recepción</label><input class="form-control form-control-sm" type="date" name="fecha_recepcion"></div>
        <div class="col-6"><label class="form-label">Sucursal</label><input class="form-control form-control-sm" name="sucursal" placeholder="Vacío = no cambiar"></div>
        <div class="col-6"><label class="form-label">Seguimiento con</label><?= sel_masivo('seguimiento_id', 'personas') ?></div>
        <div class="col-6"><label class="form-label">Coordinador</label><?= sel_masivo('coordinador_id', 'personas') ?></div>
        <div class="col-6"><label class="form-label">Tipo de equipo</label><?= sel_masivo('tipo_id', 'tipos_equipo', false) ?></div>
        <div class="col-6"><label class="form-label">Proyecto</label><?= sel_masivo('proyecto_id', 'proyectos') ?></div>
        <div class="col-12"><label class="form-label">Extraviado</label>
          <select class="form-select form-select-sm" name="extraviado"><option value="__">— No cambiar —</option><option value="1">SI (extraviado)</option><option value="0">NO (localizado)</option></select></div>
        <div class="col-12"><label class="form-label">Comentario del movimiento</label><textarea class="form-control form-control-sm" name="mov_comentario" rows="3" placeholder="Ej. Recibidos en bodega Gavilanes, guía DHL 3292538200"></textarea></div>
        <div class="col-12"><label class="form-label">Evidencias (se ligan a todos los equipos)</label>
          <input class="form-control form-control-sm" type="file" name="evidencias[]" multiple accept="<?= e('.' . implode(',.', cfg('upload_ext'))) ?>">
          <input class="form-control form-control-sm mt-1" name="ev_descripcion" placeholder="Descripción (opcional)"></div>
        <div class="col-12"><hr class="my-2"><label class="form-label">Vincular a acuerdo</label>
          <select class="form-select form-select-sm" name="acuerdo_id">
            <option value="">— Ninguno / nuevo —</option>
            <?php foreach ($acAbiertos as $a): ?><option value="<?= (int)$a['id'] ?>">#<?= (int)$a['id'] ?> · <?= e(mb_strimwidth($a['descripcion'], 0, 70, '…')) ?><?= $a['fecha_compromiso'] ? ' · ' . fecha($a['fecha_compromiso'], false) : '' ?></option><?php endforeach; ?>
          </select>
          <textarea class="form-control form-control-sm mt-1" name="nuevo_acuerdo" rows="2" placeholder="…o escribe un nuevo acuerdo"></textarea>
          <div class="row g-2 mt-0">
            <div class="col-6"><input class="form-control form-control-sm" type="date" name="nuevo_fecha" title="Fecha compromiso"></div>
            <div class="col-6"><select class="form-select form-select-sm" name="nuevo_responsable"><?= opciones('personas', null, 'Responsable') ?></select></div>
          </div>
        </div>
      </div>
      <button class="btn btn-primary mt-3" data-confirm="¿Aplicar el movimiento a <?= count($ids) ?> equipos?"><i class="bi bi-check2-all"></i> Aplicar a <?= num(count($ids)) ?> equipos</button>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header">Etapa actual de la selección</div>
      <div class="card-body small"><?php foreach ($resumen as $r): ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= etapa_badge($r['nombre'], $r['color']) ?></span><b><?= num($r['n']) ?></b></div><?php endforeach; ?></div></div>
    <div class="card"><div class="card-header">Equipos seleccionados <?= count($ids) > 200 ? '(primeros 200)' : '' ?></div>
      <div class="table-responsive tabla-scroll"><table class="table table-sm table-tp mb-0">
        <thead><tr><th>Serie</th><th>Inventario</th><th>Tipo</th><th>Etapa</th><th>Ubicación</th></tr></thead>
        <tbody><?php foreach ($muestra as $m): ?><tr><td class="mono"><?= e($m['serie']) ?></td><td class="mono small"><?= e($m['inventario']) ?></td><td class="small"><?= e($m['tipo']) ?></td><td><?= etapa_badge($m['etapa'], $m['etapa_color']) ?></td><td class="small"><?= e($m['ubicacion']) ?></td></tr><?php endforeach; ?></tbody>
      </table></div></div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
