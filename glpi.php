<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/glpi_api.php';
require_login();
// "Actualizar GLPI" (sincronizar) lo pueden usar editores; configurar la conexión solo el administrador
if (!(is_post() && p('accion') === 'sincronizar' && puede('editor'))) require_rol('admin');

$resPrueba = null;
$resSync = null;

if (is_post()) {
    csrf_check();
    $accion = p('accion');
    try {
        if ($accion === 'guardar') {
            $url = glpi_normalizar_url((string)p('url'));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) { $_SESSION['glpi_url_borrador'] = (string)p('url'); throw new RuntimeException('La dirección no parece válida: ' . p('url')); }
            $nuevos = ['GLPI_URL' => $url, 'GLPI_VERIFY_SSL' => p('verify_ssl') ? 'true' : 'false'];
            // los tokens solo se reemplazan si se escribió algo (el campo vacío conserva el actual)
            $inApp = trim((string)($_POST['app_token'] ?? ''));
            $inUser = trim((string)($_POST['user_token'] ?? ''));
            if ($inApp !== '' || $inUser !== '') {
                // se combinan con los actuales para poder detectar si se pegaron cruzados o con etiqueta
                [$a2, $u2] = glpi_limpiar_tokens($inApp !== '' ? $inApp : (string)(cfg('glpi')['app_token'] ?? ''), $inUser !== '' ? $inUser : (string)(cfg('glpi')['user_token'] ?? ''));
                $nuevos['GLPI_APP_TOKEN'] = $a2;
                $nuevos['GLPI_USER_TOKEN'] = $u2;
            }
            if (p('borrar_tokens')) { $nuevos['GLPI_APP_TOKEN'] = ''; $nuevos['GLPI_USER_TOKEN'] = ''; }
            env_guardar($nuevos);
            unset($_SESSION['glpi_url_borrador']);
            flash('success', 'Datos de conexión guardados en el archivo .env' . ($url ? ' · dirección: ' . $url : '') . '. Ahora da clic en "Probar conexión".');
            redirect('glpi.php');
        }
        if ($accion === 'codigos') {
            foreach ((array)($_POST['codigo'] ?? []) as $id => $cod) {
                $cod = trim((string)$cod);
                q('UPDATE estados_glpi SET codigo_glpi = ? WHERE id = ?', [$cod === '' ? null : (int)$cod, (int)$id]);
            }
            foreach ((array)($_POST['categoria'] ?? []) as $id => $cat) {
                q('UPDATE estados_glpi SET categoria_tp = ? WHERE id = ?', [trim((string)$cat) ?: null, (int)$id]);
            }
            flash('success', 'Equivalencia de códigos actualizada.');
            redirect('glpi.php');
        }
        if ($accion === 'probar') {
            $api = GlpiApi::desdeConfig();
            $api->iniciarSesion();
            $resPrueba = $api->quienSoy();
            $ejemplo = q("SELECT folio_glpi FROM equipos WHERE folio_glpi REGEXP '^[0-9]+$' ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
            $resPrueba['tickets'] = $ejemplo ? $api->estadosTickets(array_map('intval', $ejemplo)) : [];
            $resPrueba['folios'] = $ejemplo;
            $api->cerrarSesion();
        }
        if ($accion === 'sincronizar') {
            $uno = (int)p('equipo_id');
            $resSync = glpi_sincronizar(uid(), $uno ? [$uno] : null);
            if (p('volver')) {   // botón del menú / tablero / ficha: avisar y regresar a la misma pantalla
                if ($resSync['error']) flash('danger', 'GLPI: ' . $resSync['error']);
                elseif ($uno) flash($resSync['no_encontrados'] ? 'warning' : 'success', $resSync['no_encontrados'] ? 'El ticket ' . implode(', ', $resSync['no_encontrados']) . ' no se encontró en GLPI (o sin permiso).' : ($resSync['desconocidos'] ? 'GLPI devolvió un código de estado sin equivalencia: ' . implode(', ', array_keys($resSync['desconocidos'])) . ' (asígnalo en Conexión GLPI).' : ($resSync['actualizados'] ? 'Estado actualizado desde GLPI.' : 'GLPI: el estado no cambió.')));
                else flash('success', sprintf('GLPI actualizado: %d tickets consultados, %d equipos cambiaron, %d igual%s%s.', $resSync['consultados'], $resSync['actualizados'], $resSync['sin_cambios'],
                    $resSync['no_encontrados'] ? ', ' . count($resSync['no_encontrados']) . ' no encontrados' : '', $resSync['desconocidos'] ? ', ' . array_sum($resSync['desconocidos']) . ' con código sin equivalencia' : ''));
                redirect(glpi_volver(p('volver')));
            }
            if ($resSync['error']) flash('danger', 'La sincronización no terminó: ' . $resSync['error']);
            else flash('success', sprintf('Sincronización terminada: %d tickets consultados, %d equipos actualizados, %d sin cambios.', $resSync['consultados'], $resSync['actualizados'], $resSync['sin_cambios']));
        }
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
        if ($accion !== 'probar') redirect('glpi.php');
    }
}

