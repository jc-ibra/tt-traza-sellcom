<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$ev = q('SELECT * FROM evidencias WHERE id = ?', [(int)g('id')])->fetch();
$base = realpath(cfg('upload_dir'));
$ruta = $ev ? realpath(cfg('upload_dir') . '/' . $ev['archivo']) : false;
if (!$ev || !$ruta || !$base || !str_starts_with($ruta, $base . DIRECTORY_SEPARATOR) || !is_file($ruta)) {
    http_response_code(404);
    exit('Evidencia no encontrada.');
}
$ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
$tipos = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8'];
$inline = isset($tipos[$ext]);
header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $ev['nombre_original']) . '"; filename*=UTF-8\'\'' . rawurlencode($ev['nombre_original']));
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
if ($ext !== 'pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=3600');
readfile($ruta);
