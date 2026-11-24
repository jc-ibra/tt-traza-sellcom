<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/reportes.php';
require_login();

[$desde, $hasta] = periodo();
$solo = g('solo', '');   // etapa | ubicacion | envio | extravio | (todo)
$params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
$extraW = '';
$filtros = ['etapa' => '"etapa_id"', 'ubicacion' => '"ubicacion_id"', 'envio' => '"guia"', 'extravio' => '"extraviado"'];
if (isset($filtros[$solo])) {
    $extraW = ' AND m.cambios LIKE ?';
    $params[] = '%' . $filtros[$solo] . '%';
}
if (g('etapa_destino')) {
    $extraW .= ' AND m.cambios LIKE ?';
    // en LIKE la barra invertida es carácter de escape: se duplica para buscar el JSON literal (p. ej. "\/")
    $params[] = '%"campo":"etapa_id"%"despues":' . str_replace('\\', '\\\\', json_encode(catalogo_nombre('etapas', g('etapa_destino')), JSON_UNESCAPED_UNICODE)) . '%';
}
$movs = q("SELECT m.*, COALESCE(u.nombre, 'Sistema') AS usuario, e.serie, e.inventario, e.folio, t.nombre AS tipo, et.nombre AS etapa_actual
    FROM movimientos m JOIN equipos e ON e.id = m.equipo_id
    LEFT JOIN usuarios u ON u.id = m.usuario_id LEFT JOIN tipos_equipo t ON t.id = e.tipo_id LEFT JOIN etapas et ON et.id = e.etapa_id
    WHERE m.fecha BETWEEN ? AND ? AND m.origen NOT IN ('importacion', 'sistema') $extraW
    ORDER BY m.fecha, m.id", $params)->fetchAll();
$f = flujos_periodo($desde, $hasta);

function cambios_texto(?string $json): string
{
    $out = [];
    foreach (json_decode((string)$json, true) ?: [] as $c) {
        $out[] = (CAMPOS_EQUIPO[$c['campo']][0] ?? $c['campo']) . ': ' . ($c['antes'] ?? '(vacío)') . ' → ' . ($c['despues'] ?? '(vacío)');
    }
    return implode(' | ', $out);
}

if (g('xlsx')) {
    enviar_xlsx('Movimientos', 'MOVIMIENTOS',
        ['Fecha', 'ID', 'Serie', 'Inventario', 'Folio', 'Tipo', 'Movimiento', 'Cambios', 'Comentario', 'Usuario', 'Etapa actual'],
        (function () use ($movs) {
            foreach ($movs as $m) yield [fecha($m['fecha']), codigo_equipo((int)$m['equipo_id']), $m['serie'], $m['inventario'], $m['folio'], $m['tipo'],
                ORIGENES[$m['origen']][0] ?? $m['origen'], cambios_texto($m['cambios']), $m['comentario'], $m['usuario'], $m['etapa_actual']];
        })(), [16, 10, 16, 12, 16, 22, 14, 70, 50, 18, 22]);
}

$titulo = 'Movimientos del periodo';
require __DIR__ . '/inc/header.php';
barra_reporte('Movimientos del periodo', ['<a class="btn btn-sm btn-outline-success" href="?' . e(qs(['xlsx' => 1])) . '"><i class="bi bi-file-earmark-excel"></i> Excel</a>']);
$extra = '<div class="col-6 col-md-2"><label class="form-label">Mostrar</label><select name="solo" class="form-select form-select-sm">'
    . '<option value="">Todo</option>';
foreach (['etapa' => 'Cambios de etapa', 'ubicacion' => 'Cambios de ubicación', 'envio' => 'Guías / envíos', 'extravio' => 'Extravíos / localizados'] as $k => $l) {
    $extra .= '<option value="' . $k . '"' . ($solo === $k ? ' selected' : '') . '>' . $l . '</option>';
}
$extra .= '</select></div><div class="col-6 col-md-3"><label class="form-label">Que entraron a la etapa</label><select name="etapa_destino" class="form-select form-select-sm">'
    . opciones('etapas', g('etapa_destino'), 'Cualquiera') . '</select></div>';
form_periodo($desde, $hasta, $extra);
encabezado_impresion('Movimientos de equipos', 'Periodo: ' . fecha($desde, false) . ' al ' . fecha($hasta, false) . ($solo ? ' · ' . $solo : ''));
?>
<div class="row g-2 mb-3 text-center">
  <div class="col"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= num(count($movs)) ?></div><div class="small text-body-secondary">Registros</div></div></div>
  <div class="col"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= num(count(array_unique(array_column($movs, 'equipo_id')))) ?></div><div class="small text-body-secondary">Equipos</div></div></div>
  <div class="col"><div class="border rounded p-2"><div class="fs-4 fw-bold text-success"><?= num($f['localizados']) ?></div><div class="small text-body-secondary">Localizados</div></div></div>
  <div class="col"><div class="border rounded p-2"><div class="fs-4 fw-bold text-danger"><?= num($f['extraviados']) ?></div><div class="small text-body-secondary">Marcados extraviados</div></div></div>
</div>
<?php if ($f['entradas']): ?>
<p class="small"><b>Entradas por etapa:</b> <?php foreach ($f['entradas'] as $et => $n) echo e($et) . ' <b>+' . num($n) . '</b> · '; ?></p>
<?php endif; ?>
<div class="card"><div class="table-responsive">
  <table class="table table-sm table-bordered table-tp small mb-0">
    <thead><tr><th>Fecha</th><th>Equipo</th><th>Tipo</th><th>Cambios</th><th>Comentario</th><th>Usuario</th></tr></thead>
    <tbody>
    <?php if (!$movs): ?><tr><td colspan="6" class="text-center text-body-secondary py-3">Sin movimientos en el periodo.</td></tr><?php endif; ?>
    <?php foreach ($movs as $m): $o = ORIGENES[$m['origen']] ?? [$m['origen'], 'secondary']; ?>
      <tr>
        <td class="text-nowrap"><?= fecha($m['fecha']) ?></td>
        <td class="mono text-nowrap"><a href="equipo.php?id=<?= (int)$m['equipo_id'] ?>"><?= e($m['serie'] ?: codigo_equipo((int)$m['equipo_id'])) ?></a><div class="text-body-secondary"><?= e($m['inventario']) ?></div></td>
        <td><?= e($m['tipo']) ?></td>
        <td><span class="badge text-bg-<?= $o[1] ?>"><?= e($o[0]) ?></span>
          <?php foreach (json_decode((string)$m['cambios'], true) ?: [] as $c): ?><div><span class="text-body-secondary"><?= e(CAMPOS_EQUIPO[$c['campo']][0] ?? $c['campo']) ?>:</span> <?= e($c['antes'] ?? '—') ?> → <b><?= e($c['despues'] ?? '(vacío)') ?></b></div><?php endforeach; ?></td>
        <td><?= e($m['comentario']) ?></td>
        <td class="text-nowrap"><?= e($m['usuario']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/inc/footer.php'; ?>