$c = cfg('glpi');
$configurado = !empty($c['url']) && !empty($c['user_token']);
$estados = q('SELECT * FROM estados_glpi ORDER BY orden')->fetchAll();
$hist = q('SELECT s.*, u.nombre AS usuario FROM glpi_sync s LEFT JOIN usuarios u ON u.id = s.usuario_id ORDER BY s.id DESC LIMIT 15')->fetchAll();
$conFolio = (int)q("SELECT COUNT(*) FROM equipos WHERE folio_glpi REGEXP '^[0-9]+$'")->fetchColumn();

$titulo = 'Conexión GLPI';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
  <div><h1 class="rs-titulo" style="font-size:1.9rem">Conexión con GLPI</h1>
    <div class="rs-sub">Consulta en GLPI el estado de cada ticket (FOLIO GLPI) y lo guarda como <b>Estado en GLPI (real)</b> para la conciliación.</div></div>
  <span class="badge <?= $configurado ? 'text-bg-success' : 'text-bg-secondary' ?> fs-6"><?= $configurado ? 'Configurado' : 'Sin configurar' ?></span>
</div>

<div class="row g-3">
  <div class="col-xl-5">
    <form method="post" class="card rs-card mb-3" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="accion" value="guardar">
      <div class="card-header"><span><i class="bi bi-key"></i> Credenciales (archivo .env)</span></div>
      <div class="card-body">
        <div class="mb-2"><label class="form-label">Dirección de GLPI</label>
          <input name="url" class="form-control form-control-sm" value="<?= e($_SESSION['glpi_url_borrador'] ?? ($c['url'] ?? '')) ?>" placeholder="Ejemplo: https://glpi.miempresa.com"></div>
        <div class="small text-body-secondary mb-2" style="margin-top:-.3rem">Pega la dirección con la que entras a GLPI (puede ser la de cualquier pantalla de GLPI; el sistema toma solo la base).</div>
        <div class="mb-2"><label class="form-label">App-Token <span class="text-body-secondary fw-normal">· actual: <?= e(env_enmascarar($c['app_token'] ?? null)) ?></span></label>
          <input name="app_token" type="password" class="form-control form-control-sm mono" placeholder="Escribe para reemplazarlo (vacío = conservar)" autocomplete="new-password"></div>
        <div class="mb-2"><label class="form-label">User-Token <span class="text-body-secondary fw-normal">· actual: <?= e(env_enmascarar($c['user_token'] ?? null)) ?></span></label>
          <input name="user_token" type="password" class="form-control form-control-sm mono" placeholder="Escribe para reemplazarlo (vacío = conservar)" autocomplete="new-password"></div>
        <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="verify_ssl" id="vs" value="1" <?= !empty($c['verify_ssl']) ? 'checked' : '' ?>><label class="form-check-label small" for="vs">Validar certificado HTTPS (desmárcalo solo si su GLPI usa certificado interno)</label></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="borrar_tokens" id="bt" value="1"><label class="form-check-label small text-danger" for="bt">Borrar los tokens guardados</label></div>
        <button class="btn btn-tp btn-sm"><i class="bi bi-save"></i> Guardar en .env</button>
        <div class="small text-body-secondary mt-3">
          <i class="bi bi-shield-lock"></i> Se guardan en <code><?= e(realpath(dirname(ENV_ARCHIVO)) . DIRECTORY_SEPARATOR) ?>.env</code>, archivo bloqueado para el navegador y excluido de los respaldos. Los tokens nunca se muestran completos ni se escriben en la bitácora.
        </div>
      </div>
    </form>

    <div class="card rs-card mb-3">
      <div class="card-header"><span><i class="bi bi-question-circle"></i> ¿Dónde obtengo los tokens?</span></div>
      <div class="card-body small">
        <ol class="mb-0 ps-3">
          <li><b>Habilitar la API</b> (administrador de GLPI): <i>Configuración → General → API</i> → activar "API REST" y "inicio de sesión con token externo".</li>
          <li><b>App-Token</b>: en esa misma pantalla, <i>Agregar cliente de API</i> (puede limitarse a la IP de este servidor) → copiar el <b>Token de aplicación</b>.</li>
          <li><b>User-Token</b>: entrar a GLPI con el usuario de integración (de preferencia uno de solo lectura de tickets) → <i>Mi configuración → Claves de acceso remoto → Token de API</i> → regenerar y copiar.</li>
        </ol>
      </div>
    </div>
  </div>

  <div class="col-xl-7">
    <div class="card rs-card mb-3">
      <div class="card-header"><span><i class="bi bi-plug"></i> Probar y sincronizar</span><span class="extra"><?= num($conFolio) ?> equipos con FOLIO GLPI</span></div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="probar"><button class="btn btn-tp-out" <?= $configurado ? '' : 'disabled' ?>><i class="bi bi-wifi"></i> Probar conexión</button></form>
          <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="sincronizar"><button class="btn btn-tp" <?= $configurado && $conFolio ? '' : 'disabled' ?> data-confirm="¿Consultar en GLPI el estado de los <?= num($conFolio) ?> tickets?"><i class="bi bi-arrow-repeat"></i> Sincronizar estados ahora</button></form>
        </div>
        <?php if (!$configurado): ?><div class="alert alert-info small py-2 mb-0">Captura la dirección y los tokens a la izquierda para habilitar la conexión.</div><?php endif; ?>
        <?php if ($configurado && !$conFolio): ?><div class="alert alert-warning small py-2 mb-0">Ningún equipo tiene FOLIO GLPI todavía. Aplica la sábana con esa columna en <a href="sabana.php">Actualizar sábana</a>.</div><?php endif; ?>
        <?php if ($resPrueba): ?>
          <div class="alert alert-success small py-2 mb-0"><b><i class="bi bi-check-circle"></i> Conexión correcta.</b> Usuario: <b><?= e($resPrueba['usuario']) ?></b> <?= e($resPrueba['nombre']) ?> · Perfil: <?= e($resPrueba['perfil']) ?> · Entidad: <?= e($resPrueba['entidad']) ?>
            <?php if ($resPrueba['folios']): ?><br>Prueba con folios <?= e(implode(', ', $resPrueba['folios'])) ?>:
              <?php foreach ($resPrueba['folios'] as $fo): $t = $resPrueba['tickets'][(int)$fo] ?? null; ?>
                <span class="me-2">#<?= e($fo) ?> → <?= $t ? 'código ' . (int)$t['status'] . ' (' . e(q('SELECT nombre FROM estados_glpi WHERE codigo_glpi = ?', [$t['status']])->fetchColumn() ?: 'sin equivalencia') . ')' : '<span class="text-danger">no encontrado o sin permiso</span>' ?></span>
              <?php endforeach; ?>
            <?php endif; ?></div>
        <?php endif; ?>
        <?php if ($resSync && !$resSync['error']): ?>
          <div class="small mt-2">
            <?php if ($resSync['no_encontrados']): ?><div class="text-danger">Tickets no encontrados en GLPI (o sin permiso): <?= e(implode(', ', array_slice($resSync['no_encontrados'], 0, 40))) ?><?= count($resSync['no_encontrados']) > 40 ? '…' : '' ?></div><?php endif; ?>
            <?php if ($resSync['desconocidos']): ?><div class="text-warning-emphasis">Códigos de estado de GLPI sin equivalencia (asígnalos abajo): <?php foreach ($resSync['desconocidos'] as $cod => $n) echo 'código ' . (int)$cod . ' (' . (int)$n . ' tickets) · '; ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <form method="post" class="card rs-card mb-3">
      <?= csrf_field() ?><input type="hidden" name="accion" value="codigos">
      <div class="card-header" id="conversion"><span><i class="bi bi-arrow-left-right"></i> Conversión GLPI → TrazaPulso</span><button class="btn btn-sm btn-tp-out">Guardar</button></div>
      <table class="tbl"><colgroup><col style="width:170px"><col style="width:100px"><col style="width:230px"><col></colgroup>
        <thead><tr><th>Estado GLPI</th><th class="c">Código</th><th>Categoría TrazaPulso</th><th>Regla</th></tr></thead>
        <tbody>
        <?php foreach ($estados as $es): ?>
          <tr><td class="nom"><?= e($es['nombre']) ?></td>
            <td class="c"><input name="codigo[<?= (int)$es['id'] ?>]" value="<?= e($es['codigo_glpi']) ?>" class="form-control form-control-sm text-center" style="width:70px;margin:auto" inputmode="numeric"></td>
            <td><select name="categoria[<?= (int)$es['id'] ?>]" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach (array_merge(array_values(catalogo('etapas', true)), ['ATENDIENDOSE', 'SIN EVIDENCIA']) as $cat): ?><option <?= mb_strtoupper((string)$es['categoria_tp']) === mb_strtoupper($cat) ? 'selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?>
            </select></td>
            <td class="small text-body-secondary" style="white-space:normal"><?= e($es['regla']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="card-body small text-body-secondary py-2">GLPI estándar usa: 1 Nuevo · 2 En curso (asignada) · 3 En curso (planificada) · 4 En espera · 5 Resuelto · 6 Cerrado. Si su GLPI tiene estados personalizados (Aprobación, Atendiéndose…), "Probar conexión" o la sincronización te mostrarán qué código traen para que los asignes aquí.</div>
    </form>

    <div class="card rs-card">
      <div class="card-header"><span><i class="bi bi-clock-history"></i> Sincronizaciones</span></div>
      <div class="tbl-scroll"><table class="tbl"><colgroup><col style="width:140px"><col><col style="width:80px"><col style="width:90px"><col style="width:80px"><col style="width:90px"><col style="width:90px"></colgroup>
        <thead><tr><th>Fecha</th><th>Usuario / resultado</th><th class="n">Tickets</th><th class="n">Actualiz.</th><th class="n">Igual</th><th class="n">No encontr.</th><th class="n">Sin equiv.</th></tr></thead>
        <tbody>
        <?php if (!$hist): ?><tr><td colspan="7" class="text-body-secondary">Aún no se ha sincronizado.</td></tr><?php endif; ?>
        <?php foreach ($hist as $h): ?>
          <tr><td><?= fecha($h['fecha']) ?></td><td class="small" title="<?= e($h['error'] ?? '') ?>"><?= e($h['usuario'] ?? 'Programado') ?><?= $h['error'] ? ' · <span class="text-danger">' . e(mb_strimwidth($h['error'], 0, 60, '…')) . '</span>' : ' · <span class="text-success">OK</span> · ' . e($h['segundos']) . ' s' ?></td>
            <td class="n"><?= num($h['consultados']) ?></td><td class="n txt-o"><?= num($h['actualizados']) ?></td><td class="n"><?= num($h['sin_cambios']) ?></td><td class="n txt-x"><?= num($h['no_encontrados']) ?></td><td class="n"><?= num($h['desconocidos']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
