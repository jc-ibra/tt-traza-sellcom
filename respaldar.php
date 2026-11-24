<?php
/**
 * Respaldo desde consola o Programador de tareas de Windows:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\TrazaPulso_Sellcom\respaldar.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo desde consola.'); }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/respaldo.php';
try {
    $r = respaldo_crear($argv[1] ?? 'programado');
    echo "Respaldo creado: {$r['ruta']} (" . round($r['tamano'] / 1024) . " KB, {$r['evidencias']} evidencias)\n";
    if ($r['copia']) echo "Copia en: {$r['copia']}\n";
} catch (Throwable $ex) {
    respaldo_log('ERROR cli: ' . $ex->getMessage());
    fwrite(STDERR, 'ERROR: ' . $ex->getMessage() . "\n");
    exit(1);
}
