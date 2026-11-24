<?php
require __DIR__ . '/inc/bootstrap.php';

$error = null;
$r = g('r', '');
// solo redirecciones locales
if (!is_string($r) || !preg_match('#^/[^/\\\\]#', $r)) $r = 'index.php';

if (user()) redirect($r);

if (is_post()) {
    csrf_check();
    $usr = (string)p('usuario', '');
    $pwd = (string)($_POST['password'] ?? '');
    $u = q('SELECT * FROM usuarios WHERE usuario = ? AND activo = 1', [$usr])->fetch();
    if ($u && password_verify($pwd, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        q('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?', [$u['id']]);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($pwd, PASSWORD_DEFAULT), $u['id']]);
        }
        redirect($r);
    }
    usleep(800000);
    $error = 'Usuario o contraseña incorrectos.';
}
$titulo = 'Iniciar sesión';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iniciar sesión · <?= e(cfg('app_name')) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/app.css?v=27">
</head>
<body>
<div class="login-wrap px-3">
  <div class="card p-4" style="width: 100%; max-width: 380px;">
    <div class="text-center mb-3">
      <div class="fs-1 text-primary"><i class="bi bi-activity"></i></div>
      <h1 class="h4 mb-0">TrazaPulso</h1>
      <div class="text-body-secondary">Trazabilidad de equipos · Sellcom</div>
    </div>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <?php if (g('salida') && !$error): ?><div class="alert alert-success py-2 small"><i class="bi bi-check-circle"></i> Sesión cerrada. Tu información quedó guardada<?= g('resp') && preg_match('/^\d{2}:\d{2}$/', g('resp')) ? ' (respaldo de las ' . e(g('resp')) . ')' : '' ?>.</div><?php endif; ?>
    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Usuario</label>
        <input class="form-control" name="usuario" value="<?= e(p('usuario', '')) ?>" required autofocus autocomplete="username">
      </div>
      <div class="mb-3">
        <label class="form-label">Contraseña</label>
        <input class="form-control" type="password" name="password" required autocomplete="current-password">
      </div>
      <button class="btn btn-primary w-100">Entrar</button>
    </form>
  </div>
</div>
</body>
</html>
