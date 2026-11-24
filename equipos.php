<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

[$where, $params] = equipos_filtro($_GET);

$ordenes = [
    'reciente' => 'e.updated_at DESC, e.id DESC',
    'antiguo'  => 'e.updated_at ASC, e.id ASC',
    'serie'    => 'e.serie ASC',
    'tipo'     => 't.nombre ASC, e.serie ASC',
    'concepto' => 'c.nombre ASC, e.serie ASC',
    'etapa'    => 'et.orden ASC, e.serie ASC',
    'dias'     => 'e.fecha_etapa IS NULL, e.fecha_etapa ASC',
    'ubicacion'=> 'u.nombre ASC, e.serie ASC',
];
$orden = g('orden', 'reciente');
if (!isset($ordenes[$orden])) $orden = 'reciente';

$porPagina = 50;
$pagina = max(1, (int)g('p', 1));
$total = (int)q("SELECT COUNT(*) FROM equipos e WHERE $where", $params)->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina = min($pagina, $paginas);
$off = ($pagina - 1) * $porPagina;

$equipos = q(SQL_EQUIPO_SELECT . " WHERE $where ORDER BY {$ordenes[$orden]} LIMIT $porPagina OFFSET $off", $params)->fetchAll();
$anios = q('SELECT DISTINCT anio FROM equipos WHERE anio IS NOT NULL ORDER BY anio DESC')->fetchAll(PDO::FETCH_COLUMN);

$hayFiltro = $where !== '1=1';
$filtroQs = qs(['p' => null, 'orden' => null]);
$acuerdoFiltro = g('acuerdo') ? q('SELECT id, descripcion FROM acuerdos WHERE id = ?', [(int)g('acuerdo')])->fetch() : null;

function sel(string $nombre, string $tabla, string $vacio): string
{
    $v = g($nombre, '');
    return '<select name="' . $nombre . '" class="form-select form-select-sm">'
        . '<option value="">' . e($vacio) . '</option>'
        . '<option value="null"' . ($v === 'null' ? ' selected' : '') . '>(Sin asignar)</option>'
        . preg_replace('/^<option value="">[^<]*<\/option>/', '', opciones($tabla, $v === 'null' ? '' : $v, '', false))
        . '</select>';
}

$titulo = 'Equipos';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0">Equipos <span class="badge text-bg-light border"><?= num($total) ?></span></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-success" href="exportar.php?<?= e($filtroQs) ?>"><i class="bi bi-file-earmark-excel"></i> Exportar a Excel</a>
    <?php if (puede('editor')): ?><a class="btn btn-sm btn-primary" href="equipo.php"><i class="bi bi-plus-lg"></i> Nuevo equipo</a><?php endif; ?>
  </div>
</div>

<?php if ($acuerdoFiltro): ?>
  <div class="alert alert-warning py-2">Mostrando equipos del <a href="acuerdo.php?id=<?= (int)$acuerdoFiltro['id'] ?>">acuerdo #<?= (int)$acuerdoFiltro['id'] ?></a>: <?= e(mb_strimwidth($acuerdoFiltro['descripcion'], 0, 140, '…')) ?></div>
<?php endif; ?>

