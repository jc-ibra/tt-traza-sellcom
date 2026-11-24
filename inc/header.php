<?php
$titulo = $titulo ?? '';
$navPagina = basename($_SERVER['SCRIPT_NAME'], '.php');
$u = user();
$vencidos = $u ? acuerdos_vencidos_count() : 0;
$nExtrav = $u ? (int)q('SELECT COUNT(*) FROM equipos WHERE extraviado = 1')->fetchColumn() : 0;
function nav_activo(array $paginas, string $actual): string
{
    return in_array($actual, $paginas, true) ? ' active' : '';
}
function nav_item(string $url, string $icono, string $texto, array $paginas, string $actual, string $badge = ''): string
{
    return '<a class="tp-nav-item' . nav_activo($paginas, $actual) . '" href="' . $url . '"><i class="bi ' . $icono . '"></i><span>' . e($texto) . '</span>' . $badge . '</a>';
}
$iniciales = $u ? mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($u['nombre'])), 0, 2)))) : '';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($titulo ? $titulo . ' · ' : '') . cfg('app_name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/app.css?v=27">
</head>
<body class="<?= $u ? 'tp-con-menu' : '' ?>">
<?php if ($u): ?>
<!-- barra superior solo en celular / pantallas chicas -->
<div class="tp-topbar d-lg-none no-print">
  <button class="btn btn-sm text-white" type="button" data-bs-toggle="offcanvas" data-bs-target="#tp-side"><i class="bi bi-list fs-4"></i></button>
  <span class="fw-bold">TRAZAPULSO</span>
  <a href="equipos.php" class="btn btn-sm text-white"><i class="bi bi-search"></i></a>
</div>

<aside class="tp-side offcanvas-lg offcanvas-start no-print" tabindex="-1" id="tp-side">
  <div class="tp-side-in">
    <a href="index.php" class="tp-brand">
      <div class="t">TRAZAPULSO</div>
      <div class="s">Trazabilidad de equipos<br>Sellcom</div>
    </a>
    <?php if (puede('admin')): ?>
      <a href="sabana.php" class="tp-side-btn<?= nav_activo(['sabana'], $navPagina) ?>"><i class="bi bi-arrow-repeat"></i> Actualizar sábana</a>
    <?php endif; ?>
    <?php if (puede('editor')): require_once __DIR__ . '/glpi_api.php'; ?>
      <?php if (glpi_configurado()): $ultGlpi = q('SELECT fecha FROM glpi_sync WHERE error IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn(); ?>
        <form method="post" action="glpi.php" class="m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="sincronizar"><input type="hidden" name="volver" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
          <button class="tp-side-btn tp-btn-glpi w-100" title="Consulta en GLPI el estado de cada FOLIO GLPI<?= $ultGlpi ? ' · última vez ' . e(fecha($ultGlpi)) : '' ?>"><i class="bi bi-cloud-download"></i> Actualizar GLPI</button></form>
      <?php elseif (puede('admin')): ?>
        <a href="glpi.php" class="tp-side-btn tp-btn-glpi" title="Falta capturar la dirección y los tokens de GLPI"><i class="bi bi-cloud-slash"></i> Actualizar GLPI <span class="small opacity-75">(configurar)</span></a>
      <?php endif; ?>
    <?php endif; ?>
    <form action="equipos.php" method="get" role="search" class="tp-side-buscar">
      <i class="bi bi-search"></i><input type="search" name="q" placeholder="Buscar serie, folio…" title="Busca por serie, inventario, folio o guía" value="<?= e($navPagina === 'equipos' ? g('q', '') : '') ?>">
    </form>

    <div class="tp-side-sec">Menú</div>
    <?= nav_item('index.php', 'bi-bar-chart-line', 'Resumen integrado', ['index'], $navPagina) ?>
    <?= nav_item('equipos.php', 'bi-pc-display', 'Equipos', ['equipos', 'equipo', 'masivo'], $navPagina) ?>
    <?= nav_item('reporte_extraviados.php', 'bi-exclamation-triangle', 'Extraviados', ['reporte_extraviados'], $navPagina, $nExtrav ? '<span class="tp-badge">' . num($nExtrav) . '</span>' : '') ?>
    <?= nav_item('acuerdos.php', 'bi-calendar-check', 'Acuerdos', ['acuerdos', 'acuerdo'], $navPagina, $vencidos ? '<span class="tp-badge rojo" title="Vencidos">' . $vencidos . '</span>' : '') ?>
    <?= nav_item('bitacora.php', 'bi-clock-history', 'Bitácora', ['bitacora'], $navPagina) ?>

    <div class="tp-side-sec">Reportes</div>
    <?= nav_item('reporte_ejecutivo.php', 'bi-clipboard-data', 'Ejecutivo', ['reporte_ejecutivo', 'reportes'], $navPagina) ?>
    <?= nav_item('reporte_responsable.php', 'bi-person-lines-fill', 'Por responsable', ['reporte_responsable'], $navPagina) ?>
    <?= nav_item('reporte_movimientos.php', 'bi-arrow-left-right', 'Movimientos', ['reporte_movimientos'], $navPagina) ?>
    <?= nav_item('actas.php', 'bi-file-earmark-text', 'Actas', ['actas', 'acta'], $navPagina) ?>

    <?php if (puede('editor')): ?>
    <div class="tp-side-sec">Administración</div>
    <?= nav_item('catalogos.php', 'bi-list-ul', 'Catálogos', ['catalogos'], $navPagina) ?>
    <?php if (puede('admin')): ?>
    <?= nav_item('importar.php', 'bi-file-earmark-arrow-up', 'Importar Excel', ['importar'], $navPagina) ?>
    <?= nav_item('usuarios.php', 'bi-people', 'Usuarios', ['usuarios'], $navPagina) ?>
    <?= nav_item('respaldos.php', 'bi-shield-check', 'Respaldos', ['respaldos'], $navPagina) ?>
    <?= nav_item('glpi.php', 'bi-plug', 'Conexión GLPI', ['glpi'], $navPagina) ?>
    <?php endif; ?>
    <?php endif; ?>

    <div class="tp-user">
      <div class="av"><?= e($iniciales) ?></div>
      <div class="flex-grow-1 overflow-hidden">
        <div class="n text-truncate"><?= e($u['nombre']) ?></div>
        <div class="r text-truncate"><?= e(ROLES[$u['rol']] ?? $u['rol']) ?> · <?= e($u['usuario']) ?></div>
      </div>
      <a href="perfil.php" title="Cambiar mi contraseña"><i class="bi bi-key"></i></a>
    </div>
    <a href="logout.php" class="tp-salir" title="Guarda un respaldo y cierra tu sesión"><i class="bi bi-power"></i> Guardar y cerrar sesión</a>
  </div>
</aside>
<?php endif; ?>
<main class="tp-main">
<?php if ($u && $navPagina !== 'index'): ?>
  <div class="tp-regresar no-print">
    <a href="index.php" class="btn btn-sm btn-tp-out" onclick="if (document.referrer && new URL(document.referrer).origin === location.origin && history.length > 1) { history.back(); return false; }"><i class="bi bi-arrow-left"></i> Regresar</a>
    <span class="small text-body-secondary"><a href="index.php" class="text-body-secondary">Resumen integrado</a> <i class="bi bi-chevron-right small"></i> <b class="text-body"><?= e($titulo ?: 'Pantalla') ?></b></span>
  </div>
<?php endif; ?>
<?php foreach (flashes() as [$t, $m]): ?>
  <div class="alert alert-<?= e($t) ?> alert-dismissible fade show" role="alert"><?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
