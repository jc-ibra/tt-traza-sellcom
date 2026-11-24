<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/importador.php';
require_rol('admin');
@set_time_limit(300);

$tmpDir = cfg('upload_dir') . '/tmp';
$res = null;
$hojas = [];
$archivo = $_SESSION['imp_archivo'] ?? null;
$ruta = $archivo ? $tmpDir . '/' . $archivo : null;
if ($ruta && !is_file($ruta)) {
    unset($_SESSION['imp_archivo'], $_SESSION['imp_nombre']);
    $archivo = $ruta = null;
}

if (is_post()) {
    csrf_check();
    $accion = p('accion');
    try {
        if ($accion === 'subir') {
            $f = $_FILES['archivo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Selecciona un archivo .xlsx.');
            if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('Solo se aceptan archivos .xlsx (Excel 2007 o superior).');
            if (!is_dir($tmpDir)) mkdir($tmpDir, 0775, true);
            if ($ruta) @unlink($ruta);
            $archivo = bin2hex(random_bytes(12)) . '.xlsx';
            if (!move_uploaded_file($f['tmp_name'], $tmpDir . '/' . $archivo)) throw new RuntimeException('No se pudo guardar el archivo.');
            $_SESSION['imp_archivo'] = $archivo;
            $_SESSION['imp_nombre'] = basename($f['name']);
            redirect('importar.php');
        }
        if ($accion === 'cancelar') {
            if ($ruta) @unlink($ruta);
            unset($_SESSION['imp_archivo'], $_SESSION['imp_nombre']);
            redirect('importar.php');
        }
        if (in_array($accion, ['simular', 'importar'], true) && $ruta) {
            $hoja = (string)p('hoja');
            $vb = (bool)p('vacios_borran');
            $res = importar_excel($ruta, $hoja, $accion === 'simular', uid(), $vb);
            $res['vacios_borran'] = $vb;
            $res['simulado'] = $accion === 'simular';
            $res['hoja'] = $hoja;
            if (!$res['simulado']) {
                @unlink($ruta);
                unset($_SESSION['imp_archivo'], $_SESSION['imp_nombre']);
                $archivo = $ruta = null;
            }
        }
    } catch (Throwable $ex) {
        flash('danger', 'Error: ' . $ex->getMessage());
        redirect('importar.php');
    }
}

if ($ruta) {
    try {
        $hojas = (new Xlsx($ruta))->hojas();
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
        @unlink($ruta);
        unset($_SESSION['imp_archivo']);
        redirect('importar.php');
    }
}
$hojaSel = $res['hoja'] ?? (in_array('BASE_SELLCOM', $hojas, true) ? 'BASE_SELLCOM' : ($hojas[0] ?? ''));
$total = (int)q('SELECT COUNT(*) FROM equipos')->fetchColumn();

