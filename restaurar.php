<?php
/**
 * Restaurar la base de datos (y evidencias) desde un respaldo .zip:
 *   C:\xampp\php\php.exe restaurar.php "C:\xampp\respaldos\trazapulso\TrazaPulso_AAAAMMDD_HHMMSS_auto.zip"
 * Antes de restaurar se crea automáticamente un respaldo del estado actual.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo desde consola.'); }
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/respaldo.php';

$zipPath = $argv[1] ?? '';
if (!$zipPath || !is_file($zipPath)) {
    echo "Uso: php restaurar.php <archivo de respaldo .zip>\n\nRespaldos disponibles:\n";
    foreach (respaldo_lista() as $r) echo '  ' . $r['ruta'] . '  (' . date('d/m/Y H:i', $r['fecha']) . ")\n";
    exit(1);
}
echo "Se restaurará: $zipPath\nEsto REEMPLAZA la información actual del sistema. Escribe SI para continuar: ";
if (trim((string)fgets(STDIN)) !== 'SI') { echo "Cancelado.\n"; exit(1); }

$previo = respaldo_crear('antes_de_restaurar');
echo "Respaldo del estado actual: {$previo['ruta']}\n";

$z = new ZipArchive();
if ($z->open($zipPath) !== true) exit("No se pudo abrir el respaldo.\n");
$sql = $z->getFromName('base_de_datos.sql');
if ($sql === false) exit("El archivo no contiene base_de_datos.sql\n");
$tmp = respaldo_dir() . '/tmp_restaurar.sql';
file_put_contents($tmp, $sql);

$c = cfg('db');
$mysql = rtrim((string)cfg('mysql_bin'), '/\\') . '/mysql.exe';
$cmd = escapeshellarg($mysql) . ' --host=' . escapeshellarg($c['host']) . ' --port=' . (int)$c['port'] . ' --user=' . escapeshellarg($c['user'])
    . ($c['pass'] !== '' ? ' --password=' . escapeshellarg($c['pass']) : '') . ' --default-character-set=utf8mb4 '
    . escapeshellarg($c['name']) . ' < ' . escapeshellarg($tmp) . ' 2>&1';
exec($cmd, $out, $code);
@unlink($tmp);
if ($code !== 0) exit('Error al restaurar la base: ' . implode(' ', $out) . "\n");
echo "Base de datos restaurada.\n";

$dest = rtrim((string)cfg('upload_dir'), '/\\');
$n = 0;
for ($i = 0; $i < $z->numFiles; $i++) {
    $name = $z->getNameIndex($i);
    if (!str_starts_with($name, 'evidencias/') || str_ends_with($name, '/') || str_contains($name, '..')) continue;
    $target = $dest . '/' . substr($name, strlen('evidencias/'));
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
    file_put_contents($target, $z->getFromIndex($i));
    $n++;
}
echo "Evidencias restauradas: $n\nListo.\n";