<form class="card card-body p-2 mb-3 auto-submit" method="get">
  <div class="row g-2">
    <div class="col-12 col-md-6 col-xl-4"><input class="form-control form-control-sm" type="search" name="q" value="<?= e(g('q', '')) ?>" placeholder="Serie, inventario, folio, TP-00001, comentario…"></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('etapa', 'etapas', 'Etapa: todas') ?></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('glpi_real', 'estados_glpi', 'GLPI real: todos') ?></div>
    <div class="col-6 col-md-3 col-xl-2">
      <select name="concilia" class="form-select form-select-sm">
        <option value="">Sábana vs GLPI: todos</option>
        <?php foreach (['ok' => 'Sábana = GLPI', 'dif' => 'Sábana ≠ GLPI', 'sin' => 'Sin dato en alguno'] as $ck => $cl): ?><option value="<?= $ck ?>" <?= g('concilia') === $ck ? 'selected' : '' ?>><?= $cl ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('concepto', 'conceptos', 'Concepto: todos') ?></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('tipo', 'tipos_equipo', 'Tipo: todos') ?></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('ubicacion', 'ubicaciones', 'Ubicación: todas') ?></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('seguimiento', 'personas', 'Seguimiento: todos') ?></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('coordinador', 'personas', 'Coordinador: todos') ?></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('region', 'regiones', 'Región: todas') ?></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('paqueteria', 'paqueterias', 'Paquetería: todas') ?></div>
    <div class="col-6 col-md-3 col-xl-2"><?= sel('proyecto', 'proyectos', 'Proyecto: todos') ?></div>
    <div class="col-6 col-md-3 col-xl-1">
      <select name="extraviado" class="form-select form-select-sm">
        <option value="">Extrav.</option>
        <option value="1" <?= g('extraviado') === '1' ? 'selected' : '' ?>>Sí</option>
        <option value="0" <?= g('extraviado') === '0' ? 'selected' : '' ?>>No</option>
      </select>
    </div>
    <div class="col-6 col-md-3 col-xl-1">
      <select name="anio" class="form-select form-select-sm">
        <option value="">Año</option>
        <?php foreach ($anios as $a): ?><option <?= g('anio') == $a ? 'selected' : '' ?>><?= (int)$a ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
    <?php if ($hayFiltro): ?><a class="btn btn-sm btn-link" href="equipos.php">Limpiar filtros</a><?php endif; ?>
    <?php if (g('acuerdo')): ?><input type="hidden" name="acuerdo" value="<?= (int)g('acuerdo') ?>"><?php endif; ?>
    <div class="form-check form-switch small mb-0"><input class="form-check-input" type="checkbox" name="pendientes" value="1" id="fpend" <?= g('pendientes') ? 'checked' : '' ?> onchange="this.form.submit()"><label class="form-check-label" for="fpend">Solo pendientes (sin destruidos/entregados)</label></div>
    <?php if (g('glpi')): ?><input type="hidden" name="glpi" value="<?= (int)g('glpi') ?>"><span class="badge text-bg-info">GLPI sugerido: <?= e(catalogo_nombre('estados_glpi', g('glpi'))) ?></span><?php endif; ?>
    <?php foreach (['cat_glpi' => 'GLPI', 'cat_sabana' => 'Sábana'] as $ck => $cl): if (g($ck)): ?><input type="hidden" name="<?= $ck ?>" value="<?= e(g($ck)) ?>"><span class="badge text-bg-info"><?= $cl ?> → <?= e(g($ck)) ?></span><?php endif; endforeach; ?>
    <?php if (g('glpi_proyecto')): ?><input type="hidden" name="glpi_proyecto" value="<?= e(g('glpi_proyecto')) ?>"><span class="badge text-bg-info">Ticket GLPI del proyecto: <?= e(g('glpi_proyecto')) ?></span><?php endif; ?>
    <?php if (g('folio_glpi_calidad')): ?><input type="hidden" name="folio_glpi_calidad" value="<?= e(g('folio_glpi_calidad')) ?>"><span class="badge text-bg-info">Folio GLPI: <?= e(['existe' => 'existe en GLPI', 'noexiste' => 'no existe en GLPI', 'corresponde' => 'corresponde al equipo', 'nocorresponde' => 'no corresponde al equipo', 'sinconsultar' => 'sin consultar', 'sinfolio' => 'sin folio', 'repetido' => 'repetido'][g('folio_glpi_calidad')] ?? '') ?></span><?php endif; ?>
    <?php if (g('fisico')): ?><input type="hidden" name="fisico" value="<?= e(g('fisico')) ?>"><span class="badge text-bg-info">GLPI <?= g('fisico') === 'ok' ? 'coincide' : 'no coincide' ?> con la etapa física</span><?php endif; ?>
    <?php if (g('pulsott_sabana')): ?><input type="hidden" name="pulsott_sabana" value="<?= e(g('pulsott_sabana')) ?>"><span class="badge text-bg-info">Pulsott (sábana): <?= e(g('pulsott_sabana')) ?></span><?php endif; ?>
    <?php if (g('pulsott_real')): ?><input type="hidden" name="pulsott_real" value="<?= e(g('pulsott_real')) ?>"><span class="badge text-bg-info">Pulsott (GLPI): <?= e(g('pulsott_real')) ?></span><?php endif; ?>
    <?php if (g('pulsott')): ?><input type="hidden" name="pulsott" value="<?= e(g('pulsott')) ?>"><span class="badge text-bg-info">Pulsott sugerido: <?= e(g('pulsott')) ?></span><?php endif; ?>
    <?php if (g('dias_etapa')): ?><input type="hidden" name="dias_etapa" value="<?= (int)g('dias_etapa') ?>"><span class="badge text-bg-danger">Más de <?= (int)g('dias_etapa') ?> días en la misma etapa</span><?php endif; ?>
    <?php if (g('sin_mov_dias')): ?><input type="hidden" name="sin_mov_dias" value="<?= (int)g('sin_mov_dias') ?>"><span class="badge text-bg-warning">Sin movimiento hace más de <?= (int)g('sin_mov_dias') ?> días</span><?php endif; ?>
    <div class="ms-auto d-flex align-items-center gap-1 small">
      Ordenar:
      <select name="orden" class="form-select form-select-sm w-auto">
        <?php foreach (['reciente' => 'Movimiento más reciente', 'antiguo' => 'Sin mover hace más tiempo', 'dias' => 'Más días en su etapa', 'etapa' => 'Etapa (flujo)', 'serie' => 'Serie', 'tipo' => 'Tipo', 'concepto' => 'Concepto', 'ubicacion' => 'Ubicación'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $orden === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<form method="post" action="masivo.php">
  <?= csrf_field() ?>
  <input type="hidden" name="paso" value="formulario">
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover table-tp tbl-1linea mb-0">
        <thead>
          <tr>
            <?php if (puede('editor')): ?><th style="width:1%"><input type="checkbox" class="form-check-input" id="sel-todos" title="Seleccionar página"></th><?php endif; ?>
            <th>ID</th><th>Serie</th><th>Inventario</th><th>Folio</th><th>GLPI</th><th>Tipo</th><th>Etapa</th><th>Concepto</th><th>Ubicación</th><th>Seguimiento</th><th>Coordinador</th><th title="Días en la etapa actual">Días</th><th>Últ. mov.</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$equipos): ?><tr><td colspan="14" class="text-center text-body-secondary py-4">No hay equipos con esos filtros.</td></tr><?php endif; ?>
        <?php foreach ($equipos as $eq): ?>
          <tr>
            <?php if (puede('editor')): ?><td><input type="checkbox" class="form-check-input sel-eq" name="ids[]" value="<?= (int)$eq['id'] ?>"></td><?php endif; ?>
            <td class="text-nowrap"><a href="equipo.php?id=<?= (int)$eq['id'] ?>" class="small"><?= codigo_equipo((int)$eq['id']) ?></a></td>
            <td class="mono"><a href="equipo.php?id=<?= (int)$eq['id'] ?>"><?= e($eq['serie'] ?? '—') ?></a></td>
            <td class="mono small"><?= e($eq['inventario'] ?? '—') ?></td>
            <td class="mono small"><?= e($eq['folio'] ?? '') ?></td>
            <td class="small" title="<?= e('Folio GLPI ' . ($eq['folio_glpi'] ?? '—') . ' · Sábana: ' . ($eq['estado_glpi_sabana'] ?? 'sin dato') . ' · GLPI: ' . ($eq['estado_glpi_real'] ?? 'sin consultar')) ?>"><span class="mono"><?= e($eq['folio_glpi'] ?? '') ?></span>
              <?php $ig = $eq['estado_glpi_real'] && $eq['estado_glpi_real'] === $eq['estado_glpi_sabana']; ?><span class="<?= !$eq['estado_glpi_real'] ? 'text-body-tertiary' : ($ig ? 'text-success' : 'text-warning-emphasis fw-semibold') ?>"><?= e($eq['estado_glpi_real'] ?? $eq['estado_glpi_sabana'] ?? '') ?></span></td>
            <td class="small" title="<?= e($eq['tipo']) ?>"><?= e($eq['tipo']) ?></td>
            <td><?= etapa_badge($eq['etapa'], $eq['etapa_color']) ?><?php if ($eq['guia']): ?> <i class="bi bi-truck text-body-secondary" title="<?= e(($eq['paqueteria'] ?? '') . ' ' . $eq['guia']) ?>"></i><?php endif; ?></td>
            <td class="small" title="<?= e($eq['concepto']) ?>"><?= e($eq['concepto']) ?></td>
            <td class="small" title="<?= e($eq['ubicacion'] . ($eq['sucursal'] ? ' / ' . $eq['sucursal'] : '')) ?>"><?= e($eq['ubicacion']) ?><?= $eq['sucursal'] ? ' <span class="text-body-secondary">/ ' . e($eq['sucursal']) . '</span>' : '' ?></td>
            <td class="small" title="<?= e($eq['seguimiento']) ?>"><?= e($eq['seguimiento']) ?></td>
            <td class="small"><?= e($eq['coordinador'] ?? '') ?: '<span class="text-body-tertiary">—</span>' ?></td>
            <td class="small text-end"><?php $de = $eq['etapa_final'] ? null : dias_desde($eq['fecha_etapa']); echo $de === null ? '<span class="text-body-tertiary">—</span>' : '<span class="' . ($de > 7 ? 'text-danger fw-semibold' : '') . '">' . $de . '</span>'; ?></td>
            <td class="small text-nowrap text-body-secondary"><?= fecha($eq['updated_at'], false) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (puede('editor') && $total): ?>
  <div class="barra-masiva card card-body py-2 mt-2 d-none shadow" id="barra-masiva">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span><b id="sel-contador">0</b> seleccionados en esta página</span>
      <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right"></i> Registrar movimiento a seleccionados</button>
      <button class="btn btn-sm btn-outline-dark" formaction="actas.php" name="paso" value="nueva"><i class="bi bi-file-earmark-text"></i> Generar acta</button>
    </div>
  </div>
  <?php endif; ?>
</form>

<div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
  <div class="small text-body-secondary">Mostrando <?= $total ? num($off + 1) : 0 ?>–<?= num(min($off + $porPagina, $total)) ?> de <?= num($total) ?></div>
  <?php if (puede('editor') && $hayFiltro && $total): ?>
    <form method="post" action="masivo.php" class="m-0">
      <?= csrf_field() ?>
      <input type="hidden" name="paso" value="formulario">
      <input type="hidden" name="filtro" value="<?= e($filtroQs) ?>">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-collection"></i> Registrar movimiento a los <?= num($total) ?> equipos filtrados</button>
      <button class="btn btn-sm btn-outline-dark" formaction="actas.php" name="paso" value="nueva"><i class="bi bi-file-earmark-text"></i> Acta con los <?= num($total) ?></button>
    </form>
  <?php endif; ?>
  <?php if ($paginas > 1): ?>
  <nav><ul class="pagination pagination-sm mb-0">
    <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e(qs(['p' => $pagina - 1])) ?>">‹</a></li>
    <?php for ($i = max(1, $pagina - 3); $i <= min($paginas, $pagina + 3); $i++): ?>
      <li class="page-item <?= $i === $pagina ? 'active' : '' ?>"><a class="page-link" href="?<?= e(qs(['p' => $i])) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e(qs(['p' => $pagina + 1])) ?>">›</a></li>
  </ul></nav>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
