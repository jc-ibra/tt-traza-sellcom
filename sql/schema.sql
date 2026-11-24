-- TrazaPulso Sellcom - Esquema de base de datos (MariaDB / MySQL)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  usuario VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol VARCHAR(20) NOT NULL DEFAULT 'consulta',      -- admin | editor | consulta
  activo TINYINT(1) NOT NULL DEFAULT 1,
  debe_cambiar TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acceso DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogos (la intercalación _ci hace que mayúsculas/acentos no generen duplicados)
CREATE TABLE IF NOT EXISTS tipos_equipo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proyectos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ubicaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conceptos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  serie VARCHAR(120) NULL,
  inventario VARCHAR(120) NULL,
  folio VARCHAR(120) NULL,
  tipo_id INT NULL,
  proyecto_id INT NULL,
  concepto_id INT NULL,
  ubicacion_id INT NULL,
  seguimiento_id INT NULL,
  coordinador_id INT NULL,
  extraviado TINYINT(1) NOT NULL DEFAULT 0,
  anio SMALLINT NULL,
  comentario TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_serie (serie),
  INDEX ix_inventario (inventario),
  INDEX ix_folio (folio),
  CONSTRAINT fk_eq_tipo FOREIGN KEY (tipo_id) REFERENCES tipos_equipo(id),
  CONSTRAINT fk_eq_proy FOREIGN KEY (proyecto_id) REFERENCES proyectos(id),
  CONSTRAINT fk_eq_conc FOREIGN KEY (concepto_id) REFERENCES conceptos(id),
  CONSTRAINT fk_eq_ubic FOREIGN KEY (ubicacion_id) REFERENCES ubicaciones(id),
  CONSTRAINT fk_eq_seg FOREIGN KEY (seguimiento_id) REFERENCES personas(id),
  CONSTRAINT fk_eq_coord FOREIGN KEY (coordinador_id) REFERENCES personas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora: cada cambio queda registrado (corazón de la trazabilidad)
