<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/xlsx_writer.php';
require_login();

[$where, $params] = equipos_filtro($_GET);
$st = q("SELECT e.*, t.nombre AS tipo, pr.nombre AS proyecto, c.nombre AS concepto, u.nombre AS ubicacion,
        ps.nombre AS seguimiento, pc.nombre AS coordinador, et.nombre AS etapa, pq.nombre AS paqueteria, rg.nombre AS region,
        gl.nombre AS estado_glpi, gl.estado_pulsott, glr.nombre AS estado_glpi_real, glr.estado_pulsott AS pulsott_real, gsb.nombre AS estado_glpi_sabana, gsb.estado_pulsott AS pulsott_sabana,
        (SELECT a.descripcion FROM acuerdos a JOIN acuerdo_equipo ae ON ae.acuerdo_id = a.id
          WHERE ae.equipo_id = e.id AND a.estatus = 'abierto' ORDER BY a.id DESC LIMIT 1) AS acuerdo,
        (SELECT MAX(m.fecha) FROM movimientos m WHERE m.equipo_id = e.id) AS ult_mov
    FROM equipos e
    LEFT JOIN tipos_equipo t ON t.id = e.tipo_id LEFT JOIN proyectos pr ON pr.id = e.proyecto_id
    LEFT JOIN conceptos c ON c.id = e.concepto_id LEFT JOIN ubicaciones u ON u.id = e.ubicacion_id
    LEFT JOIN personas ps ON ps.id = e.seguimiento_id LEFT JOIN personas pc ON pc.id = e.coordinador_id
    LEFT JOIN etapas et ON et.id = e.etapa_id LEFT JOIN paqueterias pq ON pq.id = e.paqueteria_id LEFT JOIN regiones rg ON rg.id = u.region_id
    LEFT JOIN estados_glpi gl ON gl.id = e.estado_glpi_id
    LEFT JOIN estados_glpi glr ON glr.id = e.estado_glpi_real_id
    LEFT JOIN estados_glpi gsb ON gsb.id = e.estado_glpi_sabana_id
    WHERE $where ORDER BY e.id", $params);

$filas = (function () use ($st) {
    while ($r = $st->fetch()) {
        yield [codigo_equipo((int)$r['id']), $r['folio'], $r['inventario'], $r['serie'], $r['tipo'], $r['proyecto'],
            $r['etapa'], $r['concepto'], $r['extraviado'] ? 'SI' : 'NO', $r['ubicacion'], $r['region'], $r['sucursal'],
            $r['paqueteria'], $r['guia'], $r['fecha_recepcion'] ? fecha($r['fecha_recepcion'], false) : null,
            $r['seguimiento'], $r['comentario'], $r['acuerdo'], $r['coordinador'], $r['anio'] !== null ? (int)$r['anio'] : null,
            $r['fecha_etapa'] ? dias_desde($r['fecha_etapa']) : null, fecha($r['ult_mov']), $r['folio_glpi'], $r['estado_glpi_sabana'], $r['pulsott_sabana'], $r['estado_glpi_real'], $r['pulsott_real'],
            ($r['estado_glpi_real'] && $r['estado_glpi_sabana']) ? ($r['estado_glpi_real'] === $r['estado_glpi_sabana'] ? 'SI' : 'NO') : 'SIN DATO'];
    }
})();

$tmp = tempnam(sys_get_temp_dir(), 'tpx');
xlsx_escribir($tmp, 'EQUIPOS',
    ['ID TrazaPulso', 'c_Folio', 'c_Inventario', 'c_Serie', 'c_Equipo', 'c_Proyecto', 'ETAPA', 'CONCEPTOS', 'EXTRAVIADOS', 'UBICACIÓN', 'REGIÓN (calculada)', 'SUCURSAL',
     'PAQUETERÍA', 'GUÍA', 'FECHA RECEPCIÓN', 'SEGUIMIENTO CON', 'COMENTARIO', 'ACUERDO', 'CORDINADOR', 'AÑO', 'DÍAS EN ETAPA', 'ÚLTIMO MOVIMIENTO', 'FOLIO GLPI', 'ESTATUS DE GLPI', 'ESTATUS PULSOTT', 'ESTATUS EN GLPI (CONSULTA)', 'PULSOTT SEGÚN GLPI', 'SÁBANA = GLPI'],
    $filas, [14, 16, 14, 18, 24, 18, 26, 34, 12, 30, 14, 22, 14, 26, 14, 20, 50, 50, 18, 8, 10, 18, 12, 22, 16, 26, 18, 14]);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="TrazaPulso_equipos_' . date('Ymd_Hi') . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
