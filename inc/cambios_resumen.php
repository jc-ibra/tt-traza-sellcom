<?php
// Espera $m (fila de movimientos). Imprime resumen compacto de cambios.
$__o = ORIGENES[$m['origen']] ?? [$m['origen'], 'secondary'];
echo '<span class="badge text-bg-' . $__o[1] . ' me-1">' . e($__o[0]) . '</span>';
$__c = $m['cambios'] ? json_decode($m['cambios'], true) : [];
$__partes = [];
foreach ($__c ?: [] as $__x) {
    $__lbl = CAMPOS_EQUIPO[$__x['campo']][0] ?? $__x['campo'];
    $__partes[] = '<b>' . e($__lbl) . ':</b> ' . ($__x['antes'] !== null ? '<span class="text-danger-emphasis">' . e(mb_strimwidth($__x['antes'], 0, 40, '…')) . '</span> → ' : '')
        . ($__x['despues'] !== null ? '<span class="text-success-emphasis">' . e(mb_strimwidth((string)$__x['despues'], 0, 40, '…')) . '</span>' : '<i class="text-body-secondary">(vacío)</i>');
}
echo implode(' · ', $__partes);
if ($m['comentario']) echo ($__partes ? '<br>' : '') . '<i class="bi bi-chat-left-text text-body-secondary"></i> ' . e(mb_strimwidth($m['comentario'], 0, 160, '…'));
