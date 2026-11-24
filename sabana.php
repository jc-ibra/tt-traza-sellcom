<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sabana.php';
require_rol('admin');
@set_time_limit(300);

$dirTmp = cfg('upload_dir') . '/tmp';
$dirSab = cfg('upload_dir') . '/sabanas';
$S = $_SESSION['sabana'] ?? null;
if ($S && !is_file($dirTmp . '/' . $S['archivo'])) { unset($_SESSION['sabana']); $S = null; }

if (is_post()) {
    csrf_check();
    $accion = p('accion');
    try {
        if ($accion === 'subir') {
            $f = $_FILES['archivo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Selecciona el archivo de la sábana (.xlsx).');
            if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('La sábana debe ser un archivo .xlsx.');
            if (!is_dir($dirTmp)) mkdir($dirTmp, 0775, true);
            // solo puede haber UNA sábana pendiente: la nueva reemplaza a cualquier anterior sin aplicar
            foreach (glob($dirTmp . '/*.xlsx.json') ?: [] as $viejo) { @unlink($viejo); @unlink(substr($viejo, 0, -5)); }
            if ($S) @unlink($dirTmp . '/' . $S['archivo']);
            $nombre = bin2hex(random_bytes(12)) . '.xlsx';
            if (!move_uploaded_file($f['tmp_name'], $dirTmp . '/' . $nombre)) throw new RuntimeException('No se pudo guardar el archivo.');
            $hojas = (new Xlsx($dirTmp . '/' . $nombre))->hojas();
            $hoja = in_array('BASE_SELLCOM', $hojas, true) ? 'BASE_SELLCOM' : $hojas[0];
            $_SESSION['sabana'] = ['archivo' => $nombre, 'nombre' => basename($f['name']), 'hojas' => $hojas, 'hoja' => $hoja, 'prop' => null];
            sabana_pendiente_guardar($dirTmp, $_SESSION['sabana']);
            $accion = 'comparar';
            $S = $_SESSION['sabana'];
        }
        if ($accion === 'retomar') {
            $arch = basename((string)p('archivo'));
            if (!preg_match('/^[a-f0-9]{24}\.xlsx$/', $arch) || !is_file($dirTmp . '/' . $arch)) throw new RuntimeException('Ese archivo pendiente ya no existe.');
            $meta = sabana_pendiente_leer($dirTmp, $arch);
            $hojas = (new Xlsx($dirTmp . '/' . $arch))->hojas();
            $S = $_SESSION['sabana'] = ['archivo' => $arch, 'nombre' => $meta['nombre'], 'hojas' => $hojas,
                'hoja' => in_array($meta['hoja'] ?? '', $hojas, true) ? $meta['hoja'] : (in_array('BASE_SELLCOM', $hojas, true) ? 'BASE_SELLCOM' : $hojas[0]), 'prop' => null];
            $accion = 'comparar';
        }
        if ($accion === 'descartar') {
            $arch = basename((string)p('archivo'));
            if (preg_match('/^[a-f0-9]{24}\.xlsx$/', $arch)) { @unlink($dirTmp . '/' . $arch); @unlink($dirTmp . '/' . $arch . '.json'); }
            flash('info', 'Sábana pendiente descartada.');
            redirect('sabana.php');
        }
        if ($accion === 'comparar' && $S) {
            if (p('hoja') && in_array(p('hoja'), $S['hojas'], true)) $S['hoja'] = p('hoja');
            $S['prop'] = sabana_analizar($dirTmp . '/' . $S['archivo'], $S['hoja'], uid());
            $S['comparado'] = date('Y-m-d H:i:s');
            $_SESSION['sabana'] = $S;
            redirect('sabana.php');
        }
        if ($accion === 'cancelar') {
            if ($S) { @unlink($dirTmp . '/' . $S['archivo']); @unlink($dirTmp . '/' . $S['archivo'] . '.json'); }
            unset($_SESSION['sabana']);
            redirect('sabana.php');
        }
        if ($accion === 'aplicar' && $S && $S['prop']) {
            $rech = array_filter(explode(',', (string)p('rechazados')));
            $com = p('comentario') ?: ('Actualización con sábana ' . $S['nombre']);
            $res = sabana_aplicar($dirTmp . '/' . $S['archivo'], $S['hoja'], $S['prop'], $rech, uid(), $com);
            if (!is_dir($dirSab)) mkdir($dirSab, 0775, true);
            $guardado = date('Ymd_His') . '_' . $S['archivo'];
            @rename($dirTmp . '/' . $S['archivo'], $dirSab . '/' . $guardado);
            @unlink($dirTmp . '/' . $S['archivo'] . '.json');
            q('INSERT INTO sabanas (nombre_original, archivo, hoja, usuario_id, filas, nuevos, actualizados, cambios_aplicados, cambios_rechazados, faltantes, comentario, folios_glpi) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$S['nombre'], $guardado, $S['hoja'], uid(), $res['filas'], $res['nuevos'], $res['actualizados'], $res['aplicados'], count($rech), $S['prop']['resumen']['faltantes'], mb_substr($com, 0, 255),
                 json_encode(sabana_folios_glpi($dirSab . '/' . $guardado, $S['hoja']))]);
            unset($_SESSION['sabana']);
            flash('success', sprintf('Sábana aplicada: %d equipos nuevos, %d actualizados, %d cambios aplicados, %d rechazados. Todo quedó en la bitácora.',
                $res['nuevos'], $res['actualizados'], $res['aplicados'], count($rech)));
            foreach (array_slice($res['avisos_aplicar'], 0, 5) as $a) flash('warning', $a);
            redirect('sabana.php');
        }
    } catch (Throwable $ex) {
        flash('danger', 'Error: ' . $ex->getMessage());
        redirect('sabana.php');
    }
}

