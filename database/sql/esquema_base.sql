-- =============================================================================
-- EspacioLuz Demo · esquema base (réplica de la estructura del sistema original)
-- Generado desde la estructura (sin datos) de la BD de EspacioLuz el 2026-11-22.
-- Lo ejecuta la migración 20261123000000_esquema_base.php. Cada sentencia termina
-- en una línea «-- ;;» para que la migración pueda separar los disparadores.
-- =============================================================================
CREATE TABLE `boleta_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `boleta_id` bigint(20) unsigned NOT NULL,
  `descripcion` varchar(300) NOT NULL,
  `cantidad` int(10) unsigned NOT NULL DEFAULT 1,
  `precio_unit_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `afecto` tinyint(1) NOT NULL DEFAULT 0,
  `total_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `producto_id` int(10) unsigned DEFAULT NULL COMMENT 'Producto de origen',
  `profesional_id` int(10) unsigned DEFAULT NULL COMMENT 'Socia dueña del producto',
  PRIMARY KEY (`id`),
  KEY `idx_boleta` (`boleta_id`),
  CONSTRAINT `fk_item_boleta` FOREIGN KEY (`boleta_id`) REFERENCES `boletas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `boletas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` int(10) unsigned NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'boleta',
  `serie` enum('interna','sii') NOT NULL DEFAULT 'interna' COMMENT 'interna = sin timbrar (folio propio); sii = folio del CAF',
  `empresa_id` int(10) unsigned DEFAULT NULL,
  `emisor_usuario_id` int(10) unsigned DEFAULT NULL,
  `emisor_nombre` varchar(160) DEFAULT NULL,
  `receptor_nombre` varchar(200) NOT NULL,
  `receptor_rut` varchar(20) DEFAULT NULL,
  `receptor_email` varchar(200) DEFAULT NULL,
  `paciente_id` int(10) unsigned DEFAULT NULL,
  `cliente_id` int(10) unsigned DEFAULT NULL COMMENT 'Cliente facturado (solo facturas)',
  `receptor_tipo` enum('paciente','tutor','cliente') DEFAULT NULL COMMENT 'A quien se cobro: paciente, tutor/apoderado (paciente menor) o cliente (factura)',
  `receptor_giro` varchar(200) DEFAULT NULL COMMENT 'Giro del receptor (factura)',
  `receptor_direccion` varchar(300) DEFAULT NULL,
  `receptor_comuna` varchar(120) DEFAULT NULL,
  `receptor_codigo_prestacion` varchar(40) DEFAULT NULL,
  `reserva_id` bigint(20) unsigned DEFAULT NULL,
  `profesional_id` int(11) DEFAULT NULL,
  `profesional_nombre` varchar(160) DEFAULT NULL,
  `profesional_rut` varchar(30) DEFAULT NULL,
  `fecha_emision` datetime NOT NULL,
  `medio_pago` enum('efectivo','tarjeta','transferencia','otro') NOT NULL DEFAULT 'efectivo',
  `neto_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `iva_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `exento_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `total_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `glosa` varchar(300) DEFAULT NULL,
  `estado` enum('emitida','anulada') NOT NULL DEFAULT 'emitida',
  `pago_estado` enum('pendiente','parcial','pagado') NOT NULL DEFAULT 'pendiente' COMMENT 'Estado de pago (se recalcula con los pagos y notas de crédito)',
  `pagado_clp` int(11) NOT NULL DEFAULT 0 COMMENT 'Suma de pagos (devoluciones restan)',
  `nc_clp` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Total de notas de crédito vigentes',
  `nd_clp` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Total de notas de débito vigentes',
  `vence_en` date DEFAULT NULL COMMENT 'Vencimiento del pago (facturas por cobrar)',
  `pagado_en` datetime DEFAULT NULL COMMENT 'Cuándo quedó pagado',
  `anulada_en` datetime DEFAULT NULL,
  `anulada_motivo` varchar(300) DEFAULT NULL,
  `anulada_por` varchar(160) DEFAULT NULL,
  `sii_estado` enum('no_enviada','pendiente','aceptada','rechazada') NOT NULL DEFAULT 'no_enviada',
  `sii_track_id` varchar(60) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bol_emp_tipo_serie_folio` (`empresa_id`,`tipo`,`serie`,`folio`),
  KEY `idx_fecha` (`fecha_emision`),
  KEY `idx_estado` (`estado`),
  KEY `idx_emisor` (`emisor_usuario_id`),
  KEY `idx_boletas_paciente` (`paciente_id`),
  KEY `idx_bol_empresa` (`empresa_id`),
  KEY `idx_boletas_cliente` (`cliente_id`),
  CONSTRAINT `fk_bol_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_boletas_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_boletas_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `boxes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL COMMENT 'Identificador fijo (lo usan reservas y URLs)',
  `nombre` varchar(120) NOT NULL,
  `ave` varchar(80) NOT NULL DEFAULT '',
  `ave_slug` varchar(40) NOT NULL COMMENT 'Ilustración: loica, martin-pescador, 7-colores, colibri, golondrina',
  `imagen` varchar(255) NOT NULL DEFAULT '',
  `descripcion` varchar(600) NOT NULL DEFAULT '',
  `ubicacion` varchar(120) NOT NULL DEFAULT '',
  `el_espacio` text DEFAULT NULL,
  `equipamiento` text DEFAULT NULL COMMENT 'JSON: lista de ítems',
  `publico_objetivo` varchar(160) NOT NULL DEFAULT '',
  `tarifas` text NOT NULL COMMENT 'JSON: [{label, sub, precio, gc}]',
  `orden` int(11) NOT NULL DEFAULT 0,
  `publico` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Visible en el sitio',
  `activo` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Disponible para reservar (calendario)',
  `eliminado_at` datetime DEFAULT NULL COMMENT 'Archivado: con historial de reservas',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_box_slug` (`slug`),
  KEY `idx_box_orden` (`eliminado_at`,`activo`,`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Boxes del centro (fuente única del sitio y el panel)'
-- ;;

CREATE TABLE `boxes_precios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `box_id` int(10) unsigned NOT NULL,
  `slug` varchar(60) NOT NULL,
  `por_hora_antes` int(10) unsigned NOT NULL DEFAULT 0,
  `por_hora_despues` int(10) unsigned NOT NULL DEFAULT 0,
  `tarifas_antes` text NOT NULL,
  `tarifas_despues` text NOT NULL,
  `reservas_actualizadas` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Horas aún sin facturar que pasaron al nuevo precio',
  `usuario_nombre` varchar(160) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bp_box` (`box_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial de precios de boxes (rigen desde la próxima factura)'
-- ;;

CREATE TABLE `caf_empresa` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` int(10) unsigned NOT NULL,
  `tipo_dte` smallint(5) unsigned NOT NULL,
  `ambiente` enum('certificacion','produccion') NOT NULL DEFAULT 'produccion',
  `folio_desde` int(10) unsigned NOT NULL,
  `folio_hasta` int(10) unsigned NOT NULL,
  `folio_actual` int(10) unsigned NOT NULL,
  `caf_xml` mediumtext DEFAULT NULL,
  `caf_nombre` varchar(120) DEFAULT NULL,
  `fecha_autorizacion` date DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_caf` (`empresa_id`,`tipo_dte`,`ambiente`,`folio_desde`),
  KEY `idx_emp_tipo` (`empresa_id`,`tipo_dte`,`ambiente`,`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `certificados_atencion` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `profesional_id` int(11) DEFAULT NULL,
  `especialidad` varchar(120) NOT NULL,
  `ciudad` varchar(80) NOT NULL DEFAULT 'Los Ángeles',
  `fecha_documento` date NOT NULL,
  `paciente_nombre` varchar(200) NOT NULL,
  `paciente_rut` varchar(20) DEFAULT NULL,
  `paciente_domicilio` varchar(300) DEFAULT NULL,
  `trato` enum('f','m') NOT NULL,
  `modalidad` varchar(120) NOT NULL DEFAULT 'en consulta particular',
  `certifica_pago` tinyint(1) NOT NULL DEFAULT 1,
  `sesiones` text NOT NULL,
  `total_clp` int(11) NOT NULL DEFAULT 0,
  `texto_cierre` varchar(500) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `firmante_nombre` varchar(160) NOT NULL,
  `firmante_titulo` varchar(160) DEFAULT NULL,
  `firmante_detalle` varchar(200) DEFAULT NULL,
  `firmante_rut` varchar(20) DEFAULT NULL,
  `firma_archivo` varchar(120) DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cert_paciente` (`paciente_id`),
  CONSTRAINT `fk_cert_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `clientes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rut` varchar(20) NOT NULL,
  `razon_social` varchar(200) NOT NULL,
  `nombre_fantasia` varchar(200) DEFAULT NULL,
  `giro` varchar(200) NOT NULL,
  `direccion` varchar(300) NOT NULL,
  `region` varchar(80) DEFAULT NULL,
  `comuna` varchar(120) NOT NULL,
  `ciudad` varchar(120) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL COMMENT 'Correo para enviar las facturas',
  `telefono` varchar(60) DEFAULT NULL,
  `contacto` varchar(160) DEFAULT NULL,
  `profesional_id` int(10) unsigned DEFAULT NULL COMMENT 'Profesional que reserva en el Calendario de Boxes (trae sus horas al facturar)',
  `box_slug` varchar(60) DEFAULT NULL COMMENT 'Box habitual',
  `tarifa` varchar(200) DEFAULT NULL COMMENT 'Tarifa habitual del box (etiqueta)',
  `medio_pago` enum('transferencia','efectivo','tarjeta','otro') NOT NULL DEFAULT 'transferencia',
  `notas` varchar(500) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cliente_rut` (`rut`),
  KEY `idx_cliente_razon` (`razon_social`),
  KEY `idx_cliente_prof` (`profesional_id`),
  CONSTRAINT `fk_cliente_prof` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clientes que arriendan boxes (receptores de factura)'
-- ;;

CREATE TABLE `clientes_notas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` int(10) unsigned NOT NULL,
  `texto` text NOT NULL,
  `completada` tinyint(1) NOT NULL DEFAULT 0,
  `completada_en` datetime DEFAULT NULL,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `creado_por_nombre` varchar(120) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `editado_en` datetime DEFAULT NULL,
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cnota_cliente` (`cliente_id`,`completada`,`creado_en`),
  CONSTRAINT `fk_cnota_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notas internas de clientes'
-- ;;

CREATE TABLE `clientes_profesionales` (
  `cliente_id` int(10) unsigned NOT NULL,
  `profesional_id` int(10) unsigned NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`cliente_id`,`profesional_id`),
  KEY `idx_cp_prof` (`profesional_id`),
  CONSTRAINT `fk_cp_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cp_prof` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Profesionales vinculados a cada cliente'
-- ;;

CREATE TABLE `comunas` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `region_id` tinyint(3) unsigned NOT NULL,
  `nombre` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comuna_region` (`region_id`,`nombre`),
  KEY `idx_comuna_nombre` (`nombre`),
  CONSTRAINT `fk_comuna_region` FOREIGN KEY (`region_id`) REFERENCES `regiones` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `configuracion` (
  `clave` varchar(64) NOT NULL,
  `valor` longtext NOT NULL,
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `disponibilidad` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `profesional_id` int(10) unsigned NOT NULL,
  `dia_semana` tinyint(3) unsigned NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_disp_prof` (`profesional_id`,`dia_semana`),
  CONSTRAINT `fk_disp_prof` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `documento_pagos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `documento_id` bigint(20) unsigned NOT NULL,
  `fecha` date NOT NULL,
  `monto` int(11) NOT NULL COMMENT 'Positivo = pago recibido; negativo = devolución',
  `medio` enum('efectivo','tarjeta','transferencia','otro') NOT NULL DEFAULT 'transferencia',
  `referencia` varchar(160) DEFAULT NULL,
  `usuario_nombre` varchar(160) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pago_doc` (`documento_id`),
  KEY `idx_pago_fecha` (`fecha`),
  CONSTRAINT `fk_pago_doc` FOREIGN KEY (`documento_id`) REFERENCES `boletas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pagos y devoluciones por documento'
-- ;;

CREATE TABLE `dte_documento_det` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `documento_id` int(10) unsigned NOT NULL,
  `producto_id` int(10) unsigned DEFAULT NULL,
  `codigo` varchar(120) DEFAULT NULL,
  `descripcion` varchar(255) NOT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT 1.0000,
  `unidad` varchar(20) NOT NULL DEFAULT 'UN',
  `precio_unitario` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `descuento_monto` int(11) NOT NULL DEFAULT 0,
  `exento` tinyint(1) NOT NULL DEFAULT 0,
  `total_linea` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_doc` (`documento_id`),
  CONSTRAINT `fk_det_doc` FOREIGN KEY (`documento_id`) REFERENCES `dte_documentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `dte_documento_ref` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `documento_id` int(10) unsigned NOT NULL,
  `tipo_doc` varchar(10) DEFAULT NULL,
  `folio` varchar(20) DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `codigo` tinyint(4) DEFAULT NULL,
  `razon` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_doc_ref` (`documento_id`),
  CONSTRAINT `fk_ref_doc` FOREIGN KEY (`documento_id`) REFERENCES `dte_documentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `dte_documentos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` int(10) unsigned NOT NULL,
  `tipo_dte` smallint(5) unsigned NOT NULL,
  `folio` int(10) unsigned NOT NULL,
  `ambiente` enum('certificacion','produccion') NOT NULL DEFAULT 'produccion',
  `rut_emisor` varchar(20) NOT NULL,
  `rut_receptor` varchar(20) DEFAULT NULL,
  `razon_receptor` varchar(200) DEFAULT NULL,
  `paciente_id` int(10) unsigned DEFAULT NULL,
  `reserva_id` bigint(20) unsigned DEFAULT NULL,
  `boleta_id` bigint(20) unsigned DEFAULT NULL,
  `fecha_emision` date NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `moneda` varchar(8) NOT NULL DEFAULT 'CLP',
  `tipo_cambio` decimal(14,4) DEFAULT NULL,
  `neto` int(11) NOT NULL DEFAULT 0,
  `exento` int(11) NOT NULL DEFAULT 0,
  `iva` int(11) NOT NULL DEFAULT 0,
  `total` int(11) NOT NULL DEFAULT 0,
  `estado` varchar(20) NOT NULL DEFAULT 'PENDIENTE',
  `trackid` varchar(50) DEFAULT NULL,
  `glosa_error` longtext DEFAULT NULL,
  `caf_empresa_id` int(10) unsigned DEFAULT NULL,
  `sii_envio_id` int(10) unsigned DEFAULT NULL,
  `datos_extra` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc` (`empresa_id`,`tipo_dte`,`folio`,`ambiente`),
  KEY `idx_empresa_tipo` (`empresa_id`,`tipo_dte`),
  KEY `idx_estado` (`estado`),
  KEY `idx_trackid` (`trackid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `empresa_cert` (
  `empresa_id` int(10) unsigned NOT NULL,
  `cert_rut` varchar(20) DEFAULT NULL,
  `cert_pass_enc` text NOT NULL,
  `cert_nombre` varchar(160) DEFAULT NULL,
  `cert_vence` date DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`empresa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `empresas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `razon_social` varchar(200) NOT NULL,
  `nombre_fantasia` varchar(200) DEFAULT NULL,
  `rut` varchar(20) DEFAULT NULL,
  `giro` varchar(200) DEFAULT NULL,
  `actividad_economica` varchar(200) DEFAULT NULL,
  `acteco` int(10) unsigned DEFAULT NULL COMMENT 'Código de actividad económica SII (6 dígitos)',
  `sii_resolucion_numero` int(10) unsigned DEFAULT NULL COMMENT 'N° de resolución SII que autoriza a emitir DTE',
  `sii_resolucion_fecha` date DEFAULT NULL COMMENT 'Fecha de la resolución SII',
  `direccion` varchar(200) DEFAULT NULL,
  `comuna` varchar(100) DEFAULT NULL,
  `ciudad` varchar(120) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `telefono` varchar(40) DEFAULT NULL,
  `tipo_documento` enum('boleta','factura') NOT NULL DEFAULT 'boleta',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 0,
  `creado_en` timestamp NULL DEFAULT current_timestamp(),
  `dte_certificados` varchar(60) DEFAULT NULL COMMENT 'Tipos de DTE certificados ante el SII (ej. 34,56,61); NULL = sin restricción',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `especialidades` (
  `slug` varchar(60) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `evoluciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ficha_id` int(10) unsigned NOT NULL,
  `reserva_id` bigint(20) unsigned DEFAULT NULL,
  `profesional_id` int(10) unsigned DEFAULT NULL,
  `fecha` date NOT NULL,
  `nota_progreso` text NOT NULL,
  `proxima_accion` text DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `editado_en` datetime DEFAULT NULL COMMENT 'Última edición',
  `editado_por` varchar(120) DEFAULT NULL,
  `eliminado_en` datetime DEFAULT NULL COMMENT 'Eliminada (oculta): se conserva por Ley 20.584',
  `eliminado_por` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_evo_ficha` (`ficha_id`,`fecha`),
  KEY `fk_evo_prof` (`profesional_id`),
  KEY `fk_evo_reserva` (`reserva_id`),
  CONSTRAINT `fk_evo_ficha` FOREIGN KEY (`ficha_id`) REFERENCES `fichas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_evo_prof` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_evo_reserva` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `feriados` (
  `fecha` date NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `irrenunciable` tinyint(1) NOT NULL DEFAULT 0,
  `se_trabaja` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = el centro atiende ese feriado (se pueden tomar horas)',
  `origen` enum('legal','manual') NOT NULL DEFAULT 'legal' COMMENT 'legal = calendario oficial; manual = agregado en el panel',
  `actualizado_por` int(10) unsigned DEFAULT NULL,
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Feriados de Chile; el centro decide si los trabaja'
-- ;;

CREATE TABLE `fichas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `especialidad_slug` varchar(60) NOT NULL,
  `profesional_responsable_id` int(10) unsigned DEFAULT NULL,
  `motivo_consulta` text DEFAULT NULL,
  `antecedentes` text DEFAULT NULL,
  `diagnostico` text DEFAULT NULL,
  `objetivos` text DEFAULT NULL,
  `estado` enum('activa','cerrada') NOT NULL DEFAULT 'activa',
  `abierta_en` date NOT NULL,
  `cerrada_en` date DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ficha_paciente_esp` (`paciente_id`,`especialidad_slug`),
  KEY `idx_ficha_prof` (`profesional_responsable_id`),
  CONSTRAINT `fk_ficha_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ficha_prof` FOREIGN KEY (`profesional_responsable_id`) REFERENCES `profesionales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `folios_config` (
  `empresa_id` int(10) unsigned NOT NULL,
  `tipo_dte` smallint(5) unsigned NOT NULL,
  `auto` tinyint(1) NOT NULL DEFAULT 1,
  `umbral` int(10) unsigned NOT NULL DEFAULT 10,
  `cantidad` int(10) unsigned NOT NULL DEFAULT 20,
  `maximo_sii` int(10) unsigned DEFAULT NULL,
  `maximo_fecha` datetime DEFAULT NULL,
  PRIMARY KEY (`empresa_id`,`tipo_dte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solicitud automática de folios por empresa y tipo'
-- ;;

CREATE TABLE `folios_solicitudes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` int(10) unsigned NOT NULL,
  `tipo_dte` smallint(5) unsigned NOT NULL,
  `cantidad_solicitada` int(10) unsigned NOT NULL DEFAULT 0,
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `folio_desde` int(10) unsigned DEFAULT NULL,
  `folio_hasta` int(10) unsigned DEFAULT NULL,
  `maximo_sii` int(10) unsigned DEFAULT NULL,
  `mensaje` text DEFAULT NULL,
  `origen` varchar(20) NOT NULL DEFAULT 'manual',
  `usuario` varchar(160) DEFAULT NULL,
  `creado_en` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mes` (`creado_en`),
  KEY `idx_emp_tipo` (`empresa_id`,`tipo_dte`,`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solicitudes de folios (CAF) al SII y su respuesta'
-- ;;

CREATE TABLE `horario_centro` (
  `dia_semana` tinyint(3) unsigned NOT NULL COMMENT '1 = lunes … 6 = sábado (el domingo el centro no atiende)',
  `abierto` tinyint(1) NOT NULL DEFAULT 1,
  `hora_inicio` time NOT NULL DEFAULT '08:00:00',
  `hora_fin` time NOT NULL DEFAULT '21:00:00',
  `actualizado_por` int(10) unsigned DEFAULT NULL,
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`dia_semana`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Horario de atención del centro (panel: Días y horarios)'
-- ;;

CREATE TABLE `notas_credito` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `documento_id` bigint(20) unsigned NOT NULL COMMENT 'Boleta o factura que corrige',
  `empresa_id` int(10) unsigned NOT NULL,
  `folio` int(10) unsigned NOT NULL,
  `serie` enum('interna','sii') NOT NULL DEFAULT 'interna',
  `fecha_emision` datetime NOT NULL,
  `motivo` varchar(300) NOT NULL,
  `accion` enum('devolucion','descuento','anulacion','correccion') NOT NULL DEFAULT 'devolucion',
  `codigo_ref` tinyint(3) unsigned DEFAULT NULL COMMENT 'Código SII: 1 anula, 2 corrige texto, 3 corrige montos',
  `neto_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `iva_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `exento_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `total_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `estado` enum('emitida','anulada') NOT NULL DEFAULT 'emitida',
  `emisor_nombre` varchar(160) DEFAULT NULL,
  `usuario_nombre` varchar(160) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `dte_documento_id` int(10) unsigned DEFAULT NULL COMMENT 'DTE 61 en dte_documentos (si fue al SII)',
  `sii_estado` enum('no_enviada','pendiente','aceptada','rechazada') NOT NULL DEFAULT 'no_enviada',
  `sii_track_id` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nc_folio` (`empresa_id`,`serie`,`folio`),
  KEY `idx_nc_doc` (`documento_id`),
  KEY `idx_nc_fecha` (`fecha_emision`),
  CONSTRAINT `fk_nc_doc` FOREIGN KEY (`documento_id`) REFERENCES `boletas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notas de crédito (devoluciones y descuentos)'
-- ;;

CREATE TABLE `notas_credito_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nota_id` bigint(20) unsigned NOT NULL,
  `descripcion` varchar(300) NOT NULL,
  `cantidad` int(10) unsigned NOT NULL DEFAULT 1,
  `precio_unit_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `afecto` tinyint(1) NOT NULL DEFAULT 0,
  `total_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `reserva_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Hora o sesión devuelta',
  PRIMARY KEY (`id`),
  KEY `idx_nci_nota` (`nota_id`),
  KEY `idx_nci_reserva` (`reserva_id`),
  CONSTRAINT `fk_nci_nota` FOREIGN KEY (`nota_id`) REFERENCES `notas_credito` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `notas_debito` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `documento_id` bigint(20) unsigned NOT NULL COMMENT 'Boleta o factura sobre la que se emite',
  `nota_credito_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Nota de crédito que anula (accion anula_nc)',
  `empresa_id` int(10) unsigned NOT NULL,
  `folio` int(10) unsigned NOT NULL,
  `serie` enum('interna','sii') NOT NULL DEFAULT 'interna',
  `fecha_emision` datetime NOT NULL,
  `motivo` varchar(300) NOT NULL,
  `accion` enum('cargo','anula_nc') NOT NULL DEFAULT 'cargo',
  `codigo_ref` tinyint(3) unsigned DEFAULT NULL COMMENT 'Código SII: 1 anula (NC), 3 corrige montos',
  `neto_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `iva_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `exento_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `total_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `estado` enum('emitida','anulada') NOT NULL DEFAULT 'emitida',
  `emisor_nombre` varchar(160) DEFAULT NULL,
  `usuario_nombre` varchar(160) DEFAULT NULL,
  `dte_documento_id` int(10) unsigned DEFAULT NULL,
  `sii_estado` enum('no_enviada','pendiente','aceptada','rechazada') NOT NULL DEFAULT 'no_enviada',
  `sii_track_id` varchar(60) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nd_folio` (`empresa_id`,`serie`,`folio`),
  KEY `idx_nd_doc` (`documento_id`),
  KEY `idx_nd_nc` (`nota_credito_id`),
  KEY `idx_nd_fecha` (`fecha_emision`),
  CONSTRAINT `fk_nd_doc` FOREIGN KEY (`documento_id`) REFERENCES `boletas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nd_nc` FOREIGN KEY (`nota_credito_id`) REFERENCES `notas_credito` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notas de débito (cobros adicionales y anulación de notas de crédito)'
-- ;;

CREATE TABLE `notas_debito_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nota_id` bigint(20) unsigned NOT NULL,
  `descripcion` varchar(300) NOT NULL,
  `cantidad` int(10) unsigned NOT NULL DEFAULT 1,
  `precio_unit_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `afecto` tinyint(1) NOT NULL DEFAULT 0,
  `total_clp` int(10) unsigned NOT NULL DEFAULT 0,
  `reserva_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Hora que vuelve a cobrarse',
  PRIMARY KEY (`id`),
  KEY `idx_ndi_nota` (`nota_id`),
  CONSTRAINT `fk_ndi_nota` FOREIGN KEY (`nota_id`) REFERENCES `notas_debito` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `paciente_informes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `ficha_id` int(10) unsigned DEFAULT NULL COMMENT 'Área (ficha) a la que pertenece; NULL = general',
  `tipo` varchar(40) NOT NULL COMMENT 'evaluacion, avance, orientacion_familiar, alta, derivacion, externo, otro',
  `titulo` varchar(200) NOT NULL,
  `fecha_informe` date NOT NULL,
  `profesional_id` int(10) unsigned DEFAULT NULL COMMENT 'Quien lo emitió (NULL = externo o sin indicar)',
  `descripcion` text DEFAULT NULL,
  `archivo` varchar(80) NOT NULL COMMENT 'storage/informes/p<paciente>/<archivo>',
  `nombre_original` varchar(255) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `tamano` int(10) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `subido_por` varchar(120) DEFAULT NULL,
  `subido_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `editado_en` datetime DEFAULT NULL,
  `editado_por` varchar(120) DEFAULT NULL,
  `eliminado_en` datetime DEFAULT NULL COMMENT 'Eliminado (oculto): se conserva por Ley 20.584',
  `eliminado_por` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inf_paciente` (`paciente_id`,`fecha_informe`),
  KEY `idx_inf_ficha` (`ficha_id`),
  KEY `idx_inf_prof` (`profesional_id`),
  CONSTRAINT `fk_inf_ficha` FOREIGN KEY (`ficha_id`) REFERENCES `fichas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inf_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_inf_prof` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Informes clínicos adjuntos a la ficha del paciente'
-- ;;

CREATE TABLE `pacientes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `rut` varchar(20) DEFAULT NULL,
  `documento_tipo` varchar(12) NOT NULL DEFAULT 'rut',
  `documento_numero` varchar(30) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `telefono` varchar(60) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `sexo` enum('hombre','mujer','otro') DEFAULT NULL,
  `direccion` varchar(300) DEFAULT NULL,
  `region` varchar(80) DEFAULT NULL,
  `comuna` varchar(120) DEFAULT NULL,
  `apoderado` varchar(300) DEFAULT NULL,
  `apoderado_rut` varchar(20) DEFAULT NULL,
  `apoderado_telefono` varchar(40) DEFAULT NULL,
  `contacto_emergencia` varchar(300) DEFAULT NULL,
  `prevision` varchar(60) DEFAULT NULL,
  `giro` varchar(160) DEFAULT NULL,
  `codigo_prestacion` varchar(40) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `consentimiento` tinyint(1) NOT NULL DEFAULT 0,
  `consentimiento_fecha` datetime DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paciente_rut` (`rut`),
  UNIQUE KEY `uq_paciente_doc` (`documento_tipo`,`documento_numero`),
  KEY `idx_paciente_nombre` (`nombre`),
  KEY `idx_paciente_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `pacientes_notas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `texto` text NOT NULL,
  `completada` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = marcada con ticket',
  `completada_en` datetime DEFAULT NULL,
  `creado_por` int(10) unsigned DEFAULT NULL COMMENT 'usuarios.id de quien la escribió',
  `creado_por_nombre` varchar(120) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `editado_en` datetime DEFAULT NULL COMMENT 'última edición del texto',
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pnota_paciente` (`paciente_id`,`completada`,`creado_en`),
  CONSTRAINT `fk_pnota_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notas internas (no clínicas) de cada paciente'
-- ;;

CREATE TABLE `previsiones` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `grupo` varchar(40) NOT NULL,
  `orden` smallint(5) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prevision_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `productos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `empresa_id` int(10) unsigned DEFAULT NULL COMMENT 'Empresa del producto; NULL = Ambas empresas (boletas)',
  `profesional_id` int(10) unsigned DEFAULT NULL COMMENT 'Socia dueña del producto (Fernanda o Javiera)',
  `nombre` varchar(200) NOT NULL,
  `descripcion` varchar(300) DEFAULT NULL,
  `precio_neto` int(10) unsigned NOT NULL DEFAULT 0,
  `afecto` tinyint(1) NOT NULL DEFAULT 1,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 0,
  `creado_en` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prod_empresa` (`empresa_id`),
  KEY `idx_prod_prof` (`profesional_id`),
  CONSTRAINT `fk_prod_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `profesionales` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `rut` varchar(20) DEFAULT NULL,
  `firma_archivo` varchar(120) DEFAULT NULL,
  `firma_actualizada` datetime DEFAULT NULL,
  `slug` varchar(80) DEFAULT NULL,
  `especialidad_slug` varchar(60) NOT NULL,
  `titulo` varchar(120) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `enfoque` text DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `duracion_texto` varchar(60) DEFAULT NULL,
  `empresa_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `eliminado_at` datetime DEFAULT NULL COMMENT 'Archivado: fuera del panel y del sitio, historial intacto',
  `publico` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 0,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `perfil` longtext DEFAULT NULL,
  `agenda_online` tinyint(1) NOT NULL DEFAULT 0,
  `socia` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Socia: emite boletas y facturas; no paga arriendo de box',
  `box_slug` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prof_slug` (`slug`),
  KEY `idx_prof_esp` (`especialidad_slug`),
  KEY `idx_prof_list` (`activo`,`publico`,`orden`),
  KEY `idx_prof_eliminado` (`eliminado_at`),
  CONSTRAINT `fk_prof_esp` FOREIGN KEY (`especialidad_slug`) REFERENCES `especialidades` (`slug`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `regiones` (
  `id` tinyint(3) unsigned NOT NULL COMMENT 'Código oficial (CUT) de la región',
  `numero` varchar(5) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `orden` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_region_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `reservas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `publico_id` char(16) NOT NULL,
  `box_slug` varchar(60) NOT NULL,
  `profesional_id` int(10) unsigned DEFAULT NULL,
  `paciente_id` int(10) unsigned DEFAULT NULL,
  `modalidad_label` varchar(120) NOT NULL,
  `modalidad_sub` varchar(160) DEFAULT NULL,
  `tipo_atencion` varchar(40) DEFAULT NULL COMMENT 'Tipo de atención (opcional): evaluacion, intervencion, orientacion_familiar, etapas_vida, otro',
  `precio_clp` int(10) unsigned DEFAULT NULL,
  `fecha` date NOT NULL,
  `hora` time DEFAULT NULL,
  `duracion_min` smallint(5) unsigned DEFAULT NULL,
  `nombre` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `telefono` varchar(60) NOT NULL,
  `estado` enum('pendiente','confirmada','realizada','cancelada','no_show') NOT NULL DEFAULT 'pendiente',
  `origen` varchar(30) NOT NULL DEFAULT 'web',
  `serie_id` char(16) DEFAULT NULL COMMENT 'Agrupa las horas creadas en un mismo lote (rango)',
  `factura_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Factura a cliente en que se cobró esta hora de box',
  `boleta_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Boleta que pagó esta sesión (paciente)',
  `boleta_neto` int(10) unsigned DEFAULT NULL COMMENT 'Neto cobrado por esta sesión en su boleta',
  `boleta_afecto` tinyint(1) DEFAULT NULL,
  `cobro_neto` int(10) unsigned DEFAULT NULL COMMENT 'Neto cobrado por esta hora de box en su factura',
  `cobro_afecto` tinyint(1) DEFAULT NULL COMMENT '1 = afecto IVA',
  `factura_plan` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = incluida en un plan mensual facturado',
  `cobro_mantenido` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = cancelada, pero el cobro se mantiene (sin devolución)',
  `notas` varchar(500) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `slot_prof` varchar(160) GENERATED ALWAYS AS (case when `estado` <> 'cancelada' and `hora` is not null and `profesional_id` is not null and `origen` <> 'panel' then concat(`profesional_id`,'|',`fecha`,'|',time_format(`hora`,'%H:%i')) else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pub` (`publico_id`),
  UNIQUE KEY `uq_slot_prof` (`slot_prof`),
  KEY `idx_box_fecha` (`box_slug`,`fecha`),
  KEY `idx_fecha_estado` (`fecha`,`estado`),
  KEY `idx_prof` (`profesional_id`),
  KEY `idx_reservas_paciente` (`paciente_id`),
  KEY `idx_prof_fh` (`profesional_id`,`fecha`,`hora`),
  KEY `idx_box_fh` (`box_slug`,`fecha`,`hora`),
  KEY `idx_serie` (`serie_id`),
  KEY `idx_reservas_factura` (`factura_id`),
  KEY `idx_reservas_boleta` (`boleta_id`),
  CONSTRAINT `fk_reservas_boleta` FOREIGN KEY (`boleta_id`) REFERENCES `boletas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_reservas_factura` FOREIGN KEY (`factura_id`) REFERENCES `boletas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_reservas_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_reservas_prof` FOREIGN KEY (`profesional_id`) REFERENCES `profesionales` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `reservas_movimientos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reserva_id` bigint(20) unsigned NOT NULL,
  `lote_id` char(16) DEFAULT NULL COMMENT 'Agrupa los movimientos de una misma operación masiva',
  `accion` enum('cancelacion','traspaso','reagenda','cobro','reactivacion') NOT NULL,
  `profesional_origen_id` int(10) unsigned DEFAULT NULL,
  `profesional_destino_id` int(10) unsigned DEFAULT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `usuario_nombre` varchar(160) DEFAULT NULL,
  `detalle` varchar(300) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mov_reserva` (`reserva_id`),
  KEY `idx_mov_lote` (`lote_id`),
  KEY `idx_mov_fecha` (`creado_en`),
  CONSTRAINT `fk_mov_reserva` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `sii_envios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doc_tipo` varchar(10) NOT NULL,
  `doc_id` int(11) NOT NULL,
  `rut_emisor` varchar(20) NOT NULL,
  `rut_receptor` varchar(20) DEFAULT NULL,
  `folio` int(11) DEFAULT NULL,
  `lote_id` varchar(80) DEFAULT NULL,
  `trackid` varchar(50) DEFAULT NULL,
  `estado` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `glosa_error` longtext DEFAULT NULL,
  `xml_dte` longtext DEFAULT NULL,
  `xml_envio` longtext DEFAULT NULL,
  `respuesta_sii` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_doc` (`doc_tipo`,`doc_id`),
  KEY `idx_track` (`trackid`),
  KEY `idx_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;

CREATE TABLE `usuarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `rol` enum('admin','editor','pacientes') NOT NULL DEFAULT 'admin',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `ultimo_acceso` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- ;;
CREATE TRIGGER trg_reservas_cupo_ins BEFORE INSERT ON reservas FOR EACH ROW
BEGIN
  IF NEW.estado <> 'cancelada' THEN
    IF WEEKDAY(NEW.fecha) = 6 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DIA_CERRADO_DOMINGO';
    END IF;
    IF EXISTS (SELECT 1 FROM feriados WHERE fecha = NEW.fecha AND se_trabaja = 0) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DIA_CERRADO_FERIADO';
    END IF;
  END IF;
  IF NEW.estado <> 'cancelada' AND NEW.hora IS NOT NULL AND NEW.box_slug IS NOT NULL THEN
    BEGIN
      DECLARE v_cupo INT DEFAULT 2;
      DECLARE v_n INT DEFAULT 0;
      SET v_cupo = COALESCE((SELECT CAST(valor AS UNSIGNED) FROM configuracion WHERE clave = 'box_cupo_max' LIMIT 1), 2);
      SELECT COUNT(*) INTO v_n FROM reservas
       WHERE box_slug = NEW.box_slug AND fecha = NEW.fecha AND hora = NEW.hora AND estado <> 'cancelada';
      IF v_n >= v_cupo THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BOX_SIN_CUPO';
      END IF;
    END;
  END IF;
END
-- ;;

CREATE TRIGGER trg_reservas_cupo_upd BEFORE UPDATE ON reservas FOR EACH ROW
BEGIN
  IF NEW.estado <> 'cancelada' AND (OLD.estado = 'cancelada' OR NOT (OLD.fecha <=> NEW.fecha)) THEN
    IF WEEKDAY(NEW.fecha) = 6 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DIA_CERRADO_DOMINGO';
    END IF;
    IF EXISTS (SELECT 1 FROM feriados WHERE fecha = NEW.fecha AND se_trabaja = 0) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DIA_CERRADO_FERIADO';
    END IF;
  END IF;
  IF NEW.estado <> 'cancelada' AND NEW.hora IS NOT NULL AND NEW.box_slug IS NOT NULL
     AND (OLD.estado = 'cancelada' OR NOT (OLD.box_slug <=> NEW.box_slug)
          OR NOT (OLD.fecha <=> NEW.fecha) OR NOT (OLD.hora <=> NEW.hora)) THEN
    BEGIN
      DECLARE v_cupo INT DEFAULT 2;
      DECLARE v_n INT DEFAULT 0;
      SET v_cupo = COALESCE((SELECT CAST(valor AS UNSIGNED) FROM configuracion WHERE clave = 'box_cupo_max' LIMIT 1), 2);
      SELECT COUNT(*) INTO v_n FROM reservas
       WHERE box_slug = NEW.box_slug AND fecha = NEW.fecha AND hora = NEW.hora AND estado <> 'cancelada' AND id <> NEW.id;
      IF v_n >= v_cupo THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BOX_SIN_CUPO';
      END IF;
    END;
  END IF;
END
-- ;;
