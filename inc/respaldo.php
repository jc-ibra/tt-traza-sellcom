<?php
declare(strict_types=1);

/**
 * Respaldo completo en un solo .zip:
 *   - base_de_datos.sql  (volcado completo de MySQL)
 *   - evidencias/        (archivos adjuntos)
 *   - sistema/           (código del programa, para reinstalar igual)
 */
function respaldo_dir(): string
{
    $d = rtrim(str_replace('\\', '/', (string)cfg('respaldo_dir')), '/');
    if (!is_dir($d) && !mkdir($d, 0775, true)) throw new RuntimeException("No se pudo crear la carpeta de respaldos: $d");
    return $d;
}

function respaldo_lista(): array
{
    $out = [];
    foreach (glob(respaldo_dir() . '/TrazaPulso_*.zip') ?: [] as $f) {
        $out[] = ['archivo' => basename($f), 'ruta' => $f, 'fecha' => filemtime($f), 'tamano' => filesize($f)];
    }
    usort($out, fn($a, $b) => $b['fecha'] <=> $a['fecha']);
    return $out;
}

function respaldo_ultimo(): ?array
{
    return respaldo_lista()[0] ?? null;
}

function respaldo_crear(string $motivo = 'manual'): array
{
    @set_time_limit(600);
    $dir = respaldo_dir();
    $c = cfg('db');
    $stamp = date('Ymd_His');
    $sql = $dir . "/tmp_$stamp.sql";
    $dump = rtrim((string)cfg('mysql_bin'), '/\\') . '/mysqldump' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    if (!is_file($dump)) throw new RuntimeException("No se encontró mysqldump en $dump");

    $cmd = escapeshellarg($dump)
        . ' --host=' . escapeshellarg($c['host']) . ' --port=' . (int)$c['port']
        . ' --user=' . escapeshellarg($c['user']) . ($c['pass'] !== '' ? ' --password=' . escapeshellarg($c['pass']) : '')
        . ' --single-transaction --routines --triggers --default-character-set=utf8mb4 --add-drop-table'
        . ' --result-file=' . escapeshellarg($sql) . ' ' . escapeshellarg($c['name']) . ' 2>&1';
    exec($cmd, $salida, $codigo);
    if ($codigo !== 0 || !is_file($sql) || filesize($sql) < 100) {
        @unlink($sql);
        throw new RuntimeException('Falló el volcado de la base de datos: ' . implode(' ', $salida));
    }

    $zipPath = $dir . "/TrazaPulso_{$stamp}_{$motivo}.zip";
    $z = new ZipArchive();
    if ($z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo crear el archivo de respaldo.');
    $z->addFile($sql, 'base_de_datos.sql');
    $base = realpath(__DIR__ . '/..');
    $uploads = realpath((string)cfg('upload_dir'));
    $nArch = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ruta = $f->getRealPath();
        $rel = str_replace('\\', '/', substr($ruta, strlen($base) + 1));
        if (str_starts_with($rel, 'uploads/tmp/')) continue;
        // archivos temporales de Office (~$...) o bloqueados por otro programa: se omiten
        if (str_starts_with($f->getFilename(), '~$')) continue;
        if ($rel === '.env') continue;   // las credenciales no viajan en los respaldos
        $fh = @fopen($ruta, 'rb');
        if (!$fh) { respaldo_log("Omitido (bloqueado): $rel"); continue; }
        fclose($fh);
        if ($uploads && str_starts_with($ruta, $uploads . DIRECTORY_SEPARATOR)) {
            $z->addFile($ruta, 'evidencias/' . str_replace('\\', '/', substr($ruta, strlen($uploads) + 1)));
            if ($f->getFilename()[0] !== '.') $nArch++;
        } else {
            $z->addFile($ruta, 'sistema/' . $rel);
        }
    }
    $z->setArchiveComment("TrazaPulso respaldo $stamp ($motivo) - BD {$c['name']}");
    $z->addFromString('LEEME.txt', "Respaldo de TrazaPulso Sellcom\r\nFecha: " . date('d/m/Y H:i:s') . "\r\nMotivo: $motivo\r\n\r\n"
        . "Contenido:\r\n  base_de_datos.sql  -> toda la información (equipos, bitácora, acuerdos, actas, usuarios)\r\n"
        . "  evidencias/        -> archivos adjuntos ($nArch)\r\n  sistema/           -> el programa\r\n\r\n"
        . "Para restaurar (desde la carpeta del sistema):\r\n  C:\\xampp\\php\\php.exe restaurar.php \"" . str_replace('/', '\\', $zipPath) . "\"\r\n");
    if (!$z->close()) throw new RuntimeException('No se pudo cerrar el archivo de respaldo.');
    @unlink($sql);

    // copia secundaria
    $copia = cfg('respaldo_copia');
    $msgCopia = null;
    if ($copia) {
        $copia = rtrim(str_replace('\\', '/', (string)$copia), '/');
        if ((is_dir($copia) || @mkdir($copia, 0775, true)) && @copy($zipPath, $copia . '/' . basename($zipPath))) {
            $msgCopia = $copia;
            respaldo_depurar($copia);
        } else {
            respaldo_log("No se pudo copiar a la carpeta secundaria $copia");
        }
    }
    $borrados = respaldo_depurar($dir);
    respaldo_log("OK $motivo " . basename($zipPath) . ' ' . round(filesize($zipPath) / 1024) . ' KB');
    return ['archivo' => basename($zipPath), 'ruta' => $zipPath, 'tamano' => filesize($zipPath), 'copia' => $msgCopia, 'borrados' => $borrados, 'evidencias' => $nArch];
}

/** Conserva los respaldos de los últimos N días (siempre al menos los 5 más recientes). */
function respaldo_depurar(string $dir): int
{
    $files = glob($dir . '/TrazaPulso_*.zip') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $limite = time() - (int)cfg('respaldo_dias') * 86400;
    $n = 0;
    foreach (array_slice($files, 5) as $f) {
        if (filemtime($f) < $limite && @unlink($f)) $n++;
    }
    return $n;
}

function respaldo_log(string $msg): void
{
    @file_put_contents(respaldo_dir() . '/respaldos.log', date('Y-m-d H:i:s') . " $msg\r\n", FILE_APPEND);
}

/** Respaldo automático: si el último tiene más de N horas, se genera uno (silencioso). */
function respaldo_auto(): void
{
    try {
        $horas = (int)cfg('respaldo_auto_horas');
        if ($horas <= 0) return;
        $marca = respaldo_dir() . '/.ultimo_intento';
        // evita reintentos en cada clic si algo falla: como máximo un intento por hora
        if (is_file($marca) && filemtime($marca) > time() - 3600) return;
        $u = respaldo_ultimo();
        if ($u && $u['fecha'] > time() - $horas * 3600) return;
        @touch($marca);
        respaldo_crear('auto');
    } catch (Throwable $ex) {
        respaldo_log('ERROR auto: ' . $ex->getMessage());
    }
}
