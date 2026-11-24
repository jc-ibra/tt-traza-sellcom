<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/reportes.php';
require_login();
// Plantilla vacía para capturar o actualizar equipos desde Excel
enviar_xlsx('Plantilla_TrazaPulso', 'CAPTURA',
    ['ID TrazaPulso', 'c_Serie', 'c_Inventario', 'c_Folio', 'c_Equipo', 'ETAPA', 'CONCEPTOS', 'UBICACIÓN', 'SUCURSAL',
     'PAQUETERÍA', 'GUÍA', 'FECHA RECEPCIÓN', 'SEGUIMIENTO CON', 'CORDINADOR', 'EXTRAVIADOS', 'AÑO', 'COMENTARIO DEL MOVIMIENTO'],
    [], [14, 18, 14, 16, 24, 26, 30, 28, 20, 14, 24, 16, 20, 18, 12, 8, 50]);
