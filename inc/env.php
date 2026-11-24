<?php
declare(strict_types=1);

/**
 * Lector mínimo de archivos .env (KEY=VALOR). Las credenciales viven aquí y no en el código.
 * El archivo está protegido por .htaccess (no se puede abrir desde el navegador) y se excluye de los respaldos.
 */
const ENV_ARCHIVO = __DIR__ . '/../.env';

function env_cargar(): array
{
    static $vals = null;
    if ($vals !== null) return $vals;
    $vals = [];
    if (!is_file(ENV_ARCHIVO)) return $vals;
    foreach (file(ENV_ARCHIVO, FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $linea, 2));
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
            $v = str_replace(['\\"', '\\\\'], ['"', '\\'], $v);
        }
        $vals[$k] = $v;
    }
    return $vals;
}

function env(string $clave, $defecto = null)
{
    $vals = env_cargar();
    $g = getenv($clave);   // una variable de entorno del sistema tiene prioridad sobre el .env
    if ($g !== false && $g !== '') $vals[$clave] = $g;
    if (!array_key_exists($clave, $vals) || $vals[$clave] === '') return $defecto;
    $v = $vals[$clave];
    $l = strtolower($v);
    if ($l === 'true') return true;
    if ($l === 'false') return false;
    return $v;
}

/** Actualiza (o agrega) claves en el .env conservando comentarios y el resto del contenido. */
function env_guardar(array $nuevos): void
{
    $lineas = is_file(ENV_ARCHIVO) ? (file(ENV_ARCHIVO, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $fmt = function ($v): string {
        $v = (string)$v;
        if ($v === '' || preg_match('/^[A-Za-z0-9_.:\/\-]+$/', $v)) return $v;
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    };
    $pendientes = $nuevos;
    foreach ($lineas as $i => $linea) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $linea, $m) && array_key_exists($m[1], $pendientes)) {
            $lineas[$i] = $m[1] . '=' . $fmt($pendientes[$m[1]]);
            unset($pendientes[$m[1]]);
        }
    }
    foreach ($pendientes as $k => $v) $lineas[] = $k . '=' . $fmt($v);
    if (file_put_contents(ENV_ARCHIVO, implode(PHP_EOL, $lineas) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir el archivo .env');
    }
}

/** Muestra solo los últimos caracteres de un secreto. */
function env_enmascarar(?string $v): string
{
    if (!$v) return '(vacío)';
    return str_repeat('•', 8) . substr($v, -4);
}
