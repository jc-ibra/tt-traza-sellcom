<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$w = ['1=1'];
$params = [];
$desde = g('desde', date('Y-m-d', strtotime('-30 days')));
$hasta = g('hasta', date('Y-m-d'));
if ($desde) { $w[] = 'm.fecha >= ?'; $params[] = $desde . ' 00:00:00'; }
if ($hasta) { $w[] = 'm.fecha <= ?'; $params[] = $hasta . ' 23:59:59'; }
if (g('origen')) { $w[] = 'm.origen = ?'; $params[] = g('origen'); }
elseif (!g('incluir_imp')) { $w[] = "m.origen <> 'importacion'"; }
if (g('usuario')) { $w[] = 'm.usuario_id = ?'; $params[] = (int)g('usuario'); }
if (g('q')) {
    $w[] = '(e.serie LIKE ? OR e.inventario LIKE ? OR m.comentario LIKE ? OR m.cambios LIKE ?)';
    $l = '%' . g('q') . '%';
    array_push($params, $l, $l, $l, $l);
}
$where = implode(' AND ', $w);
$porPagina = 100;
$pagina = max(1, (int)g('p', 1));
$total = (int)q("SELECT COUNT(*) FROM movimientos m JOIN equipos e ON e.id = m.equipo_id WHERE $where", $params)->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$off = (min($pagina, $paginas) - 1) * $porPagina;
$movs = q("SELECT m.*, u.nombre AS usuario, e.serie, e.inventario, t.nombre AS tipo
    FROM movimientos m JOIN equipos e ON e.id = m.equipo_id
    LEFT JOIN usuarios u ON u.id = m.usuario_id LEFT JOIN tipos_equipo t ON t.id = e.tipo_id
    WHERE $where ORDER BY m.fecha DESC, m.id DESC LIMIT $porPagina OFFSET $off", $params)->fetchAll();
$usuarios = q('SELECT id, nombre FROM usuarios ORDER BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR);

$titulo = 'Bitácora';
require __DIR__ . '/inc/header.php';
?>
<h1 class="h4 mb-3">Bitácora de movimientos <span class="badge text-bg-light border"><?= num($total) ?></span></h1>
<form class="card card-body p-2 mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label">Desde</label><input type="date" name="desde" value="<?= e($desde) ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label">Hasta</label><input type="date" name="hasta" value="<?= e($hasta) ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label">Tipo</label><select name="origen" class="form-select form-select-sm"><option value="">Todos</option><?php foreach (ORIGENES as $k => [$l]): ?><option value="<?= $k ?>" <?= g('origen') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label">Usuario</label><select name="usuario" class="form-select form-select-sm"><option value="">Todos</option><?php foreach ($usuarios as $k => $l): ?><option value="<?= $k ?>" <?= (string)g('usuario') === (string)$k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-2"><label class="form-label">Buscar</label><input name="q" value="<?= e(g('q', '')) ?>" class="form-control form-control-sm" placeholder="Serie, guía, texto…"></div>
    <div class="col-12 col-md-2 d-flex gap-2 align-items-center">
      <div class="form-check small"><input class="form-check-input" type="checkbox" name="incluir_imp" value="1" id="ii" <?= g('incluir_imp') ? 'checked' : '' ?>><label class="form-check-label" for="ii">Incluir importaciones</label></div>
      <button class="btn btn-sm btn-primary">Filtrar</button>
    </div>
  </div>
</form>
<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-tp mb-0">
      <thead><tr><th>Fecha</th><th>Equipo</th><th>Movimiento</th><th>Usuario</th></tr></thead>
      <tbody>
      <?php if (!$movs): ?><tr><td colspan="4" class="text-center text-body-secondary py-4">Sin movimientos en este periodo.</td></tr><?php endif; ?>
      <?php foreach ($movs as $m): ?>
        <tr>
          <td class="text-nowrap small"><?= fecha($m['fecha']) ?></td>
          <td class="text-nowrap"><a class="mono" href="equipo.php?id=<?= (int)$m['equipo_id'] ?>"><?= e($m['serie'] ?: codigo_equipo((int)$m['equipo_id'])) ?></a><div class="small text-body-secondary"><?= e($m['tipo']) ?></div></td>
          <td class="small"><?php require __DIR__ . '/inc/cambios_resumen.php'; ?></td>
          <td class="small text-nowrap"><?= e($m['usuario'] ?? 'Sistema') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($paginas > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm">
  <?php for ($i = 1; $i <= $paginas && $i <= 30; $i++): ?><li class="page-item <?= $i === $pagina ? 'active' : '' ?>"><a class="page-link" href="?<?= e(qs(['p' => $i])) ?>"><?= $i ?></a></li><?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