if (g('archivo')) { // descargar una sábana del historial
    $row = q('SELECT * FROM sabanas WHERE id = ?', [(int)g('archivo')])->fetch();
    $ruta = $row && $row['archivo'] ? $dirSab . '/' . basename($row['archivo']) : null;
    if (!$ruta || !is_file($ruta)) { http_response_code(404); exit('Archivo no disponible.'); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $row['nombre_original']) . '"');
    readfile($ruta);
    exit;
}

$hist = q('SELECT s.*, u.nombre AS usuario FROM sabanas s LEFT JOIN usuarios u ON u.id = s.usuario_id ORDER BY s.id DESC LIMIT 30')->fetchAll();
$P = $S['prop'] ?? null;

$titulo = 'Actualizar sábana';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><h1 class="h4 mb-0"><i class="bi bi-arrow-repeat"></i> Actualizar con la sábana</h1>
    <div class="small text-body-secondary">Sube la sábana cuando quieras: el sistema te muestra qué cambió y tú decides qué se aplica.</div></div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-success" href="sabana_descargar.php"><i class="bi bi-download"></i> Descargar sábana (formato original)</a>
    <a class="btn btn-sm btn-outline-success" href="sabana_descargar.php?modo=completo"><i class="bi bi-download"></i> Con columnas del sistema</a>
  </div>
</div>

<?php if (!$S && ($pend = sabana_pendientes($dirTmp))): ?>
<div class="card rs-card mb-3 border-warning">
  <div class="card-header bg-warning-subtle"><span><i class="bi bi-hourglass-split"></i> Tienes <?= count($pend) === 1 ? 'una sábana pendiente' : count($pend) . ' sábanas pendientes' ?> de aplicar <span class="fw-normal small">(si subes otra, reemplaza a la pendiente)</span></span></div>
  <table class="tbl"><colgroup><col><col style="width:150px"><col style="width:140px"><col style="width:330px"></colgroup>
    <thead><tr><th>Archivo</th><th>Subida</th><th>Por</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pend as $pd): ?>
      <tr><td class="nom"><i class="bi bi-file-earmark-excel text-success"></i> <?= e($pd['nombre']) ?></td><td><?= fecha($pd['fecha']) ?></td><td class="small"><?= e($pd['usuario']) ?></td>
        <td class="text-end text-nowrap">
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="retomar"><input type="hidden" name="archivo" value="<?= e($pd['archivo']) ?>"><button class="btn btn-sm btn-tp"><i class="bi bi-arrow-counterclockwise"></i> Retomar comparación</button></form>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="descartar"><input type="hidden" name="archivo" value="<?= e($pd['archivo']) ?>"><button class="btn btn-sm btn-link text-danger" data-confirm="¿Descartar esta sábana pendiente?">Descartar</button></form>
        </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php if (!$S): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="card card-body">
      <?= csrf_field() ?><input type="hidden" name="accion" value="subir">
      <label class="form-label">Archivo de la sábana (.xlsx)</label>
      <input type="file" name="archivo" accept=".xlsx" class="form-control mb-3" required>
      <button class="btn btn-primary"><i class="bi bi-search"></i> Subir y comparar</button>
      <div class="small text-body-secondary mt-2">No se guarda nada hasta que revises y confirmes.</div>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card card-body small">
      <h2 class="h6">Se respetan tus campos</h2>
      <ul class="mb-0 ps-3">
        <li>Solo se comparan las columnas que trae la sábana (Folio, Inventario, Serie, Equipo, Proyecto, Conceptos, Extraviados, Ubicación, Seguimiento, Comentario, Acuerdo, Coordinador, Año…).</li>
        <li>Una celda <b>vacía no borra</b> lo que ya tiene el sistema.</li>
        <li>Los campos propios del sistema (etapa, guía, paquetería, fecha de recepción, sucursal) <b>no se tocan</b> si la sábana no los trae.</li>
        <li>Si una serie viene repetida, se toma la última fila.</li>
        <li>Todo lo que aceptes queda en la bitácora de cada equipo como “Sábana”.</li>
      </ul>
    </div>
  </div>