CREATE TABLE IF NOT EXISTS movimientos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  equipo_id INT NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usuario_id INT NULL,
  origen VARCHAR(20) NOT NULL,          -- alta | edicion | masivo | importacion | comentario | acuerdo
  cambios LONGTEXT NULL,                -- JSON [{campo, antes, despues}]
  comentario TEXT NULL,
  INDEX ix_mov_equipo (equipo_id, fecha),
  INDEX ix_mov_fecha (fecha),
  CONSTRAINT fk_mov_eq FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evidencias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  equipo_id INT NOT NULL,
  movimiento_id INT NULL,
  archivo VARCHAR(255) NOT NULL,        -- nombre interno en /uploads
  nombre_original VARCHAR(255) NOT NULL,
  tamano INT NOT NULL DEFAULT 0,
  descripcion VARCHAR(255) NULL,
  usuario_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_ev_equipo (equipo_id),
  CONSTRAINT fk_ev_eq FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
  CONSTRAINT fk_ev_mov FOREIGN KEY (movimiento_id) REFERENCES movimientos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acuerdos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  descripcion TEXT NOT NULL,
  fecha_compromiso DATE NULL,
  responsable_id INT NULL,
  estatus VARCHAR(20) NOT NULL DEFAULT 'abierto',   -- abierto | cumplido | cancelado
  comentario_cierre TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrado_at DATETIME NULL,
  INDEX ix_ac_est (estatus, fecha_compromiso),
  CONSTRAINT fk_ac_resp FOREIGN KEY (responsable_id) REFERENCES personas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acuerdo_equipo (
  acuerdo_id INT NOT NULL,
  equipo_id INT NOT NULL,
  PRIMARY KEY (acuerdo_id, equipo_id),
  INDEX ix_ae_eq (equipo_id),
  CONSTRAINT fk_ae_ac FOREIGN KEY (acuerdo_id) REFERENCES acuerdos(id) ON DELETE CASCADE,
  CONSTRAINT fk_ae_eq FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- v2: etapas (flujo físico), paqueterías, regiones, actas
-- =====================================================================
CREATE TABLE IF NOT EXISTS etapas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  orden INT NOT NULL DEFAULT 50,
  es_final TINYINT(1) NOT NULL DEFAULT 0,          -- ya no requiere seguimiento
  marca_extraviado TINYINT(1) NOT NULL DEFAULT 0,  -- al entrar marca el equipo como extraviado
  registra_recepcion TINYINT(1) NOT NULL DEFAULT 0,-- al entrar pone fecha de recepción si no tiene
  color VARCHAR(20) NOT NULL DEFAULT 'secondary',
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO etapas (nombre, orden, es_final, marca_extraviado, registra_recepcion, color) VALUES
 ('POR LOCALIZAR', 10, 0, 0, 0, 'secondary'),
 ('EN SUCURSAL / CON TECNICO', 20, 0, 0, 0, 'info'),
 ('EN TRANSITO', 30, 0, 0, 0, 'primary'),
 ('EN FRONTERA / ADUANA', 40, 0, 0, 0, 'warning'),
 ('EN ALMACEN CDMX', 50, 0, 0, 1, 'success'),
 ('PARA DESTRUCCION', 60, 0, 0, 0, 'dark'),
 ('DESTRUIDO / CERRADO', 70, 1, 0, 0, 'light'),
 ('ENTREGADO AL BANCO', 80, 1, 0, 0, 'light'),
 ('EXTRAVIADO', 90, 0, 1, 0, 'danger');

CREATE TABLE IF NOT EXISTS paqueterias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO paqueterias (nombre) VALUES ('FEDEX'), ('DHL'), ('ESTAFETA'), ('TRES GUERRAS'), ('PAQUETEXPRESS'), ('REDPACK'), ('UPS'), ('TRANSPORTE PROPIO');

CREATE TABLE IF NOT EXISTS regiones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO regiones (nombre) VALUES ('NOROESTE'), ('NORTE'), ('NORESTE'), ('CENTRO');

ALTER TABLE ubicaciones ADD COLUMN IF NOT EXISTS region_id INT NULL;
ALTER TABLE ubicaciones ADD CONSTRAINT fk_ub_region FOREIGN KEY IF NOT EXISTS (region_id) REFERENCES regiones(id);

ALTER TABLE equipos
  ADD COLUMN IF NOT EXISTS etapa_id INT NULL AFTER concepto_id,
  ADD COLUMN IF NOT EXISTS fecha_etapa DATETIME NULL AFTER etapa_id,
  ADD COLUMN IF NOT EXISTS sucursal VARCHAR(150) NULL AFTER ubicacion_id,
  ADD COLUMN IF NOT EXISTS guia VARCHAR(255) NULL AFTER sucursal,
  ADD COLUMN IF NOT EXISTS paqueteria_id INT NULL AFTER guia,
  ADD COLUMN IF NOT EXISTS fecha_recepcion DATE NULL AFTER paqueteria_id;
ALTER TABLE equipos ADD CONSTRAINT fk_eq_etapa FOREIGN KEY IF NOT EXISTS (etapa_id) REFERENCES etapas(id);
ALTER TABLE equipos ADD CONSTRAINT fk_eq_paq FOREIGN KEY IF NOT EXISTS (paqueteria_id) REFERENCES paqueterias(id);
ALTER TABLE equipos ADD INDEX IF NOT EXISTS ix_guia (guia);

-- Actas de entrega / recepción / destrucción
CREATE TABLE IF NOT EXISTS actas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,              -- entrega | recepcion | destruccion
  fecha DATE NOT NULL,
  lugar VARCHAR(200) NULL,
  entrega VARCHAR(150) NULL,
  recibe VARCHAR(150) NULL,
  observaciones TEXT NULL,
  etapa_id INT NULL,                      -- etapa aplicada a los equipos (opcional)
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acta_equipo (
  acta_id INT NOT NULL,
  equipo_id INT NOT NULL,
  PRIMARY KEY (acta_id, equipo_id),
  CONSTRAINT fk_actae_acta FOREIGN KEY (acta_id) REFERENCES actas(id) ON DELETE CASCADE,
  CONSTRAINT fk_actae_eq FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- v3: historial de actualizaciones con la sábana
-- =====================================================================
CREATE TABLE IF NOT EXISTS sabanas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre_original VARCHAR(255) NOT NULL,
  archivo VARCHAR(255) NULL,          -- copia guardada en uploads/sabanas
  hoja VARCHAR(100) NULL,
  usuario_id INT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  filas INT NOT NULL DEFAULT 0,
  nuevos INT NOT NULL DEFAULT 0,
  actualizados INT NOT NULL DEFAULT 0,
  cambios_aplicados INT NOT NULL DEFAULT 0,
  cambios_rechazados INT NOT NULL DEFAULT 0,
  faltantes INT NOT NULL DEFAULT 0,
  comentario VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- v4: folio y estados GLPI / Pulsott
-- =====================================================================
CREATE TABLE IF NOT EXISTS estados_glpi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL UNIQUE,
  estado_pulsott VARCHAR(40) NULL,
  orden INT NOT NULL DEFAULT 50,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO estados_glpi (nombre, estado_pulsott, orden) VALUES
 ('Nuevo', 'PENDIENTE', 10),
 ('Aprobacion', 'PENDIENTE', 20),
 ('En curso (asignada)', 'REPROGRAMADO', 30),
 ('En curso (planificada)', 'PROGRAMADO', 40),
 ('En espera', 'PENDIENTE', 50),
 ('Atendiendose', 'EN PROCESO', 60),
 ('Resuelto', 'EJECUTADO', 70),
 ('Cerrado', 'EJECUTADO', 80),
 ('Resuelto + Cerrado', 'EJECUTADO', 90),
 ('No resuelto', 'EN PAUSA', 100),
 ('No cerrado', 'CANCELADO', 110);

ALTER TABLE etapas ADD COLUMN IF NOT EXISTS estado_glpi_id INT NULL;
ALTER TABLE etapas ADD CONSTRAINT fk_et_glpi FOREIGN KEY IF NOT EXISTS (estado_glpi_id) REFERENCES estados_glpi(id);
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'En curso (asignada)' SET e.estado_glpi_id = g.id WHERE e.nombre = 'POR LOCALIZAR' AND e.estado_glpi_id IS NULL;
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'En curso (asignada)' SET e.estado_glpi_id = g.id WHERE e.nombre = 'EN SUCURSAL / CON TECNICO' AND e.estado_glpi_id IS NULL;
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'En curso (planificada)' SET e.estado_glpi_id = g.id WHERE e.nombre = 'EN TRANSITO' AND e.estado_glpi_id IS NULL;
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'En espera' SET e.estado_glpi_id = g.id WHERE e.nombre = 'EN FRONTERA / ADUANA' AND e.estado_glpi_id IS NULL;
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'Resuelto' SET e.estado_glpi_id = g.id WHERE e.nombre = 'EN ALMACEN CDMX' AND e.estado_glpi_id IS NULL;
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'Aprobacion' SET e.estado_glpi_id = g.id WHERE e.nombre = 'PARA DESTRUCCION' AND e.estado_glpi_id IS NULL;
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'Resuelto + Cerrado' SET e.estado_glpi_id = g.id WHERE e.nombre = 'DESTRUIDO / CERRADO' AND e.estado_glpi_id IS NULL;
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'Cerrado' SET e.estado_glpi_id = g.id WHERE e.nombre = 'ENTREGADO AL BANCO' AND e.estado_glpi_id IS NULL;
UPDATE etapas e JOIN estados_glpi g ON g.nombre = 'No resuelto' SET e.estado_glpi_id = g.id WHERE e.nombre = 'EXTRAVIADO' AND e.estado_glpi_id IS NULL;

ALTER TABLE equipos
  ADD COLUMN IF NOT EXISTS folio_glpi VARCHAR(50) NULL AFTER folio,
  ADD COLUMN IF NOT EXISTS estado_glpi_manual_id INT NULL,
  ADD COLUMN IF NOT EXISTS estado_glpi_id INT NULL;
ALTER TABLE equipos ADD INDEX IF NOT EXISTS ix_folio_glpi (folio_glpi);
ALTER TABLE equipos ADD CONSTRAINT fk_eq_glpi_man FOREIGN KEY IF NOT EXISTS (estado_glpi_manual_id) REFERENCES estados_glpi(id);
ALTER TABLE equipos ADD CONSTRAINT fk_eq_glpi FOREIGN KEY IF NOT EXISTS (estado_glpi_id) REFERENCES estados_glpi(id);

-- =====================================================================
-- v5: estado GLPI real (reportado desde GLPI) separado del calculado, para conciliar
-- =====================================================================
ALTER TABLE equipos
  ADD COLUMN IF NOT EXISTS estado_glpi_real_id INT NULL,
  ADD COLUMN IF NOT EXISTS estado_glpi_real_fecha DATETIME NULL;
ALTER TABLE equipos ADD CONSTRAINT fk_eq_glpi_real FOREIGN KEY IF NOT EXISTS (estado_glpi_real_id) REFERENCES estados_glpi(id);
UPDATE equipos SET estado_glpi_real_id = estado_glpi_manual_id, estado_glpi_real_fecha = NOW() WHERE estado_glpi_manual_id IS NOT NULL AND estado_glpi_real_id IS NULL;
UPDATE equipos SET estado_glpi_manual_id = NULL WHERE estado_glpi_manual_id IS NOT NULL;

-- =====================================================================
-- v6: conexión con la API de GLPI
-- =====================================================================
ALTER TABLE estados_glpi ADD COLUMN IF NOT EXISTS codigo_glpi INT NULL;
UPDATE estados_glpi SET codigo_glpi = 1 WHERE nombre = 'Nuevo' AND codigo_glpi IS NULL;
UPDATE estados_glpi SET codigo_glpi = 2 WHERE nombre = 'En curso (asignada)' AND codigo_glpi IS NULL;
UPDATE estados_glpi SET codigo_glpi = 3 WHERE nombre = 'En curso (planificada)' AND codigo_glpi IS NULL;
UPDATE estados_glpi SET codigo_glpi = 4 WHERE nombre = 'En espera' AND codigo_glpi IS NULL;
UPDATE estados_glpi SET codigo_glpi = 5 WHERE nombre = 'Resuelto' AND codigo_glpi IS NULL;
UPDATE estados_glpi SET codigo_glpi = 6 WHERE nombre = 'Cerrado' AND codigo_glpi IS NULL;
ALTER TABLE equipos ADD COLUMN IF NOT EXISTS glpi_consulta DATETIME NULL;
CREATE TABLE IF NOT EXISTS glpi_sync (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usuario_id INT NULL,
  consultados INT NOT NULL DEFAULT 0,
  actualizados INT NOT NULL DEFAULT 0,
  sin_cambios INT NOT NULL DEFAULT 0,
  no_encontrados INT NOT NULL DEFAULT 0,
  desconocidos INT NOT NULL DEFAULT 0,
  detalle TEXT NULL,
  error TEXT NULL,
  segundos DECIMAL(8,1) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- v7: estado GLPI según la sábana (Excel) separado del consultado en GLPI
-- =====================================================================
ALTER TABLE equipos ADD COLUMN IF NOT EXISTS estado_glpi_sabana_id INT NULL;
ALTER TABLE equipos ADD CONSTRAINT fk_eq_glpi_sab FOREIGN KEY IF NOT EXISTS (estado_glpi_sabana_id) REFERENCES estados_glpi(id);

-- =====================================================================
-- v8: conversión del estado GLPI a la categoría de TrazaPulso
-- =====================================================================
ALTER TABLE estados_glpi ADD COLUMN IF NOT EXISTS categoria_tp VARCHAR(60) NULL;
ALTER TABLE estados_glpi ADD COLUMN IF NOT EXISTS regla VARCHAR(255) NULL;
UPDATE estados_glpi SET categoria_tp = 'POR LOCALIZAR',             regla = 'Recién registrado, todavía sin responsable de seguimiento' WHERE nombre = 'Nuevo' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'PARA DESTRUCCION',          regla = 'Esperando autorización o formato de destrucción' WHERE nombre = 'Aprobacion' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'EN SUCURSAL / CON TECNICO', regla = 'Ya tiene responsable y lo está buscando o recolectando' WHERE nombre = 'En curso (asignada)' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'EN TRANSITO',               regla = 'Ya tiene guía y fecha estimada de llegada' WHERE nombre = 'En curso (planificada)' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'EN FRONTERA / ADUANA',      regla = 'Detenido por un tercero (aduana, factura, banco)' WHERE nombre = 'En espera' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'ATENDIENDOSE',              regla = 'Se registró actividad en las últimas 24 h, es un estado temporal' WHERE nombre = 'Atendiendose' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'EN ALMACEN CDMX',           regla = 'El equipo ya se recuperó, falta su disposición final' WHERE nombre = 'Resuelto' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'DESTRUIDO / CERRADO',       regla = 'Con acta de destrucción: caso terminado' WHERE nombre = 'Resuelto + Cerrado' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'ENTREGADO AL BANCO',        regla = 'Se entregó y ya no requiere seguimiento' WHERE nombre = 'Cerrado' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'EXTRAVIADO',                regla = 'No se recuperó, declarado extraviado ante el banco' WHERE nombre = 'No resuelto' AND categoria_tp IS NULL;
UPDATE estados_glpi SET categoria_tp = 'SIN EVIDENCIA',             regla = 'Recibido o destruido sin evidencia: falta el acta o la guía para cerrarlo' WHERE nombre = 'No cerrado' AND categoria_tp IS NULL;

-- =====================================================================
-- v9: calidad de los folios GLPI (existe en GLPI, corresponde al equipo)
-- =====================================================================
ALTER TABLE equipos
  ADD COLUMN IF NOT EXISTS glpi_existe TINYINT(1) NULL,
  ADD COLUMN IF NOT EXISTS glpi_corresponde TINYINT(1) NULL,
  ADD COLUMN IF NOT EXISTS glpi_titulo VARCHAR(255) NULL;
ALTER TABLE sabanas ADD COLUMN IF NOT EXISTS folios_glpi LONGTEXT NULL;
