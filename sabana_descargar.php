<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/reportes.php';
require __DIR__ . '/inc/sabana.php';
require_login();

// Sábana con las mismas columnas del Excel original (BASE_SELLCOM); "completo" agrega las columnas del sistema al final.
$completo = g('modo') === 'completo';
$st = q("SELECT e.*, t.nombre AS tipo, pr.nombre AS proyecto, c.nombre AS concepto, u.nombre AS ubicacion, ps.nombre AS seguimiento,
        pc.nombre AS coordinador, et.nombre AS etapa, pq.nombre AS paqueteria, rg.nombre AS region, gl.nombre AS estado_glpi, gl.estado_pulsott, glr.nombre AS estado_glpi_real, glr.estado_pulsott AS pulsott_real, gsb.nombre AS estado_glpi_sabana, gsb.estado_pulsott AS pulsott_sabana,
        (SELECT a.descripcion FROM acuerdos a JOIN acuerdo_equipo ae ON ae.acuerdo_id = a.id WHERE ae.equipo_id = e.id AND a.estatus = 'abierto' ORDER BY a.id DESC LIMIT 1) AS acuerdo
    FROM equipos e
    LEFT JOIN tipos_equipo t ON t.id = e.tipo_id LEFT JOIN proyectos pr ON pr.id = e.proyecto_id LEFT JOIN conceptos c ON c.id = e.concepto_id
    LEFT JOIN ubicaciones u ON u.id = e.ubicacion_id LEFT JOIN regiones rg ON rg.id = u.region_id LEFT JOIN personas ps ON ps.id = e.seguimiento_id
    LEFT JOIN personas pc ON pc.id = e.coordinador_id LEFT JOIN etapas et ON et.id = e.etapa_id LEFT JOIN paqueterias pq ON pq.id = e.paqueteria_id
    LEFT JOIN estados_glpi gl ON gl.id = e.estado_glpi_id
    LEFT JOIN estados_glpi glr ON glr.id = e.estado_glpi_real_id
    LEFT JOIN estados_glpi gsb ON gsb.id = e.estado_glpi_sabana_id
    ORDER BY e.id");

$enc = array_merge(SABANA_COLUMNAS, ['Etapa en TrazaPulso', 'FOLIO GLPI', 'ESTATUS DE GLPI', 'ESTATUS PULSOTT', 'ESTATUS EN GLPI (CONSULTA)', 'PULSOTT SEGÚN GLPI', 'SÁBANA = GLPI']);
$anchos = [16, 14, 18, 24, 18, 34, 12, 30, 20, 50, 50, 18, 8, 26, 12, 22, 16, 24, 18, 12];
if ($completo) {
    array_push($enc, 'SUCURSAL', 'PAQUETERÍA', 'GUÍA', 'FECHA RECEPCIÓN', 'REGIÓN (calculada)', 'ID TrazaPulso');
    array_push($anchos, 20, 14, 26, 14, 14, 12);
}
$filas = (function () use ($st, $completo) {
    while ($r = $st->fetch()) {
        $f = [$r['folio'] ?? 'N/A', $r['inventario'] ?? 'N/A', $r['serie'] ?? 'N/A', $r['tipo'], $r['proyecto'], $r['concepto'],
            $r['extraviado'] ? 'SI' : 'NO', $r['ubicacion'], $r['seguimiento'], $r['comentario'], $r['acuerdo'],
            $r['coordinador'] ?? 'SIN CORDINADOR', $r['anio'] !== null ? (int)$r['anio'] : null,
            $r['etapa'], $r['folio_glpi'], $r['estado_glpi_sabana'], $r['pulsott_sabana'], $r['estado_glpi_real'], $r['pulsott_real'],
            ($r['estado_glpi_real'] && $r['estado_glpi_sabana']) ? ($r['estado_glpi_real'] === $r['estado_glpi_sabana'] ? 'SI' : 'NO') : 'SIN DATO'];
        if ($completo) {
            array_push($f, $r['sucursal'], $r['paqueteria'], $r['guia'],
                $r['fecha_recepcion'] ? fecha($r['fecha_recepcion'], false) : null, $r['region'], codigo_equipo((int)$r['id']));
        }
        yield $f;
    }
})();
enviar_xlsx($completo ? 'Sabana_TrazaPulso_completa' : 'Sabana_TrazaPulso', 'BASE_SELLCOM', $enc, $filas, $anchos);
