<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/respaldo.php';
require_rol('admin');

if (g('descargar')) {
    $f = basename((string)g('descargar'));
    $ruta = respaldo_dir() . '/' . $f;
    if (!preg_match('/^TrazaPulso_[\w-]+\.zip$/', $f) || !is_file($ruta)) { http_response_code(404); exit('No encontrado.'); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;
}

if (is_post()) {
    csrf_check();
    try {
        $r = respaldo_crear('manual');
        flash('success', "Respaldo creado: {$r['archivo']} (" . round($r['tamano'] / 1024) . " KB, {$r['evidencias']} evidencias)" . ($r['copia'] ? ". Copia en {$r['copia']}" : ''));
    } catch (Throwable $ex) {
        flash('danger', 'No se pudo respaldar: ' . $ex->getMessage());
    }
    redirect('respaldos.php');
}

$lista = respaldo_lista();
$log = @file(respaldo_dir() . '/respaldos.log', FILE_IGNORE_NEW_LINES) ?: [];
$errores = array_filter(array_slice($log, -20), fn($l) => str_contains($l, 'ERROR'));
$libre = @disk_free_space(respaldo_dir());

$titulo = 'Respaldos';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0">Respaldos</h1>
  <form method="post" class="m-0"><?= csrf_field() ?><button class="btn btn-primary"><i class="bi bi-shield-check"></i> Respaldar ahora</button></form>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card kpi h-100"><div class="card-body"><div class="etq">Último respaldo</div>
    <?php if ($lista): $h = (time() - $lista[0]['fecha']) / 3600; ?>
      <div class="valor <?= $h > 48 ? 'text-danger' : 'text-success' ?>" style="font-size:1.4rem"><?= date('d/m/Y H:i', $lista[0]['fecha']) ?></div>
      <div class="small text-body-secondary">hace <?= $h < 1 ? round($h * 60) . ' min' : round($h) . ' h' ?></div>
    <?php else: ?><div class="valor text-danger" style="font-size:1.4rem">Ninguno</div><?php endif; ?></div></div></div>
  <div class="col-md-4"><div class="card kpi h-100"><div class="card-body"><div class="etq">Respaldos guardados</div><div class="valor" style="font-size:1.4rem"><?= count($lista) ?></div>
    <div class="small text-body-secondary">Se conservan <?= (int)cfg('respaldo_dias') ?> días · <?= round(array_sum(array_column($lista, 'tamano')) / 1048576, 1) ?> MB</div></div></div></div>
  <div class="col-md-4"><div class="card kpi h-100"><div class="card-body"><div class="etq">Dónde se guardan</div><div class="small mono mt-1"><?= e(str_replace('/', '\\', respaldo_dir())) ?></div>
    <div class="small mt-1"><?= cfg('respaldo_copia') ? '<span class="text-success"><i class="bi bi-check-circle"></i> Copia en ' . e(cfg('respaldo_copia')) . '</span>' : '<span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> Sin copia en otra ubicación</span>' ?></div>
    <?php if ($libre !== false): ?><div class="small text-body-secondary">Espacio libre en disco: <?= round($libre / 1073741824, 1) ?> GB</div><?php endif; ?></div></div></div>
</div>

<?php if ($errores): ?><div class="alert alert-danger small"><b>Errores recientes:</b><br><?= implode('<br>', array_map('e', $errores)) ?></div><?php endif; ?>

<div class="card mb-3"><div class="table-responsive">
  <table class="table table-sm table-hover table-tp mb-0">
    <thead><tr><th>Archivo</th><th>Fecha</th><th>Tipo</th><th class="text-end">Tamaño</th><th></th></tr></thead>
    <tbody>
    <?php if (!$lista): ?><tr><td colspan="5" class="text-center text-body-secondary py-3">Aún no hay respaldos.</td></tr><?php endif; ?>
    <?php foreach ($lista as $r): preg_match('/^TrazaPulso_\d{8}_\d{6}_(.+)\.zip$/', $r['archivo'], $m); ?>
      <tr><td class="mono small"><?= e($r['archivo']) ?></td><td><?= date('d/m/Y H:i', $r['fecha']) ?></td>
        <td><span class="badge text-bg-light border"><?= e(['auto' => 'Automático', 'manual' => 'Manual', 'programado' => 'Programado', 'antes_de_restaurar' => 'Antes de restaurar'][$m[1] ?? ''] ?? ($m[1] ?? '')) ?></span></td>
        <td class="text-end small"><?= round($r['tamano'] / 1024) ?> KB</td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="?descargar=<?= urlencode($r['archivo']) ?>"><i class="bi bi-download"></i> Descargar</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<div class="card card-body small">
  <h2 class="h6">¿Qué incluye cada respaldo?</h2>
  <ul class="mb-2"><li><b>Toda la base de datos:</b> equipos, bitácora completa, acuerdos, actas, catálogos y usuarios.</li><li><b>Evidencias:</b> fotos, PDF y guías adjuntas.</li><li><b>El programa:</b> para reinstalarlo idéntico en otra computadora.</li></ul>
  <p class="mb-1"><b>Automático:</b> el sistema se respalda solo una vez al día, la primera vez que alguien lo usa.</p>
  <p class="mb-0"><b>Para restaurar</b> (lo hace el administrador desde la consola; antes guarda un respaldo del estado actual):<br><code>C:\xampp\php\php.exe C:\xampp\htdocs\TrazaPulso_Sellcom\restaurar.php "ruta\del\respaldo.zip"</code></p>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
