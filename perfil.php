<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (is_post()) {
    csrf_check();
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    $conf = (string)($_POST['confirmar'] ?? '');
    if (!password_verify($actual, user()['password_hash'])) {
        flash('danger', 'La contraseña actual no es correcta.');
    } elseif (mb_strlen($nueva) < 8) {
        flash('danger', 'La nueva contraseña debe tener al menos 8 caracteres.');
    } elseif ($nueva !== $conf) {
        flash('danger', 'La confirmación no coincide.');
    } elseif ($nueva === $actual) {
        flash('danger', 'La nueva contraseña debe ser distinta de la actual.');
    } else {
        q('UPDATE usuarios SET password_hash = ?, debe_cambiar = 0 WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), uid()]);
        session_regenerate_id(true);
        flash('success', 'Contraseña actualizada.');
        redirect('index.php');
    }
    redirect('perfil.php');
}

$titulo = 'Mi contraseña';
require __DIR__ . '/inc/header.php';
?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-key"></i> Cambiar contraseña</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label">Contraseña actual</label><input type="password" name="actual" class="form-control" required autocomplete="current-password"></div>
          <div class="mb-3"><label class="form-label">Nueva contraseña (mín. 8)</label><input type="password" name="nueva" class="form-control" required minlength="8" autocomplete="new-password"></div>
          <div class="mb-3"><label class="form-label">Confirmar nueva</label><input type="password" name="confirmar" class="form-control" required minlength="8" autocomplete="new-password"></div>
          <button class="btn btn-primary w-100">Guardar</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
