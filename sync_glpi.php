<?php
/**
 * Sincronización de estados con GLPI desde consola o Programador de tareas:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\TrazaPulso_Sellcom\sync_glpi.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo desde consola.'); }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/glpi_api.php';
$r = glpi_sincronizar(null);
if ($r['error']) { fwrite(STDERR, 'ERROR: ' . $r['error'] . "\n"); exit(1); }
printf("GLPI: %d tickets consultados, %d equipos actualizados, %d sin cambios, %d no encontrados, %d con código sin equivalencia (%.1f s)\n",
    $r['consultados'], $r['actualizados'], $r['sin_cambios'], count($r['no_encontrados']), array_sum($r['desconocidos']), $r['segundos']);
