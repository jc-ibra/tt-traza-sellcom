<?php
declare(strict_types=1);

/** Periodo desde GET (por defecto últimos 7 días). */
function periodo(): array
{
    $hasta = g('hasta') ?: date('Y-m-d');
    $desde = g('desde') ?: date('Y-m-d', strtotime($hasta . ' -6 days'));
    if (!strtotime($desde) || !strtotime($hasta)) { $desde = date('Y-m-d', strtotime('-6 days')); $hasta = date('Y-m-d'); }
    if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
    return [$desde, $hasta];
}

/**
 * Analiza la bitácora de un periodo (sin importaciones ni ajustes del sistema).
 * @return array{entradas:array, salidas:array, localizados:int, extraviados:int, equipos:int, movimientos:int, por_usuario:array}
 */
function flujos_periodo(string $desde, string $hasta): array
{
    $r = ['entradas' => [], 'salidas' => [], 'localizados' => 0, 'extraviados' => 0, 'equipos' => 0, 'movimientos' => 0, 'por_usuario' => []];
    $st = q("SELECT m.equipo_id, m.cambios, COALESCE(u.nombre, 'Sistema') AS usuario FROM movimientos m LEFT JOIN usuarios u ON u.id = m.usuario_id
             WHERE m.fecha BETWEEN ? AND ? AND m.origen NOT IN ('importacion', 'sistema')", [$desde . ' 00:00:00', $hasta . ' 23:59:59']);
    $eqs = [];
    while ($m = $st->fetch()) {
        $r['movimientos']++;
        $eqs[$m['equipo_id']] = true;
        $r['por_usuario'][$m['usuario']] = ($r['por_usuario'][$m['usuario']] ?? 0) + 1;
        foreach (json_decode((string)$m['cambios'], true) ?: [] as $c) {
            if ($c['campo'] === 'etapa_id') {
                if ($c['despues'] !== null) $r['entradas'][$c['despues']] = ($r['entradas'][$c['despues']] ?? 0) + 1;
                if ($c['antes'] !== null) $r['salidas'][$c['antes']] = ($r['salidas'][$c['antes']] ?? 0) + 1;
            } elseif ($c['campo'] === 'extraviado') {
                if ($c['antes'] === 'SI' && $c['despues'] === 'NO') $r['localizados']++;
                if ($c['despues'] === 'SI') $r['extraviados']++;
            }
        }
    }
    $r['equipos'] = count($eqs);
    arsort($r['por_usuario']);
    return $r;
}

/** Barra de herramientas de un reporte (no se imprime). */
function barra_reporte(string $titulo, array $extraBotones = []): void
{
    echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">';
    echo '<div><a href="reportes.php" class="small"><i class="bi bi-arrow-left"></i> Reportes</a><h1 class="h4 mb-0">' . e($titulo) . '</h1></div>';
    echo '<div class="d-flex gap-2">';
    foreach ($extraBotones as $b) echo $b;
    echo '<button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / Guardar PDF</button></div></div>';
}

/** Encabezado que solo aparece al imprimir. */
function encabezado_impresion(string $titulo, string $subtitulo = ''): void
{
    echo '<div class="solo-print mb-3 border-bottom pb-2"><div class="d-flex justify-content-between align-items-end">'
        . '<div><div class="fw-bold fs-5">' . e($titulo) . '</div><div class="small">' . e($subtitulo) . '</div></div>'
        . '<div class="text-end small"><b>TrazaPulso · Sellcom</b><br>Generado ' . date('d/m/Y H:i') . ' por ' . e(user()['nombre'] ?? '') . '</div></div></div>';
}

function form_periodo(string $desde, string $hasta, string $extra = ''): void
{
    echo '<form class="card card-body p-2 mb-3 no-print" method="get"><div class="row g-2 align-items-end">'
        . '<div class="col-6 col-md-2"><label class="form-label">Desde</label><input type="date" name="desde" value="' . e($desde) . '" class="form-control form-control-sm"></div>'
        . '<div class="col-6 col-md-2"><label class="form-label">Hasta</label><input type="date" name="hasta" value="' . e($hasta) . '" class="form-control form-control-sm"></div>'
        . $extra
        . '<div class="col-auto"><button class="btn btn-sm btn-primary">Generar</button></div>'
        . '<div class="col-auto small">Rápido: '
        . '<a href="?' . e(qs(['desde' => date('Y-m-d', strtotime('-6 days')), 'hasta' => date('Y-m-d')])) . '">7 días</a> · '
        . '<a href="?' . e(qs(['desde' => date('Y-m-d', strtotime('monday this week')), 'hasta' => date('Y-m-d')])) . '">esta semana</a> · '
        . '<a href="?' . e(qs(['desde' => date('Y-m-01'), 'hasta' => date('Y-m-d')])) . '">este mes</a> · '
        . '<a href="?' . e(qs(['desde' => date('Y-m-d', strtotime('-29 days')), 'hasta' => date('Y-m-d')])) . '">30 días</a></div>'
        . '</div></form>';
}

/** Envía un .xlsx y termina. */
function enviar_xlsx(string $nombre, string $hoja, array $enc, iterable $filas, array $anchos = []): never
{
    require_once __DIR__ . '/xlsx_writer.php';
    $tmp = tempnam(sys_get_temp_dir(), 'tpx');
    xlsx_escribir($tmp, $hoja, $enc, $filas, $anchos);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '_' . date('Ymd_Hi') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}
