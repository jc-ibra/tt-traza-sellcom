<?php
/**
 * Instalador de TrazaPulso (ejecutar desde consola):
 *   C:\xampp\php\php.exe instalar.php [--importar=archivo.xlsx] [--hoja=BASE_SELLCOM]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo desde consola.'); }

$cfg = require __DIR__ . '/config.php';
$c = $cfg['db'];
$opt = getopt('', ['importar::', 'hoja::']);

echo "== TrazaPulso: instalación ==\n";
$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset={$c['charset']}", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$c['name']}`");
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/sql/schema.sql'))))) as $sql) {
    $pdo->exec($sql);
}
echo "Base de datos '{$c['name']}' lista.\n";

require __DIR__ . '/inc/bootstrap.php';

if (!q("SELECT 1 FROM usuarios WHERE rol = 'admin' LIMIT 1")->fetchColumn()) {
    $pw = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
    q("INSERT INTO usuarios (nombre, usuario, password_hash, rol, debe_cambiar) VALUES ('Administrador', 'admin', ?, 'admin', 1)", [password_hash($pw, PASSWORD_DEFAULT)]);
    echo "Usuario administrador creado:\n   usuario:    admin\n   contraseña: $pw   (se pedirá cambiarla al entrar)\n";
} else {
    echo "Ya existe un administrador; no se creó otro.\n";
}

if (!empty($opt['importar'])) {
    require __DIR__ . '/inc/importador.php';
    $hoja = $opt['hoja'] ?? 'BASE_SELLCOM';
    $adminId = (int)q("SELECT id FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    echo "Importando {$opt['importar']} (hoja $hoja)...\n";
    $r = importar_excel($opt['importar'], $hoja, false, $adminId);
    printf("Filas: %d | nuevos: %d | actualizados: %d | sin cambios: %d | omitidos: %d | acuerdos: %d\n",
        $r['filas'], $r['nuevos'], $r['actualizados'], $r['sin_cambios'], $r['omitidos'], $r['acuerdos_nuevos']);
    foreach ($r['catalogos_nuevos'] as $k => $n) echo "  catálogo $k: $n\n";
    if ($r['avisos']) {
        echo "Avisos (" . count($r['avisos']) . "):\n";
        foreach ($r['avisos'] as $a) echo "  - $a\n";
    }
}
echo "Listo. Abre http://localhost/TrazaPulso_Sellcom/\n";