</div>
<?php elseif ($P): $R = $P['resumen']; ?>
<div class="card card-body mb-3">
  <form method="post" class="d-flex flex-wrap align-items-center gap-2 m-0">
    <?= csrf_field() ?>
    <span><i class="bi bi-file-earmark-excel text-success"></i> <b><?= e($S['nombre']) ?></b></span>
    <span class="text-body-secondary small">comparado <?= fecha($S['comparado']) ?> · hoja</span>
    <select name="hoja" class="form-select form-select-sm w-auto"><?php foreach ($S['hojas'] as $h): ?><option <?= $h === $S['hoja'] ? 'selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?></select>
    <button name="accion" value="comparar" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i> Volver a comparar</button>
    <button name="accion" value="cancelar" class="btn btn-sm btn-link text-danger ms-auto">Cancelar</button>
  </form>
  <div class="small text-body-secondary mt-1">Columnas reconocidas: <?= e(implode(', ', $P['columnas'])) ?></div>
</div>

<div class="row g-2 mb-3 text-center">
  <?php foreach ([['Filas', $R['filas'], ''], ['Nuevos', $R['nuevos'], 'text-success'], ['Equipos con cambios', $R['equipos_con_cambios'], 'text-primary'], ['Campos cambiados', $R['cambios'], 'text-primary'],
      ['Acuerdos a vincular', $R['acuerdos'], 'text-warning-emphasis'], ['Sin cambios', $R['sin_cambios'], 'text-body-secondary'], ['No vienen en la sábana', $R['faltantes'], 'text-danger']] as [$l, $n, $c]): ?>
    <div class="col"><div class="card"><div class="card-body p-2"><div class="fs-4 fw-bold <?= $c ?>"><?= num($n) ?></div><div class="small text-body-secondary"><?= e($l) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<?php if (!$R['nuevos'] && !$R['cambios'] && !$R['acuerdos']): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle"></i> La sábana coincide con el sistema: no hay nada que actualizar.</div>