$titulo = 'Importar Excel';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0">Alimentar el sistema desde Excel</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-success" href="plantilla.php"><i class="bi bi-file-earmark-plus"></i> Descargar plantilla vacía</a>
    <a class="btn btn-sm btn-outline-success" href="exportar.php"><i class="bi bi-file-earmark-excel"></i> Descargar equipos actuales para editar</a>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <?php if (!$ruta): ?>
    <form method="post" enctype="multipart/form-data" class="card card-body">
      <?= csrf_field() ?><input type="hidden" name="accion" value="subir">
      <label class="form-label">1. Selecciona el archivo .xlsx</label>
      <input type="file" name="archivo" accept=".xlsx" class="form-control mb-3" required>
      <button class="btn btn-primary"><i class="bi bi-upload"></i> Subir y revisar</button>
    </form>
    <?php else: ?>
    <div class="card card-body">
      <div class="mb-2"><i class="bi bi-file-earmark-excel text-success"></i> <b><?= e($_SESSION['imp_nombre'] ?? $archivo) ?></b></div>
      <form method="post">
        <?= csrf_field() ?>
        <label class="form-label">2. Hoja a importar</label>
        <select name="hoja" class="form-select form-select-sm mb-2"><?php foreach ($hojas as $h): ?><option <?= $h === $hojaSel ? 'selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?></select>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="vacios_borran" value="1" id="vb" <?= !empty($res['vacios_borran']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="vb">Las celdas vacías <b>borran</b> el dato existente <span class="text-body-secondary">(normalmente déjalo sin marcar: lo vacío se ignora)</span></label></div>
        <div class="d-flex flex-wrap gap-2">
          <button name="accion" value="simular" class="btn btn-outline-primary"><i class="bi bi-eye"></i> 3. Simular (no guarda)</button>
          <?php if ($res && $res['simulado']): ?>
          <button name="accion" value="importar" class="btn btn-success" data-confirm="¿Aplicar la importación? Todos los cambios quedarán registrados en la bitácora."><i class="bi bi-check2-circle"></i> 4. Importar definitivamente</button>
          <?php endif; ?>
          <button name="accion" value="cancelar" class="btn btn-link text-danger ms-auto">Cancelar</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="card card-body mt-3 small">
      <h2 class="h6">Formas de usarlo</h2>
      <ol class="ps-3 mb-3">
        <li><b>Actualizar equipos existentes:</b> descarga <i>equipos actuales</i>, cambia lo que necesites (etapa, guía, ubicación…) y agrega un texto en <i>COMENTARIO DEL MOVIMIENTO</i> si quieres. Súbelo: cada cambio queda en la bitácora.</li>
        <li><b>Registrar equipos nuevos o movimientos rápidos:</b> descarga la <i>plantilla vacía</i>, llena solo las columnas que uses (puedes borrar las demás) y súbela.</li>
        <li><b>Tu base original</b> (BASE_SELLCOM) también se puede volver a subir.</li>
      </ol>
      <p class="mb-1"><b>Etapas válidas:</b> <?= e(implode(' · ', catalogo('etapas', true))) ?></p>
      <h2 class="h6 mt-3">¿Cómo funciona?</h2>
      <ul class="mb-2 ps-3">
        <li>Reconoce las columnas de tu base (<code>c_Serie</code>, <code>c_Inventario</code>, <code>c_Folio</code>, <code>c_Equipo</code>, <code>CONCEPTOS</code>, <code>UBICACIÓN</code>, <code>SEGUIMIENTO CON</code>, <code>CORDINADOR</code>, <code>ACUERDO</code>…).</li>
        <li>Busca cada equipo por <b>ID TrazaPulso</b>, luego por <b>serie</b> y luego por <b>inventario</b>. Si existe lo actualiza (registrando el antes → después en su bitácora); si no, lo da de alta.</li>
        <li>Unifica nombres (mayúsculas, acentos, "SIN UBICACION" → "SIN UBICAR", "TABLETA HIBRIDA" → "TABLETAS HIBRIDAS"…).</li>
        <li>Cada texto distinto de <code>ACUERDO</code> se convierte en un acuerdo con fecha compromiso (detectada del texto) y se liga a sus equipos.</li>
      </ul>
      <div class="alert alert-warning py-2 mb-0"><i class="bi bi-lightbulb"></i> Para actualizaciones posteriores usa el Excel <b>exportado desde la app</b> (trae la columna <i>ID TrazaPulso</i>): así los equipos sin serie ni inventario válidos no se duplican.</div>
    </div>
  </div>

  <div class="col-lg-7">
    <?php if ($res): ?>
    <div class="card">
      <div class="card-header <?= $res['simulado'] ? 'bg-warning-subtle' : 'bg-success-subtle' ?>">
        <?= $res['simulado'] ? '<i class="bi bi-eye"></i> Resultado de la SIMULACIÓN (aún no se guardó nada)' : '<i class="bi bi-check2-circle"></i> Importación aplicada' ?>
      </div>
      <div class="card-body">
        <div class="row g-2 text-center mb-3">
          <?php foreach (['filas' => 'Filas leídas', 'nuevos' => 'Nuevos', 'actualizados' => 'Actualizados', 'sin_cambios' => 'Sin cambios', 'omitidos' => 'Omitidos', 'comentarios' => 'Comentarios'] as $k => $l): ?>
            <div class="col-4 col-md-2"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= num($res[$k]) ?></div><div class="small text-body-secondary"><?= $l ?></div></div></div>
          <?php endforeach; ?>
        </div>
        <p class="small mb-1"><b>Columnas reconocidas:</b> <?= e(implode(', ', array_unique($res['columnas']))) ?></p>
        <?php if ($res['catalogos_nuevos']): ?><p class="small mb-1"><b>Elementos nuevos en catálogos:</b> <?php foreach ($res['catalogos_nuevos'] as $c => $n) echo e($c) . ': ' . (int)$n . '; '; ?></p><?php endif; ?>
        <?php if ($res['avisos']): ?>
          <h3 class="h6 mt-3">Avisos (<?= count($res['avisos']) ?>) <span class="fw-normal small text-body-secondary">— datos a revisar en tu base</span></h3>
          <ul class="small tabla-scroll" style="max-height:340px"><?php foreach ($res['avisos'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!$res['simulado']): ?><a href="index.php" class="btn btn-primary btn-sm">Ir al tablero</a> <a href="equipos.php" class="btn btn-outline-primary btn-sm">Ver equipos</a><?php endif; ?>
      </div>
    </div>
    <?php else: ?>
      <div class="card card-body text-body-secondary">Actualmente hay <b><?= num($total) ?></b> equipos registrados. Sube un archivo y ejecuta la simulación para ver qué cambiaría antes de guardar.</div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
