<?php
require __DIR__ . '/inc/bootstrap.php';
require_rol('admin');

function pass_temporal(): string
{
    $c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $c[random_int(0, strlen($c) - 1)];
    return $s;
}

if (is_post()) {
    csrf_check();
    $accion = p('accion');
    $id = (int)p('id');
    try {
        if ($accion === 'crear') {
            $usr = strtolower((string)p('usuario'));
            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $usr)) throw new RuntimeException('Usuario inválido (3–60 caracteres: letras, números, punto, guion).');
            if (!p('nombre')) throw new RuntimeException('Escribe el nombre.');
            if (!isset(ROLES[p('rol')])) throw new RuntimeException('Rol inválido.');
            if (q('SELECT 1 FROM usuarios WHERE usuario = ?', [$usr])->fetchColumn()) throw new RuntimeException('Ese usuario ya existe.');
            $pw = pass_temporal();
            q('INSERT INTO usuarios (nombre, usuario, password_hash, rol, debe_cambiar) VALUES (?,?,?,?,1)', [p('nombre'), $usr, password_hash($pw, PASSWORD_DEFAULT), p('rol')]);
            flash('success', "Usuario \"$usr\" creado. Contraseña temporal: $pw  (se le pedirá cambiarla al entrar).");
        } elseif ($accion === 'editar') {
            if (!isset(ROLES[p('rol')])) throw new RuntimeException('Rol inválido.');
            if ($id === uid() && (p('rol') !== 'admin' || !p('activo'))) throw new RuntimeException('No puedes quitarte el rol de administrador ni desactivarte a ti mismo.');
            q('UPDATE usuarios SET nombre = ?, rol = ?, activo = ? WHERE id = ?', [p('nombre'), p('rol'), p('activo') ? 1 : 0, $id]);
            flash('success', 'Usuario actualizado.');
        } elseif ($accion === 'reset') {
            $pw = pass_temporal();
            q('UPDATE usuarios SET password_hash = ?, debe_cambiar = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            $u = q('SELECT usuario FROM usuarios WHERE id = ?', [$id])->fetchColumn();
            flash('success', "Nueva contraseña temporal para \"$u\": $pw");
        }
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('usuarios.php');
}

$usuarios = q('SELECT u.*, (SELECT COUNT(*) FROM movimientos m WHERE m.usuario_id = u.id) AS movs FROM usuarios u ORDER BY u.activo DESC, u.nombre')->fetchAll();

$titulo = 'Usuarios';
require __DIR__ . '/inc/header.php';
?>
<h1 class="h4 mb-3">Usuarios</h1>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table table-sm table-tp mb-0">
        <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Activo</th><th>Último acceso</th><th class="text-end">Movs.</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
          <tr class="<?= $u['activo'] ? '' : 'text-body-tertiary' ?>">
            <?php $f = 'fu' . (int)$u['id']; ?>
            <td><input form="<?= $f ?>" name="nombre" value="<?= e($u['nombre']) ?>" class="form-control form-control-sm" required></td>
            <td class="mono small"><?= e($u['usuario']) ?><?= $u['debe_cambiar'] ? ' <span class="badge text-bg-warning" title="Debe cambiar contraseña">temp</span>' : '' ?></td>
            <td><select form="<?= $f ?>" name="rol" class="form-select form-select-sm"><?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $u['rol'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
            <td><input form="<?= $f ?>" type="checkbox" class="form-check-input" name="activo" value="1" <?= $u['activo'] ? 'checked' : '' ?>></td>
            <td class="small text-nowrap"><?= fecha($u['ultimo_acceso']) ?: '—' ?></td>
            <td class="text-end small"><?= num($u['movs']) ?></td>
            <td class="text-nowrap"><form method="post" id="<?= $f ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn-sm btn-outline-primary" title="Guardar"><i class="bi bi-save"></i></button></form>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Restablecer contraseña" data-confirm="¿Generar una nueva contraseña temporal?"><i class="bi bi-key"></i></button></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-4">
    <form method="post" class="card card-body">
      <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
      <h2 class="h6">Nuevo usuario</h2>
      <div class="mb-2"><label class="form-label">Nombre completo</label><input name="nombre" class="form-control form-control-sm" required></div>
      <div class="mb-2"><label class="form-label">Usuario</label><input name="usuario" class="form-control form-control-sm" required placeholder="ej. lhernandez"></div>
      <div class="mb-3"><label class="form-label">Rol</label><select name="rol" class="form-select form-select-sm"><?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'editor' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary btn-sm">Crear (genera contraseña temporal)</button>
    </form>
    <div class="card card-body mt-3 small text-body-secondary">
      <b>Administrador:</b> todo, incluidos usuarios, importación y catálogos.<br>
      <b>Editor:</b> registra movimientos, evidencias y acuerdos.<br>
      <b>Solo consulta:</b> ve tablero, equipos e historial, y exporta.
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
