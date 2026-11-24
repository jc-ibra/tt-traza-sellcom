<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/respaldo.php';

// "Guardar y cerrar sesión": la información ya se guarda al momento en la base de datos;
// además se hace un respaldo de seguridad si el último tiene más de 1 hora.
$resp = '';
if (user()) {
    try {
        $ult = respaldo_ultimo();
        if (!$ult || $ult['fecha'] < time() - 3600) {
            respaldo_crear('cierre');
            $resp = date('H:i');
        } else {
            $resp = date('H:i', $ult['fecha']);
        }
    } catch (Throwable $e) {
        respaldo_log('ERROR al cerrar sesión: ' . $e->getMessage());
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect('login.php?salida=1' . ($resp ? '&resp=' . urlencode($resp) : ''));
