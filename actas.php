<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

function folio_acta(int $id): string
{
    return 'ACTA-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

$paso = is_post() ? p('paso') : null;

if ($paso === 'crear') {
    csrf_check();
    require_rol('editor');
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)p('ids_csv'))))));
    $tipo = p('tipo');
    if (!$ids || !isset(TIPOS_ACTA[$tipo]) || !p('fecha')) {
        flash('danger', 'Faltan datos del acta (tipo, fecha o equipos).');
        redirect('actas.php');
    }
    $etapaId = p('etapa_id') ? (int)p('etapa_id') : null;
    try {
        db()->beginTransaction();
        q('INSERT INTO actas (tipo, fecha, lugar, entrega, recibe, observaciones, etapa_id, created_by) VALUES (?,?,?,?,?,?,?,?)',
            [$tipo, p('fecha'), p('lugar') ?: null, p('entrega') ?: null, p('recibe') ?: null, p('observaciones') ?: null, $etapaId, uid()]);
        $aid = (int)db()->lastInsertId();
        $txt = 'Incluido en ' . folio_acta($aid) . ' (' . TIPOS_ACTA[$tipo] . ', ' . fecha(p('fecha'), false) . ')'
            . (p('recibe') ? '. Recibe: ' . p('recibe') : '');
        foreach ($ids as $eid) {
            q('INSERT IGNORE INTO acta_equipo (acta_id, equipo_id) VALUES (?,?)', [$aid, $eid]);
            $d = $etapaId ? ['etapa_id' => $etapaId] : [];
            if ($tipo === 'recepcion' && p('fecha')) $d['fecha_recepcion'] = p('fecha');
            $res = equipo_guardar($eid, $d, 'acta', $txt, uid());
            if (!$res['mov']) movimiento_insert($eid, 'acta', [], $txt, uid());
        }
        db()->commit();
        flash('success', folio_acta($aid) . ' generada con ' . count($ids) . ' equipos y registrada en su bitácora.');
        redirect('acta.php?id=' . $aid);
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', 'No se generó el acta: ' . $ex->getMessage());
        redirect('actas.php');
    }
}

