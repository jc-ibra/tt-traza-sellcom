<?php
// Configuración de TrazaPulso Sellcom.
// Las credenciales (base de datos, GLPI) se leen del archivo .env — no se escriben aquí.
require_once __DIR__ . '/inc/env.php';

return [
    'app_name' => 'TrazaPulso Sellcom',
    'timezone' => env('APP_TIMEZONE', 'America/Mexico_City'),
    'db' => [
        'host'    => env('DB_HOST', '127.0.0.1'),
        'port'    => (int)env('DB_PORT', 3306),
        'name'    => getenv('TP_DB') ?: env('DB_NAME', 'trazapulso'),
        'user'    => env('DB_USER', 'root'),
        'pass'    => (string)env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    // Conexión con GLPI (API REST)
    'glpi' => [
        'url'        => env('GLPI_URL'),               // ej. https://glpi.miempresa.com
        'app_token'  => env('GLPI_APP_TOKEN'),
        'user_token' => env('GLPI_USER_TOKEN'),
        'verify_ssl' => env('GLPI_VERIFY_SSL', true),  // false solo si el servidor usa certificado interno
        'timeout'    => (int)env('GLPI_TIMEOUT', 20),
    ],
    // Evidencias
    'upload_dir' => __DIR__ . '/uploads',
    'upload_max' => 15 * 1024 * 1024, // 15 MB por archivo
    'upload_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'msg', 'eml', 'zip'],

    // Respaldos automáticos (base de datos + evidencias + sistema)
    'respaldo_dir'   => env('RESPALDO_DIR', 'C:/xampp/respaldos/trazapulso'),
    'respaldo_copia' => env('RESPALDO_COPIA'),        // segunda carpeta (ej. OneDrive o USB). vacío = desactivado
    'respaldo_dias'  => (int)env('RESPALDO_DIAS', 30),
    'respaldo_auto_horas' => (int)env('RESPALDO_AUTO_HORAS', 24),
    'mysql_bin'      => env('MYSQL_BIN', 'C:/xampp/mysql/bin'),
];
