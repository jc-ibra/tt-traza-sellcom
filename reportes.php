<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();
$actas = (int)q('SELECT COUNT(*) FROM actas')->fetchColumn();
$titulo = 'Reportes';
require __DIR__ . '/inc/header.php';
$tarjetas = [
    ['reporte_ejecutivo.php', 'bi-clipboard-data', 'Reporte ejecutivo', 'Resumen de 1–2 hojas para jefes o banco: equipos por etapa, qué entró y salió en el periodo, recibidos en almacén, destruidos, localizados, región y acuerdos.'],
    ['reporte_responsable.php', 'bi-person-lines-fill', 'Pendientes por responsable', 'Lo que tiene pendiente cada persona (seguimiento o coordinación): equipos por etapa, días detenidos y sus acuerdos. Se imprime una hoja por persona.'],
    ['reporte_movimientos.php', 'bi-arrow-left-right', 'Movimientos del periodo', 'Todo lo que cambió entre dos fechas: etapas, ubicaciones, guías, extravíos y localizaciones, con quién lo registró. Exportable a Excel.'],
    ['actas.php', 'bi-file-earmark-text', 'Actas de entrega / destrucción', 'Formatos imprimibles con la lista de equipos y espacio para firmas. Opcionalmente cambian la etapa y quedan en la bitácora. (' . $actas . ' generadas)'],
];
?>
<h1 class="h4 mb-3">Reportes</h1>
<div class="row g-3">
  <?php foreach ($tarjetas as [$url, $ico, $t, $d]): ?>
  <div class="col-md-6 col-xl-3">
    <a href="<?= $url ?>" class="card h-100 text-decoration-none text-body">
      <div class="card-body">
        <div class="fs-2 text-primary"><i class="bi <?= $ico ?>"></i></div>
        <h2 class="h6"><?= e($t) ?></h2>
        <p class="small text-body-secondary mb-0"><?= e($d) ?></p>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