if ($paso === 'nueva') {
    csrf_check();
    require_rol('editor');
    $ids = [];
    if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
    } elseif (p('filtro') !== null) {
        parse_str((string)p('filtro'), $f);
        [$where, $params] = equipos_filtro($f);
        $ids = q("SELECT e.id FROM equipos e WHERE $where", $params)->fetchAll(PDO::FETCH_COLUMN);
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) {
        flash('warning', 'Selecciona equipos para el acta.');
        redirect('equipos.php');
    }
    $muestra = q(SQL_EQUIPO_SELECT . ' WHERE e.id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY t.nombre, e.serie')->fetchAll();
    $tipos = [];
    foreach ($muestra as $m) $tipos[$m['tipo'] ?? 'SIN TIPO'] = ($tipos[$m['tipo'] ?? 'SIN TIPO'] ?? 0) + 1;

    $titulo = 'Nueva acta';
    require __DIR__ . '/inc/header.php';
    ?>
    <nav class="small mb-2"><a href="actas.php"><i class="bi bi-arrow-left"></i> Actas</a></nav>
    <h1 class="h4">Nueva acta con <?= num(count($ids)) ?> equipos</h1>
    <div class="row g-3">
      <div class="col-lg-5">
        <form method="post" class="card card-body">
          <?= csrf_field() ?><input type="hidden" name="paso" value="crear"><input type="hidden" name="ids_csv" value="<?= e(implode(',', $ids)) ?>">
          <div class="row g-2">
            <div class="col-7"><label class="form-label">Tipo de acta</label><select name="tipo" class="form-select form-select-sm" id="tipo-acta">
              <?php foreach (TIPOS_ACTA as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-5"><label class="form-label">Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm" required></div>
            <div class="col-12"><label class="form-label">Lugar</label><input name="lugar" class="form-control form-control-sm" placeholder="Ej. Bodega Gavilanes, CDMX"></div>
            <div class="col-6"><label class="form-label">Entrega</label><input name="entrega" class="form-control form-control-sm" placeholder="Nombre y empresa"></div>
            <div class="col-6"><label class="form-label">Recibe</label><input name="recibe" class="form-control form-control-sm" placeholder="Nombre y empresa"></div>
            <div class="col-12"><label class="form-label">Observaciones</label><textarea name="observaciones" rows="3" class="form-control form-control-sm"></textarea></div>
            <div class="col-12"><label class="form-label">Cambiar la etapa de los equipos a</label>
              <select name="etapa_id" class="form-select form-select-sm" id="etapa-acta"><?= opciones('etapas', null, '— No cambiar la etapa —') ?></select>
              <div class="small text-body-secondary mt-1">En todos los casos el acta queda registrada en la bitácora de cada equipo.</div></div>
          </div>
          <button class="btn btn-primary mt-3"><i class="bi bi-file-earmark-check"></i> Generar acta</button>
        </form>
      </div>
      <div class="col-lg-7">
        <div class="card mb-3"><div class="card-body small"><b>Resumen:</b> <?php foreach ($tipos as $t => $n) echo e($t) . ': <b>' . $n . '</b> · '; ?></div></div>
        <div class="card"><div class="table-responsive tabla-scroll"><table class="table table-sm table-tp mb-0">
          <thead><tr><th>Serie</th><th>Inventario</th><th>Tipo</th><th>Etapa</th><th>Ubicación</th></tr></thead>
          <tbody><?php foreach ($muestra as $m): ?><tr><td class="mono"><?= e($m['serie']) ?></td><td class="mono small"><?= e($m['inventario']) ?></td><td class="small"><?= e($m['tipo']) ?></td><td><?= etapa_badge($m['etapa'], $m['etapa_color']) ?></td><td class="small"><?= e($m['ubicacion']) ?></td></tr><?php endforeach; ?></tbody>
        </table></div></div>
      </div>
    </div>
    <script>
      // sugerencia de etapa según el tipo de acta
      (() => {
        const t = document.getElementById('tipo-acta'), s = document.getElementById('etapa-acta');
        const sug = {destruccion: 'DESTRUIDO / CERRADO', recepcion: 'EN ALMACEN CDMX', entrega: ''};
        const aplicar = () => { const n = sug[t.value]; for (const o of s.options) o.selected = n ? o.text === n : o.value === ''; };
        t.addEventListener('change', aplicar); aplicar();
      })();
    </script>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

$actas = q('SELECT a.*, u.nombre AS creador, et.nombre AS etapa, (SELECT COUNT(*) FROM acta_equipo ae WHERE ae.acta_id = a.id) AS equipos
    FROM actas a LEFT JOIN usuarios u ON u.id = a.created_by LEFT JOIN etapas et ON et.id = a.etapa_id ORDER BY a.id DESC')->fetchAll();
$titulo = 'Actas';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><a href="reportes.php" class="small"><i class="bi bi-arrow-left"></i> Reportes</a><h1 class="h4 mb-0">Actas de entrega, recepción y destrucción</h1></div>
</div>
<div class="alert alert-info small"><i class="bi bi-info-circle"></i> Para generar un acta: ve a <a href="equipos.php">Equipos</a>, filtra o selecciona los equipos y usa el botón <b>Generar acta</b>. También puedes hacerlo desde un acuerdo.</div>
<div class="card"><div class="table-responsive">
  <table class="table table-sm table-hover table-tp mb-0">
    <thead><tr><th>Folio</th><th>Tipo</th><th>Fecha</th><th>Lugar</th><th>Entrega → Recibe</th><th class="text-end">Equipos</th><th>Etapa aplicada</th><th>Creó</th></tr></thead>
    <tbody>
    <?php if (!$actas): ?><tr><td colspan="8" class="text-center text-body-secondary py-4">Aún no hay actas.</td></tr><?php endif; ?>
    <?php foreach ($actas as $a): ?>
      <tr><td><a href="acta.php?id=<?= (int)$a['id'] ?>" class="fw-semibold"><?= folio_acta((int)$a['id']) ?></a></td><td><?= e(TIPOS_ACTA[$a['tipo']] ?? $a['tipo']) ?></td><td><?= fecha($a['fecha'], false) ?></td><td class="small"><?= e($a['lugar']) ?></td>
        <td class="small"><?= e($a['entrega'] ?: '—') ?> → <?= e($a['recibe'] ?: '—') ?></td><td class="text-end"><?= num($a['equipos']) ?></td><td class="small"><?= e($a['etapa'] ?? '—') ?></td><td class="small"><?= e($a['creador']) ?> · <?= fecha($a['created_at'], false) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/inc/footer.php'; ?>