<?php else: ?>
<form method="post" id="form-sabana">
  <?= csrf_field() ?><input type="hidden" name="accion" value="aplicar"><input type="hidden" name="rechazados" id="rechazados">

  <?php if ($P['cambios']):
      $porCampo = [];
      foreach ($P['cambios'] as $c) $porCampo[$c['campo']] = ($porCampo[$c['campo']] ?? 0) + 1;
      arsort($porCampo); ?>
  <div class="card mb-3">
    <div class="card-header">Cambios por campo <span class="small text-body-secondary fw-normal">— desmarca un campo completo si no quieres que la sábana lo actualice</span></div>
    <div class="card-body py-2 d-flex flex-wrap gap-3">
      <?php foreach ($porCampo as $campo => $n): ?>
        <label class="form-check-label"><input type="checkbox" class="form-check-input sab-master" data-grupo="campo-<?= e($campo) ?>" checked> <?= e(CAMPOS_EQUIPO[$campo][0] ?? $campo) ?> <span class="badge text-bg-light border"><?= $n ?></span></label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between"><span>Cambios en equipos existentes (<?= num(count($P['cambios'])) ?>)</span>
      <input type="search" class="form-control form-control-sm w-auto" placeholder="Filtrar por serie o valor…" id="filtro-cambios"></div>
    <div class="table-responsive tabla-scroll" style="max-height:55vh">
      <table class="table table-sm table-hover table-tp mb-0" id="tabla-cambios">
        <thead><tr><th style="width:1%"><input type="checkbox" class="form-check-input sab-master" data-grupo="cambio" checked></th><th>Fila</th><th>Equipo</th><th>Campo</th><th>En el sistema</th><th></th><th>En la sábana</th></tr></thead>
        <tbody>
        <?php foreach ($P['cambios'] as $k => $c): ?>
          <tr><td><input type="checkbox" class="form-check-input sab-chk" value="<?= e($k) ?>" data-grupos="cambio campo-<?= e($c['campo']) ?>" checked></td>
            <td class="small text-body-secondary"><?= (int)$c['fila'] ?></td>
            <td class="mono small text-nowrap"><a href="equipo.php?id=<?= (int)$c['id'] ?>" target="_blank"><?= e($c['serie'] ?: codigo_equipo((int)$c['id'])) ?></a> <span class="text-body-secondary"><?= e($c['tipo']) ?></span></td>
            <td class="small fw-semibold"><?= e(CAMPOS_EQUIPO[$c['campo']][0] ?? $c['campo']) ?></td>
            <td class="small text-danger-emphasis"><?= $c['antes'] !== null ? e($c['antes']) : '<i class="text-body-tertiary">(vacío)</i>' ?></td>
            <td><i class="bi bi-arrow-right"></i></td>
            <td class="small text-success-emphasis fw-semibold"><?= $c['despues'] !== null ? e($c['despues']) : '<i class="text-body-tertiary">(vacío)</i>' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($P['nuevos']): ?>
  <div class="card mb-3">
    <div class="card-header">Equipos nuevos (<?= num(count($P['nuevos'])) ?>)</div>
    <div class="table-responsive tabla-scroll" style="max-height:40vh">
      <table class="table table-sm table-tp mb-0">
        <thead><tr><th style="width:1%"><input type="checkbox" class="form-check-input sab-master" data-grupo="nuevo" checked></th><th>Fila</th><th>Serie</th><th>Inventario</th><th>Folio</th><th>Tipo</th><th>Concepto</th><th>Etapa asignada</th><th>Ubicación</th></tr></thead>
        <tbody>
        <?php foreach ($P['nuevos'] as $fila => $n): ?>
          <tr><td><input type="checkbox" class="form-check-input sab-chk" value="n:<?= (int)$fila ?>" data-grupos="nuevo" checked></td><td class="small text-body-secondary"><?= (int)$fila ?></td>
            <td class="mono"><?= e($n['serie'] ?? '—') ?></td><td class="mono small"><?= e($n['inventario'] ?? '—') ?></td><td class="mono small"><?= e($n['folio']) ?></td>
            <td class="small"><?= e($n['tipo']) ?></td><td class="small"><?= e($n['concepto']) ?></td><td class="small"><?= e($n['etapa']) ?></td><td class="small"><?= e($n['ubicacion']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($P['acuerdos']): ?>
  <div class="card mb-3">
    <div class="card-header">Acuerdos a vincular (<?= num(count($P['acuerdos'])) ?>)</div>
    <div class="table-responsive tabla-scroll" style="max-height:30vh">
      <table class="table table-sm table-tp mb-0">
        <thead><tr><th style="width:1%"><input type="checkbox" class="form-check-input sab-master" data-grupo="acuerdo" checked></th><th>Equipo</th><th>Acuerdo</th></tr></thead>
        <tbody><?php foreach ($P['acuerdos'] as $fila => $a): ?><tr><td><input type="checkbox" class="form-check-input sab-chk" value="a:<?= (int)$fila ?>" data-grupos="acuerdo" checked></td><td class="mono small"><?= e($a['serie']) ?></td><td class="small"><?= e($a['texto']) ?></td></tr><?php endforeach; ?></tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card card-body mb-3 barra-masiva shadow">
    <div class="row g-2 align-items-end">
      <div class="col-md-7"><label class="form-label">Comentario para la bitácora</label><input name="comentario" class="form-control form-control-sm" value="<?= e('Actualización con sábana ' . $S['nombre']) ?>"></div>
      <div class="col-md-5 d-flex gap-2 align-items-center">
        <span class="small"><b id="sab-contador">0</b> seleccionados</span>
        <button class="btn btn-success ms-auto" data-confirm="¿Aplicar los cambios seleccionados? Quedarán registrados en la bitácora."><i class="bi bi-check2-circle"></i> Aplicar seleccionados</button>
      </div>
    </div>
  </div>
</form>
<?php endif; ?>

<?php if ($P['faltantes']): ?>
<div class="card mb-3">
  <div class="card-header text-danger">Equipos que están en el sistema pero NO vienen en la sábana (<?= num(count($P['faltantes'])) ?>) <span class="small text-body-secondary fw-normal">— solo para revisión, no se modifican</span></div>
  <div class="table-responsive tabla-scroll" style="max-height:35vh">
    <table class="table table-sm table-tp mb-0"><thead><tr><th>ID</th><th>Serie</th><th>Inventario</th><th>Tipo</th><th>Etapa</th><th>Ubicación</th></tr></thead><tbody>
    <?php foreach (array_slice($P['faltantes'], 0, 500) as $e): ?><tr><td class="small"><a href="equipo.php?id=<?= (int)$e['id'] ?>" target="_blank"><?= codigo_equipo((int)$e['id']) ?></a></td><td class="mono"><?= e($e['serie']) ?></td><td class="mono small"><?= e($e['inventario']) ?></td><td class="small"><?= e($e['tipo']) ?></td><td><?= etapa_badge($e['etapa'], $e['etapa_color']) ?></td><td class="small"><?= e($e['ubicacion']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php endif; ?>

<?php if ($P['avisos']): ?>
<div class="card card-body mb-3 small"><b>Avisos de la sábana (<?= count($P['avisos']) ?>):</b><ul class="mb-0 tabla-scroll" style="max-height:200px"><?php foreach ($P['avisos'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php endif; ?>

<div class="card mt-3">
  <div class="card-header">Historial de actualizaciones con sábana</div>
  <div class="table-responsive">
    <table class="table table-sm table-tp mb-0">
      <thead><tr><th>Fecha</th><th>Archivo</th><th>Usuario</th><th class="text-end">Filas</th><th class="text-end">Nuevos</th><th class="text-end">Actualizados</th><th class="text-end">Cambios aplicados</th><th class="text-end">Rechazados</th><th class="text-end">No venían</th></tr></thead>
      <tbody>
      <?php if (!$hist): ?><tr><td colspan="9" class="text-center text-body-secondary py-3">Aún no se ha actualizado con una sábana.</td></tr><?php endif; ?>
      <?php foreach ($hist as $h): ?>
        <tr><td class="text-nowrap"><?= fecha($h['fecha']) ?></td><td class="small"><a href="?archivo=<?= (int)$h['id'] ?>"><i class="bi bi-file-earmark-excel"></i> <?= e($h['nombre_original']) ?></a></td><td class="small"><?= e($h['usuario']) ?></td>
          <td class="text-end"><?= num($h['filas']) ?></td><td class="text-end"><?= num($h['nuevos']) ?></td><td class="text-end"><?= num($h['actualizados']) ?></td><td class="text-end"><?= num($h['cambios_aplicados']) ?></td><td class="text-end"><?= num($h['cambios_rechazados']) ?></td><td class="text-end"><?= num($h['faltantes']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(() => {
  const form = document.getElementById('form-sabana');
  if (!form) return;
  const chks = () => Array.from(document.querySelectorAll('.sab-chk'));
  const cont = document.getElementById('sab-contador');
  const contar = () => { cont.textContent = chks().filter(c => c.checked).length; };
  document.querySelectorAll('.sab-master').forEach(m => m.addEventListener('change', () => {
    chks().filter(c => (' ' + c.dataset.grupos + ' ').includes(' ' + m.dataset.grupo + ' ')).forEach(c => c.checked = m.checked);
    contar();
  }));
  chks().forEach(c => c.addEventListener('change', contar));
  form.addEventListener('submit', () => {
    document.getElementById('rechazados').value = chks().filter(c => !c.checked).map(c => c.value).join(',');
  });
  const f = document.getElementById('filtro-cambios');
  if (f) f.addEventListener('input', () => {
    const t = f.value.toLowerCase();
    document.querySelectorAll('#tabla-cambios tbody tr').forEach(tr => tr.style.display = tr.textContent.toLowerCase().includes(t) ? '' : 'none');
  });
  contar();
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
