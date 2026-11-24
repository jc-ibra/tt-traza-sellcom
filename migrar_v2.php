<?php
/**
 * Migración v2 (ejecutar desde consola, una vez):  C:\xampp\php\php.exe migrar_v2.php
 * - Aplica el esquema nuevo (etapas, paqueterías, regiones, actas).
 * - Asigna región a las ubicaciones existentes.
 * - Clasifica la etapa de cada equipo y extrae guía / paquetería / fecha de recepción de los comentarios.
 * Todo queda registrado en la bitácora de cada equipo como movimiento "Sistema".
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo desde consola.'); }

require __DIR__ . '/inc/bootstrap.php';

$schema = preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/sql/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) db()->exec($sql);
echo "Esquema actualizado.\n";

$reg = 0;
foreach (q('SELECT id, nombre FROM ubicaciones WHERE region_id IS NULL')->fetchAll() as $u) {
    if ($r = region_sugerida($u['nombre'])) {
        q('UPDATE ubicaciones SET region_id = (SELECT id FROM regiones WHERE nombre = ?) WHERE id = ?', [$r, $u['id']]);
        $reg++;
    }
}
echo "Ubicaciones con región asignada: $reg\n";
foreach (q('SELECT nombre FROM ubicaciones WHERE region_id IS NULL ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN) as $n) echo "  sin región: $n\n";

$equipos = q('SELECT e.id, e.extraviado, e.comentario, e.guia, e.paqueteria_id, e.fecha_recepcion, c.nombre AS concepto
              FROM equipos e LEFT JOIN conceptos c ON c.id = e.concepto_id WHERE e.etapa_id IS NULL')->fetchAll();
$admin = (int)q("SELECT id FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: null;
$cuenta = [];
$guias = 0;
db()->beginTransaction();
foreach ($equipos as $e) {
    $etapa = etapa_sugerida($e['concepto'], (int)$e['extraviado'], $e['comentario']);
    $d = ['etapa_id' => catalogo_id('etapas', $etapa, false)];
    $env = extraer_envio($e['comentario']);
    if ($env['guia'] && !$e['guia']) { $d['guia'] = $env['guia']; $guias++; }
    if ($env['paqueteria'] && !$e['paqueteria_id']) $d['paqueteria_id'] = catalogo_id('paqueterias', $env['paqueteria']);
    if ($env['fecha'] && !$e['fecha_recepcion'] && $etapa === 'EN ALMACEN CDMX') $d['fecha_recepcion'] = $env['fecha'];
    // la etapa "extraviado" no debe cambiar la marca original; la recepción solo si había dato
    $res = equipo_guardar((int)$e['id'], $d, 'sistema', 'Clasificación inicial de etapa a partir de concepto y comentarios (v2)', $admin);
    if (empty($d['fecha_recepcion']) && $etapa === 'EN ALMACEN CDMX') {
        // la regla automática puso la fecha de hoy; para datos heredados es mejor dejarla vacía si no se conoce
        q('UPDATE equipos SET fecha_recepcion = NULL WHERE id = ? AND fecha_recepcion = CURDATE()', [$e['id']]);
    }
    $cuenta[$etapa] = ($cuenta[$etapa] ?? 0) + 1;
}
db()->commit();
arsort($cuenta);
echo "Equipos clasificados: " . count($equipos) . "\n";
foreach ($cuenta as $k => $n) printf("  %-28s %d\n", $k, $n);
echo "Guías extraídas de comentarios: $guias\n";
