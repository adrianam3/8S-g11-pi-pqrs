-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 30-09-2025 a las 04:54:58
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `imbauto3_pqrs`
--

DELIMITER $$
--
-- Procedimientos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_cerrar_no_contestadas` ()   BEGIN
  UPDATE encuestasProgramadas ep
  LEFT JOIN (
     SELECT idProgEncuesta
     FROM respuestasCliente
     GROUP BY idProgEncuesta
  ) r ON r.idProgEncuesta = ep.idProgEncuesta
  SET ep.estadoEnvio = 'NO_CONTESTADA',
      ep.fechaActualizacion = NOW()
  WHERE ep.estadoEnvio IN ('PENDIENTE','ENVIADA')   -- compatible con flujo manual
    AND ep.intentosEnviados >= ep.maxIntentos
    AND r.idProgEncuesta IS NULL;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_ejecutar_escalamiento` ()   BEGIN
  DECLARE v_idEstadoEscalado INT;
  DECLARE v_idEstadoCerrado INT;

  SELECT idEstado INTO v_idEstadoEscalado FROM estadosPqrs WHERE nombre='ESCALADO';
  SELECT idEstado INTO v_idEstadoCerrado  FROM estadosPqrs WHERE nombre='CERRADO';

  UPDATE pqrs p
  JOIN pqrs_responsables pr
    ON pr.idPqrs = p.idPqrs
   AND pr.nivel = p.nivelActual + 1
  SET p.nivelActual = p.nivelActual + 1,
      p.idEstado = v_idEstadoEscalado,
      p.fechaLimiteNivel = DATE_ADD(NOW(), INTERVAL pr.horasSLA HOUR),
      p.fechaActualizacion = NOW()
  WHERE p.estadoRegistro = 1
    AND p.idEstado <> v_idEstadoCerrado
    AND p.fechaLimiteNivel IS NOT NULL
    AND NOW() >= p.fechaLimiteNivel;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_generar_pqrs_desde_respuestas` (IN `p_idprogencuesta` BIGINT)   BEGIN
  /* ===== Variables ===== */
  DECLARE v_idencuesta       BIGINT;
  DECLARE v_idatencion       BIGINT;
  DECLARE v_idcliente        BIGINT;
  DECLARE v_idcanal          INT;
  DECLARE v_idagencia        INT;
  DECLARE v_idestadoabierto  INT;
  DECLARE v_codigo           VARCHAR(20);
  DECLARE v_idpqrs           BIGINT;
  DECLARE v_idtiporeclamo    INT;
  DECLARE v_idcategoriasel   INT;
  DECLARE v_horassla_n1      INT;

  DECLARE v_existen_detonantes INT DEFAULT 0;
  DECLARE v_sin_cat_opc_genera INT DEFAULT 0;
  DECLARE v_con_categorias     INT DEFAULT 0;

  /* Para asunto/detalle */
  DECLARE v_asunto    TEXT;
  DECLARE v_det_body  TEXT;
  DECLARE v_detalle   LONGTEXT;
  DECLARE v_cliente   VARCHAR(255);
  DECLARE v_nom_tipo_reclamo VARCHAR(100);

  /* Campos de ATENCIÓN */
  DECLARE v_tipoDoc        VARCHAR(100);
  DECLARE v_numDoc         VARCHAR(100);
  DECLARE v_numFactura     VARCHAR(100);
  DECLARE v_det_atencion   TEXT;
  DECLARE v_det_atn_block  TEXT;

  /* Idempotencia */
  DECLARE v_pqrs_existente BIGINT;
  DECLARE v_msg            TEXT;

  /* Handler de errores */
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK; RESIGNAL;
  END;

  START TRANSACTION;

  /* ===== 0) Idempotencia ===== */
  SELECT idPqrs
    INTO v_pqrs_existente
  FROM pqrs
  WHERE idProgEncuesta = p_idprogencuesta
  ORDER BY idPqrs DESC
  LIMIT 1;

  IF v_pqrs_existente IS NOT NULL THEN
    SET v_msg = CONCAT('Ya existe un PQR (id=', v_pqrs_existente,
                       ') para idProgEncuesta=', p_idprogencuesta, '.');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;

  /* Evitar truncamiento en concatenaciones largas */
  SET @old_gcml := @@SESSION.group_concat_max_len;
  SET SESSION group_concat_max_len = 8192;

  /* ===== 1) Contexto de la instancia ===== */
  SELECT ep.idEncuesta, ep.idAtencion, ep.idCliente, e.idCanal
    INTO v_idencuesta, v_idatencion, v_idcliente, v_idcanal
  FROM encuestasProgramadas ep
  JOIN encuestas e ON e.idEncuesta = ep.idEncuesta
  WHERE ep.idProgEncuesta = p_idprogencuesta
  LIMIT 1;

  /* Datos de la atención */
  SELECT a.idAgencia, a.tipoDocumento, a.numeroDocumento, a.numeroFactura, a.detalle
    INTO v_idagencia, v_tipoDoc, v_numDoc, v_numFactura, v_det_atencion
  FROM atenciones a
  WHERE a.idAtencion = v_idatencion
  LIMIT 1;

  /* Estado ABIERTO */
  SELECT idEstado INTO v_idestadoabierto
  FROM estadosPqrs WHERE nombre='ABIERTO' LIMIT 1;

  /* Nombre del cliente */
  SELECT CONCAT(TRIM(c.nombres),' ',TRIM(c.apellidos))
    INTO v_cliente
  FROM clientes c
  WHERE c.idCliente = v_idcliente
  LIMIT 1;

  /* ===== 2) Preguntas detonantes ===== */
  DROP TEMPORARY TABLE IF EXISTS tmp_detonantes;
  CREATE TEMPORARY TABLE tmp_detonantes (
    idPregunta BIGINT PRIMARY KEY
  ) ENGINE=MEMORY
  AS
  SELECT DISTINCT rc.idPregunta
  FROM respuestasCliente rc
  JOIN preguntas p               ON p.idPregunta = rc.idPregunta
  LEFT JOIN respuestasOpciones o ON o.idOpcion   = rc.idOpcion
  WHERE rc.idProgEncuesta = p_idprogencuesta
    AND p.generaPqr = 1
    AND (
          (p.tipo='ESCALA_1_10' AND rc.valorNumerico IS NOT NULL AND rc.valorNumerico <= IFNULL(p.umbralMinimo,7))
          OR (o.generaPqr = 1)
        );

  SELECT COUNT(*) INTO v_existen_detonantes FROM tmp_detonantes;

  IF v_existen_detonantes = 0 THEN
    UPDATE encuestasProgramadas
       SET estadoEnvio='RESPONDIDA', fechaActualizacion=NOW()
     WHERE idProgEncuesta = p_idprogencuesta;

    SET SESSION group_concat_max_len = @old_gcml;
    COMMIT;
  ELSE
    /* ===== 3) Validaciones de categoría ===== */
    SELECT COUNT(*)
      INTO v_sin_cat_opc_genera
    FROM respuestasCliente rc
    JOIN tmp_detonantes d        ON d.idPregunta = rc.idPregunta
    LEFT JOIN respuestasOpciones o ON o.idOpcion  = rc.idOpcion
    WHERE rc.idProgEncuesta = p_idprogencuesta
      AND o.generaPqr = 1
      AND rc.idCategoria IS NULL;

    IF v_sin_cat_opc_genera > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No se puede generar PQR: hay respuestas detonantes (generaPqr=1) sin idCategoria en respuestasCliente.';
    END IF;

    SELECT COUNT(*)
      INTO v_con_categorias
    FROM respuestasCliente rc
    JOIN tmp_detonantes d ON d.idPregunta = rc.idPregunta
    WHERE rc.idProgEncuesta = p_idprogencuesta
      AND rc.idCategoria IS NOT NULL;

    IF v_con_categorias = 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No se puede generar PQR: ninguna respuesta detonante tiene idCategoria asignada.';
    END IF;

    /* ===== 4) Categoría principal para el PQR ===== */
    SELECT rc.idCategoria
      INTO v_idcategoriasel
    FROM respuestasCliente rc
    JOIN tmp_detonantes d        ON d.idPregunta = rc.idPregunta
    LEFT JOIN respuestasOpciones o ON o.idOpcion  = rc.idOpcion
    WHERE rc.idProgEncuesta = p_idprogencuesta
      AND rc.idCategoria IS NOT NULL
    ORDER BY (o.generaPqr = 1) DESC, rc.idCategoria ASC
    LIMIT 1;

    /* ===== 4.1) TIPO principal (dinámico desde detonantes) ===== */
    SELECT tp.idTipo, tp.nombre
      INTO v_idtiporeclamo, v_nom_tipo_reclamo
    FROM respuestasCliente     rc
    JOIN tmp_detonantes        d  ON d.idPregunta = rc.idPregunta
    LEFT JOIN respuestasOpciones o ON o.idOpcion  = rc.idOpcion
    JOIN categoriasPqrs        cp ON cp.idCategoria = rc.idCategoria
    JOIN tiposPqrs             tp ON tp.idTipo      = cp.idTipo
    WHERE rc.idProgEncuesta = p_idprogencuesta
    ORDER BY (o.generaPqr = 1) DESC, rc.idCategoria ASC
    LIMIT 1;

    /* Fallback por seguridad */
    IF v_idtiporeclamo IS NULL THEN
      SELECT idTipo, nombre INTO v_idtiporeclamo, v_nom_tipo_reclamo
      FROM tiposPqrs WHERE nombre='Reclamo' LIMIT 1;
    END IF;

    /* ===== 4.2) ASUNTO (dinámico) ===== */
    SELECT GROUP_CONCAT(
             CONCAT(
               COALESCE(tp.nombre, v_nom_tipo_reclamo),
               CASE WHEN COALESCE(NULLIF(TRIM(rc.comentario),''),'') <> '' THEN ' ' ELSE '' END,
               COALESCE(NULLIF(TRIM(rc.comentario),''),'')
             )
             ORDER BY rc.idPregunta
             SEPARATOR ' | '
           )
      INTO v_asunto
    FROM respuestasCliente rc
    JOIN tmp_detonantes d   ON d.idPregunta = rc.idPregunta
    LEFT JOIN categoriasPqrs cp ON cp.idCategoria = rc.idCategoria
    LEFT JOIN tiposPqrs     tp ON tp.idTipo      = cp.idTipo
    WHERE rc.idProgEncuesta = p_idprogencuesta;

    SET v_asunto := COALESCE(v_asunto, 'PQR generado desde encuesta');

    /* ===== 4.3) DETALLE (incluye ATENCIÓN al final) =====
       - Por detonante: <pregunta>: <valorTexto>, <tipo>, <comentario>
       - Luego:  | Atención: <tipoDocumento>, <numeroDocumento>, <numeroFactura>, <detalleAtencion> */
    SELECT GROUP_CONCAT(
             CONCAT(
               COALESCE(NULLIF(TRIM(pq.texto),''),'(Sin pregunta)'),
               ': ',
               TRIM(CONCAT_WS(', ',
                   NULLIF(TRIM(rc.valorTexto),''),         -- valor de la respuesta
                   COALESCE(tp.nombre, v_nom_tipo_reclamo),-- tipo/categoría
                   NULLIF(TRIM(rc.comentario),'')          -- comentario
               ))
             )
             ORDER BY rc.idPregunta
             SEPARATOR ' | '
           )
      INTO v_det_body
    FROM respuestasCliente rc
    JOIN tmp_detonantes d   ON d.idPregunta = rc.idPregunta
    JOIN preguntas      pq  ON pq.idPregunta = rc.idPregunta
    LEFT JOIN categoriasPqrs cp ON cp.idCategoria = rc.idCategoria
    LEFT JOIN tiposPqrs     tp ON tp.idTipo      = cp.idTipo
    WHERE rc.idProgEncuesta = p_idprogencuesta;

    SET v_det_atn_block = TRIM(CONCAT(
      'Atención: ',
      TRIM(CONCAT_WS(', ',
        NULLIF(TRIM(v_tipoDoc), ''),
        NULLIF(TRIM(v_numDoc), ''),
        NULLIF(TRIM(v_numFactura), ''),
        NULLIF(TRIM(v_det_atencion), '')
      ))
    ));

    SET v_detalle = CONCAT(
      'Cliente: ', COALESCE(v_cliente,'(sin nombre)'), ' - ',
      COALESCE(v_det_body, 'PQR creado por respuestas negativas en encuesta.'),
      CASE WHEN v_det_atn_block IS NOT NULL AND v_det_atn_block <> 'Atención:'
           AND SUBSTRING_INDEX(v_det_atn_block, 'Atención: ', -1) <> ''
           THEN CONCAT(' | ', v_det_atn_block)
           ELSE '' END
    );

    /* ===== 5) SLA nivel 1 ===== */
    SELECT ce.horasSLA
      INTO v_horassla_n1
    FROM config_escalamiento ce
    WHERE ce.idAgencia  = v_idagencia
      AND ce.idEncuesta = v_idencuesta
      AND ce.nivel      = 1
    LIMIT 1;

    /* ===== 6) Crear PQR ===== */
    SET v_codigo = fn_siguiente_codigo_pqrs();

    INSERT INTO pqrs (
      codigo, idTipo, idCategoria, idCanal, idEstado, idAgencia, idCliente,
      idEncuesta, idProgEncuesta, asunto, detalle, nivelActual, fechaLimiteNivel, estadoRegistro
    )
    VALUES (
      v_codigo, v_idtiporeclamo, v_idcategoriasel, v_idcanal, v_idestadoabierto, v_idagencia, v_idcliente,
      v_idencuesta, p_idprogencuesta,
      v_asunto, v_detalle,
      1,
      DATE_ADD(NOW(), INTERVAL COALESCE(v_horassla_n1, 24) HOUR),
      1
    );

    SET v_idpqrs = LAST_INSERT_ID();

    /* ===== 7) Trazabilidad: preguntas detonantes ===== */
    INSERT INTO pqrs_preguntas (idPqrs, idPregunta, idCategoria)
    SELECT v_idpqrs, rc.idPregunta, rc.idCategoria
    FROM respuestasCliente rc
    JOIN tmp_detonantes d ON d.idPregunta = rc.idPregunta
    WHERE rc.idProgEncuesta = p_idprogencuesta
      AND rc.idCategoria IS NOT NULL;

    /* ===== 8) Responsables por config_escalamiento ===== */
    INSERT INTO pqrs_responsables (idPqrs, nivel, idResponsable, horasSLA)
    SELECT v_idpqrs, ce.nivel, ce.idResponsable, ce.horasSLA
    FROM config_escalamiento ce
    WHERE ce.idEncuesta = v_idencuesta
      AND ce.idAgencia  = v_idagencia
    ORDER BY ce.nivel ASC;

    /* ===== 9) Marcar la instancia ===== */
    UPDATE encuestasProgramadas
       SET estadoEnvio='RESPONDIDA', fechaActualizacion=NOW()
     WHERE idProgEncuesta = p_idprogencuesta;

    SET SESSION group_concat_max_len = @old_gcml;
    COMMIT;
  END IF;

  DROP TEMPORARY TABLE IF EXISTS tmp_detonantes;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_marcar_envio_manual` (IN `p_idProgEncuesta` BIGINT, IN `p_idUsuario` INT, IN `p_canal` ENUM('EMAIL','WHATSAPP','SMS','OTRO'), IN `p_asunto` VARCHAR(200), IN `p_cuerpo` MEDIUMTEXT, IN `p_observacion` VARCHAR(255))   BEGIN
  UPDATE encuestasProgramadas ep
  JOIN encuestas e ON e.idEncuesta = ep.idEncuesta
  JOIN clientes  c ON c.idCliente  = ep.idCliente
     SET ep.enviadoPor       = p_idUsuario,
         ep.canalEnvio       = p_canal,
         ep.observacionEnvio = p_observacion,
         ep.ultimoEnvio      = NOW(),
         ep.intentosEnviados = ep.intentosEnviados + 1,
         ep.asuntoCache      = IFNULL(p_asunto, e.asuntoCorreo),
         ep.cuerpoHtmlCache  = IFNULL(p_cuerpo, CONCAT(
                                  e.scriptInicio,
                                  '<p><a href="https://app.imbauto.com.ec/encuesta?token=', ep.tokenEncuesta, '">Responder encuesta</a></p>',
                                  IFNULL(e.scriptFinal,'')
                                )),
         ep.estadoEnvio      = 'ENVIADA',
         ep.fechaActualizacion = NOW()
   WHERE ep.idProgEncuesta = p_idProgEncuesta
     AND c.estado = 1
     AND c.bloqueadoEncuestas = 0;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_marcar_no_contestada` (IN `p_idProgEncuesta` BIGINT)   BEGIN
  UPDATE encuestasProgramadas
     SET estadoEnvio = 'NO_CONTESTADA',
         fechaActualizacion = NOW()
   WHERE idProgEncuesta = p_idProgEncuesta
     AND estadoEnvio IN ('PENDIENTE','ENVIADA');
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_programar_despues_de_upsert_auto` (IN `p_idAtencion` BIGINT, IN `p_canalEnvio` VARCHAR(16))   BEGIN
  DECLARE v_idCliente  BIGINT;
  DECLARE v_idCanal    INT;
  DECLARE v_fechaAt    DATE;
  DECLARE v_idEncuesta BIGINT;
  DECLARE v_asunto     VARCHAR(200);
  DECLARE v_ini        MEDIUMTEXT;
  DECLARE v_fin        MEDIUMTEXT;
  DECLARE v_prog       DATETIME;
  DECLARE v_token      VARCHAR(64);

  /* 1) Atención vigente + canal */
  SELECT a.idCliente, a.idCanal, a.fechaAtencion
    INTO v_idCliente, v_idCanal, v_fechaAt
  FROM atenciones a
  WHERE a.idAtencion = p_idAtencion
    AND a.estado = 1
  LIMIT 1;

  IF v_idCliente IS NULL OR v_idCanal IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Atención inexistente/inactiva o sin idCanal.';
  END IF;

  /* 2) Encuesta activa del mismo canal (más reciente) */
  SELECT e.idEncuesta, e.asuntoCorreo, e.scriptInicio, e.scriptFinal
    INTO v_idEncuesta, v_asunto, v_ini, v_fin
  FROM encuestas e
  WHERE e.idCanal = v_idCanal AND e.activa = 1
  ORDER BY e.idEncuesta DESC
  LIMIT 1;

  IF v_idEncuesta IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay encuesta activa para el canal de la atención.';
  END IF;

  /* 3) Cliente apto (y si canalEnvio=EMAIL, email no vacío) */
  IF NOT EXISTS (
      SELECT 1 FROM clientes c
      WHERE c.idCliente = v_idCliente
        AND c.estado = 1
        AND c.bloqueadoEncuestas = 0
        AND (UPPER(p_canalEnvio) <> 'EMAIL' OR (c.email IS NOT NULL AND c.email <> ''))
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cliente no apto (inactivo/bloqueado o sin email).';
  END IF;

  /* 4) Programación (+3 días @ 09:00) y token */
  SET v_prog  = STR_TO_DATE(CONCAT(DATE_ADD(v_fechaAt, INTERVAL 3 DAY), ' 09:00:00'), '%Y-%m-%d %H:%i:%s');
  SET v_token = SUBSTRING(SHA2(CONCAT(p_idAtencion,'-',v_idEncuesta,'-',UUID()),256),1,48);

  /* 5) Insert si no existe aún para (idEncuesta,idAtencion) */
  INSERT INTO encuestasprogramadas (
    idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
    intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio,
    canalEnvio, asuntoCache, cuerpoHtmlCache, tokenEncuesta, estado
  )
  SELECT
    v_idEncuesta, p_idAtencion, v_idCliente, v_prog,
    0, 3, v_prog, 'PENDIENTE',
    UPPER(p_canalEnvio), v_asunto,
    CONCAT(v_ini,'<p><a href="https://app.imbauto.com.ec/encuesta?token=', v_token, '">Responder encuesta</a></p>',IFNULL(v_fin,'')),
    v_token, 1
  FROM DUAL
  WHERE NOT EXISTS (
    SELECT 1 FROM encuestasprogramadas ep
    WHERE ep.idEncuesta = v_idEncuesta AND ep.idAtencion = p_idAtencion
  );

  /* Retorno */
  SELECT ep.idProgEncuesta AS idProgEncuesta
  FROM encuestasprogramadas ep
  WHERE ep.idEncuesta = v_idEncuesta AND ep.idAtencion = p_idAtencion
  LIMIT 1;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_programar_despues_de_upsert_idem` (IN `p_idEncuesta` BIGINT, IN `p_idAtencion` BIGINT, IN `p_canal` VARCHAR(16))   BEGIN
  DECLARE v_idCliente BIGINT;
  DECLARE v_fechaAtencion DATE;
  DECLARE v_token VARCHAR(64);
  DECLARE v_prog TIMESTAMP;
  DECLARE v_asunto VARCHAR(200);
  DECLARE v_ini MEDIUMTEXT;
  DECLARE v_fin MEDIUMTEXT;
  DECLARE v_inserted INT DEFAULT 0;
  DECLARE v_idProg BIGINT;

  DECLARE exit handler for sqlexception
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  /* 1) Atención vigente */
  SELECT a.idCliente, a.fechaAtencion
    INTO v_idCliente, v_fechaAtencion
  FROM atenciones a
  WHERE a.idAtencion = p_idAtencion
    AND a.estado = 1
  LIMIT 1;

  IF v_idCliente IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Atención inexistente o inactiva';
  END IF;

  /* 2) Encuesta activa */
  SELECT e.asuntoCorreo, e.scriptInicio, e.scriptFinal
    INTO v_asunto, v_ini, v_fin
  FROM encuestas e
  WHERE e.idEncuesta = p_idEncuesta
    AND e.activa = 1
  LIMIT 1;

  IF v_asunto IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Encuesta inexistente o inactiva';
  END IF;

  /* 3) Cliente apto (y email si canal=EMAIL) */
  IF NOT EXISTS (
      SELECT 1
      FROM clientes c
      WHERE c.idCliente = v_idCliente
        AND c.estado = 1
        AND c.bloqueadoEncuestas = 0
        AND (UPPER(p_canal) <> 'EMAIL' OR c.email <> '')
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Cliente no apto para envío (bloqueado/inactivo o email vacío)';
  END IF;

  /* 4) Programación: +3 días @ 09:00 y token */
  SET v_prog  = DATE_ADD(CONCAT(v_fechaAtencion, ' 09:00:00'), INTERVAL 3 DAY);
  SET v_token = SUBSTRING(SHA2(CONCAT(p_idAtencion,'-',UUID()),256),1,48);

  /* 5) Insert idempotente */
  INSERT IGNORE INTO encuestasprogramadas (
    idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
    intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio,
    canalEnvio, asuntoCache, cuerpoHtmlCache, tokenEncuesta, estado
  )
  SELECT
    p_idEncuesta,
    a.idAtencion,
    a.idCliente,
    v_prog,
    0, 3,
    v_prog,
    'PENDIENTE',
    UPPER(p_canal),
    v_asunto,
    CONCAT(
      v_ini,
      '<p><a href="https://app.imbauto.com.ec/encuesta?token=', v_token, '">Responder encuesta</a></p>',
      IFNULL(v_fin,'')
    ),
    v_token,
    1
  FROM atenciones a
  WHERE a.idAtencion = p_idAtencion
  LIMIT 1;

  SET v_inserted = ROW_COUNT();

  /* 6) Obtener el id (nuevo o existente) */
  SELECT ep.idProgEncuesta
    INTO v_idProg
  FROM encuestasprogramadas ep
  WHERE ep.idEncuesta = p_idEncuesta
    AND ep.idAtencion = p_idAtencion
  LIMIT 1;

  COMMIT;

  /* Salida estándar */
  SELECT v_idProg AS idProgEncuesta,
         CASE WHEN v_inserted = 1 THEN 'CREADO' ELSE 'YA_EXISTIA' END AS status;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_programar_encuestas_completo` (IN `p_idEncuesta` BIGINT, IN `p_fecha_desde` DATE, IN `p_fecha_hasta` DATE, IN `p_canal` ENUM('EMAIL','WHATSAPP','SMS','OTRO'))   BEGIN
  /* Inserta ep para atenciones del rango, evitando duplicado por (encuesta, atencion),
     solo clientes habilitados y (si es EMAIL) con email válido. */

  INSERT INTO encuestasProgramadas(
    idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
    intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio,
    canalEnvio, asuntoCache, cuerpoHtmlCache, tokenEncuesta, estado
  )
  SELECT
    p_idEncuesta,
    t.idAtencion,
    t.idCliente,
    t.f_prog,               -- fechaProgramadaInicial
    0,                      -- intentosEnviados
    3,                      -- maxIntentos
    t.f_prog,               -- proximoEnvio
    'PENDIENTE',            -- estadoEnvio
    p_canal,                -- canalEnvio
    e.asuntoCorreo,         -- asuntoCache
    CONCAT(
      e.scriptInicio,
      '<p><a href="https://app.imbauto.com.ec/encuesta?token=', t.token, '">Responder encuesta</a></p>',
      IFNULL(e.scriptFinal,'')
    ) AS cuerpoHtmlCache,   -- cuerpoHtmlCache
    t.token,                -- tokenEncuesta
    1                       -- estado
  FROM (
    /* Precalcula fecha programada y token una sola vez por fila */
    SELECT
      a.idAtencion,
      a.idCliente,
      DATE_ADD(DATE_ADD(a.fechaAtencion, INTERVAL 3 DAY), INTERVAL 9 HOUR) AS f_prog,
      SUBSTRING(SHA2(CONCAT(a.idAtencion,'-',UUID()),256),1,48) AS token
    FROM atenciones a
    WHERE a.estado = 1
      AND a.fechaAtencion >= p_fecha_desde
      AND a.fechaAtencion <  DATE_ADD(p_fecha_hasta, INTERVAL 1 DAY)
  ) AS t
  JOIN clientes c   ON c.idCliente  = t.idCliente
  JOIN encuestas e  ON e.idEncuesta = p_idEncuesta
  LEFT JOIN encuestasProgramadas ep
         ON ep.idAtencion = t.idAtencion
        AND ep.idEncuesta = p_idEncuesta   -- evita duplicar la misma encuesta para la misma atención
  WHERE ep.idProgEncuesta IS NULL
    AND c.estado = 1
    AND c.bloqueadoEncuestas = 0
    AND (p_canal <> 'EMAIL' OR (c.email IS NOT NULL AND c.email <> ''));

END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_programar_encuestas_por_encuesta` (IN `p_idEncuesta` BIGINT)   BEGIN
  INSERT INTO encuestasProgramadas(
    idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
    intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio, tokenEncuesta
  )
  SELECT
    p_idEncuesta, a.idAtencion, a.idCliente,
    DATE_ADD(DATE_ADD(a.fechaAtencion, INTERVAL 3 DAY), INTERVAL 9 HOUR),
    0, 3,
    DATE_ADD(DATE_ADD(a.fechaAtencion, INTERVAL 3 DAY), INTERVAL 9 HOUR),
    'PENDIENTE',
    SUBSTRING(SHA2(CONCAT(a.idAtencion,'-',UUID()),256),1,48)
  FROM atenciones a
  LEFT JOIN encuestasProgramadas ep ON ep.idAtencion = a.idAtencion
  WHERE ep.idProgEncuesta IS NULL
    AND a.estado = 1;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_programar_ep_por_rango` (IN `p_idEncuesta` BIGINT, IN `p_canal` VARCHAR(16), IN `p_fecha_desde` DATE, IN `p_fecha_hasta` DATE, IN `p_offset_dias` INT, IN `p_hora_programada` TIME)   BEGIN
  DECLARE v_dummy INT;

  /* Validar encuesta activa */
  IF NOT EXISTS (
    SELECT 1 FROM encuestas e
     WHERE e.idEncuesta = p_idEncuesta AND e.activa = 1
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Encuesta inexistente o inactiva';
  END IF;

  /* Insertar SOLO las atenciones del rango que aún no tienen EP para esta encuesta */
  INSERT INTO encuestasprogramadas (
      idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
      intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio,
      canalEnvio, asuntoCache, cuerpoHtmlCache, tokenEncuesta, estado
  )
  SELECT
      e.idEncuesta,
      a.idAtencion,
      c.idCliente,
      STR_TO_DATE(CONCAT(DATE_ADD(a.fechaAtencion, INTERVAL p_offset_dias DAY), ' ', p_hora_programada), '%Y-%m-%d %H:%i:%s') AS f_prog,
      0, 3,
      STR_TO_DATE(CONCAT(DATE_ADD(a.fechaAtencion, INTERVAL p_offset_dias DAY), ' ', p_hora_programada), '%Y-%m-%d %H:%i:%s') AS proximoEnvio,
      'PENDIENTE',
      UPPER(p_canal),
      e.asuntoCorreo,
      CONCAT(
        e.scriptInicio,
        '<p><a href="https://app.imbauto.com.ec/encuesta?token=',
        SUBSTRING(SHA2(CONCAT(a.idAtencion,'-',p_idEncuesta,'-',UUID()),256),1,48),
        '">Responder encuesta</a></p>',
        IFNULL(e.scriptFinal,'')
      ) AS cuerpoHtmlCache,
      /* tokenEncuesta (mismo cálculo que en el cuerpo) */
      SUBSTRING(SHA2(CONCAT(a.idAtencion,'-',p_idEncuesta,'-',UUID()),256),1,48) AS tokenEncuesta,
      1
  FROM atenciones a
  JOIN clientes c  ON c.idCliente  = a.idCliente
  JOIN encuestas e ON e.idEncuesta = p_idEncuesta
  WHERE a.estado = 1
    AND c.estado = 1
    AND c.bloqueadoEncuestas = 0
    AND a.fechaAtencion >= p_fecha_desde
    AND a.fechaAtencion <  DATE_ADD(p_fecha_hasta, INTERVAL 1 DAY)
    AND (UPPER(p_canal) <> 'EMAIL' OR (c.email IS NOT NULL AND c.email <> ''))
    /* Evitar duplicado por par (encuesta, atencion) */
    AND NOT EXISTS (
      SELECT 1
      FROM encuestasprogramadas ep
      WHERE ep.idEncuesta = e.idEncuesta
        AND ep.idAtencion = a.idAtencion
    );

  /* Resultado simple: filas insertadas */
  SELECT ROW_COUNT() AS filas_programadas;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_programar_ep_por_rango_autoencuesta` (IN `p_canalEnvio` VARCHAR(16), IN `p_fecha_desde` DATE, IN `p_fecha_hasta` DATE, IN `p_offset_dias` INT, IN `p_hora_programada` TIME)   BEGIN
  /* Inserta una EP por atención si:
     - atención y cliente vigentes
     - hay ENCUESTA ACTIVA del mismo canal de la atención
     - no existe EP (idEncuesta,idAtencion) aún
     - si p_canalEnvio=EMAIL: email del cliente no vacío
  */
  INSERT INTO encuestasprogramadas (
    idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
    intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio,
    canalEnvio, asuntoCache, cuerpoHtmlCache, tokenEncuesta, estado
  )
  SELECT
    e.idEncuesta,
    a.idAtencion,
    c.idCliente,
    STR_TO_DATE(CONCAT(DATE_ADD(a.fechaAtencion, INTERVAL p_offset_dias DAY), ' ', p_hora_programada), '%Y-%m-%d %H:%i:%s') AS f_prog,
    0, 3,
    STR_TO_DATE(CONCAT(DATE_ADD(a.fechaAtencion, INTERVAL p_offset_dias DAY), ' ', p_hora_programada), '%Y-%m-%d %H:%i:%s'),
    'PENDIENTE',
    UPPER(p_canalEnvio),
    e.asuntoCorreo,
    CONCAT(
      e.scriptInicio,
      '<p><a href="https://app.imbauto.com.ec/encuesta?token=',
      SUBSTRING(SHA2(CONCAT(a.idAtencion,'-',e.idEncuesta,'-',UUID()),256),1,48),
      '">Responder encuesta</a></p>',
      IFNULL(e.scriptFinal,'')
    ),
    SUBSTRING(SHA2(CONCAT(a.idAtencion,'-',e.idEncuesta,'-',UUID()),256),1,48),
    1
  FROM atenciones a
  JOIN clientes  c ON c.idCliente  = a.idCliente
  JOIN (
    /* encuesta ACTIVA más reciente por canal */
    SELECT e1.*
    FROM encuestas e1
    WHERE e1.activa = 1
  ) e ON e.idCanal = a.idCanal
  WHERE a.estado = 1
    AND c.estado = 1
    AND c.bloqueadoEncuestas = 0
    AND a.idCanal IS NOT NULL
    AND a.fechaAtencion >= p_fecha_desde
    AND a.fechaAtencion <  DATE_ADD(p_fecha_hasta, INTERVAL 1 DAY)
    AND (UPPER(p_canalEnvio) <> 'EMAIL' OR (c.email IS NOT NULL AND c.email <> ''))
    AND NOT EXISTS (
      SELECT 1
      FROM encuestasprogramadas ep
      WHERE ep.idEncuesta = e.idEncuesta
        AND ep.idAtencion = a.idAtencion
    );
  SELECT ROW_COUNT() AS filas_programadas;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_programar_por_atencion` (IN `p_idEncuesta` BIGINT, IN `p_idAtencion` BIGINT)   BEGIN
  INSERT INTO encuestasProgramadas(
    idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
    intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio, tokenEncuesta
  )
  SELECT
    p_idEncuesta, a.idAtencion, a.idCliente,
    DATE_ADD(DATE_ADD(a.fechaAtencion, INTERVAL 3 DAY), INTERVAL 9 HOUR),
    0, 3,
    DATE_ADD(DATE_ADD(a.fechaAtencion, INTERVAL 3 DAY), INTERVAL 9 HOUR),
    'PENDIENTE',
    SUBSTRING(SHA2(CONCAT(a.idAtencion,'-',UUID()),256),1,48)
  FROM atenciones a
  LEFT JOIN encuestasProgramadas ep ON ep.idAtencion = a.idAtencion
  WHERE a.idAtencion = p_idAtencion
    AND ep.idProgEncuesta IS NULL;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_programar_por_fechas_idem` (IN `p_idEncuesta` BIGINT, IN `p_fecha_desde` DATE, IN `p_fecha_hasta` DATE, IN `p_canal` VARCHAR(16), IN `p_idAgencia` INT, IN `p_dias_offset` INT, IN `p_hora_envio` TIME)   BEGIN
  DECLARE v_asunto VARCHAR(200);
  DECLARE v_ini MEDIUMTEXT;
  DECLARE v_fin MEDIUMTEXT;
  DECLARE v_insertados INT DEFAULT 0;

  DECLARE exit handler for sqlexception
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  /* 0) Validar encuesta activa y obtener datos de correo */
  SELECT e.asuntoCorreo, e.scriptInicio, e.scriptFinal
    INTO v_asunto, v_ini, v_fin
  FROM encuestas e
  WHERE e.idEncuesta = p_idEncuesta
    AND e.activa = 1
  LIMIT 1;

  IF v_asunto IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Encuesta inexistente o inactiva';
  END IF;

  /* 1) Insert idempotente (evita duplicados por NOT EXISTS y/o UNIQUE) */
  INSERT INTO encuestasprogramadas (
    idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
    intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio,
    canalEnvio, asuntoCache, cuerpoHtmlCache, tokenEncuesta, estado
  )
  SELECT
    p_idEncuesta,
    a_wrap.idAtencion,
    a_wrap.idCliente,
    a_wrap.f_prog,
    0, 3,
    a_wrap.f_prog,
    'PENDIENTE',
    UPPER(p_canal),
    v_asunto,
    CONCAT(
      v_ini,
      '<p><a href="https://app.imbauto.com.ec/encuesta?token=', a_wrap.token_gen, '">Responder encuesta</a></p>',
      IFNULL(v_fin,'')
    ),
    a_wrap.token_gen,
    1
  FROM (
    /* Precalcular fecha programada y token por atención */
    SELECT
      a.idAtencion,
      a.idCliente,
      DATE_ADD(
        TIMESTAMP(CONCAT(DATE(a.fechaAtencion), ' ', p_hora_envio)),
        INTERVAL p_dias_offset DAY
      ) AS f_prog,
      SUBSTRING(SHA2(CONCAT(a.idAtencion, '-', UUID()), 256), 1, 48) AS token_gen
    FROM atenciones a
    WHERE a.estado = 1
      AND a.fechaAtencion >= p_fecha_desde
      AND a.fechaAtencion <  DATE_ADD(p_fecha_hasta, INTERVAL 1 DAY)
      AND (p_idAgencia IS NULL OR a.idAgencia = p_idAgencia)
  ) AS a_wrap
  JOIN clientes c ON c.idCliente = a_wrap.idCliente
  WHERE c.estado = 1
    AND c.bloqueadoEncuestas = 0
    AND (UPPER(p_canal) <> 'EMAIL' OR (c.email IS NOT NULL AND c.email <> ''))
    AND NOT EXISTS (
      SELECT 1
      FROM encuestasprogramadas ep
      WHERE ep.idEncuesta = p_idEncuesta
        AND ep.idAtencion = a_wrap.idAtencion
    );

  SET v_insertados = ROW_COUNT();

  COMMIT;

  /* Resultado para el caller */
  SELECT v_insertados AS total_insertados, 'OK' AS status;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_reabrir_encuesta` (IN `p_idProgEncuesta` BIGINT, IN `p_reset_intentos` TINYINT, IN `p_idUsuario` INT, IN `p_observacion` VARCHAR(255))   BEGIN
  /* Solo reabrimos si estaba NO_CONTESTADA o EXCLUIDA.
     No tocamos RESPONDIDA. */
  IF p_reset_intentos = 1 THEN
    UPDATE encuestasProgramadas
       SET estadoEnvio        = 'PENDIENTE',
           ultimoEnvio        = NULL,
           canalEnvio         = NULL,
           enviadoPor         = p_idUsuario,
           observacionEnvio   = CONCAT('[REABRIR] ', IFNULL(p_observacion,'')),
           intentosEnviados   = 0,
           fechaActualizacion = NOW()
     WHERE idProgEncuesta = p_idProgEncuesta
       AND estadoEnvio IN ('NO_CONTESTADA','EXCLUIDA');
  ELSE
    UPDATE encuestasProgramadas
       SET estadoEnvio        = 'PENDIENTE',
           ultimoEnvio        = NULL,
           canalEnvio         = NULL,
           enviadoPor         = p_idUsuario,
           observacionEnvio   = CONCAT('[REABRIR] ', IFNULL(p_observacion,'')),
           fechaActualizacion = NOW()
     WHERE idProgEncuesta = p_idProgEncuesta
       AND estadoEnvio IN ('NO_CONTESTADA','EXCLUIDA');
  END IF;

  /* Devuelve el registro reabierto (si aplicó) */
  SELECT ep.*
    FROM encuestasProgramadas ep
   WHERE ep.idProgEncuesta = p_idProgEncuesta;
END$$

CREATE DEFINER=`` PROCEDURE `sp_registrar_consentimiento` (IN `p_idProgEncuesta` BIGINT, IN `p_acepta` TINYINT, IN `p_ip` VARCHAR(45), IN `p_userAgent` VARCHAR(255), IN `p_firmaToken` VARCHAR(255))  MODIFIES SQL DATA BEGIN
  DECLARE v_idCliente     BIGINT;
  DECLARE v_prev_acepta   TINYINT;
  DECLARE v_idEncuesta    BIGINT;
  DECLARE v_nomEncuesta   VARCHAR(255);
  DECLARE v_userAgent     VARCHAR(255);
  DECLARE v_now           DATETIME;
  DECLARE v_msg           TEXT;

  /* Handler de errores */
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK; RESIGNAL;
  END;

  START TRANSACTION;

  /* 1) Validar entrada p_acepta */
  IF p_acepta IS NULL OR p_acepta NOT IN (0,1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p_acepta debe ser 0 o 1.';
  END IF;

  /* 2) Cargar y BLOQUEAR la EP + datos de encuesta para construir userAgent */
  SELECT ep.idCliente,
         ep.consentimientoAceptado,
         e.idEncuesta,
         e.nombre
    INTO v_idCliente,
         v_prev_acepta,
         v_idEncuesta,
         v_nomEncuesta
  FROM encuestasProgramadas ep
  JOIN encuestas e ON e.idEncuesta = ep.idEncuesta
  WHERE ep.idProgEncuesta = p_idProgEncuesta
  FOR UPDATE;

  IF v_idCliente IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'idProgEncuesta no existe.';
  END IF;

  /* Construir userAgent requerido: "ENCUESTA: <idEncuesta>-<nombre>" */
  SET v_userAgent := CONCAT('ENCUESTA: ', v_idEncuesta, '-', COALESCE(NULLIF(TRIM(v_nomEncuesta), ''), '(sin nombre)'));

  /* 3) Idempotencia (mismo valor ya registrado) */
--   IF v_prev_acepta IS NOT NULL AND v_prev_acepta = p_acepta THEN
--     SET v_msg = CONCAT('Consentimiento ya registrado (', v_prev_acepta,
--                        ') para idProgEncuesta=', p_idProgEncuesta, '.');
--     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
--   END IF;

  /* 4) Timestamp único para ambas tablas */
  SET v_now = NOW();

  /* 5) Log en clientes_consentimientos (guarda el token en userAgent) */
  INSERT INTO clientes_consentimientos (idCliente, aceptado, origen, ip, userAgent, fecha)
  VALUES (v_idCliente, p_acepta, 'ENCUESTA', p_ip, p_firmaToken, v_now);

  /* 6) Actualizar encuestasProgramadas con la MISMA fecha y observaciones=userAgent construido */
  UPDATE encuestasProgramadas
     SET consentimientoAceptado = p_acepta,
         fechaConsentimiento    = v_now,
         observaciones          = v_userAgent,
         estadoEnvio            = IF(p_acepta = 1, estadoEnvio, 'EXCLUIDA'),
         fechaActualizacion     = NOW()
   WHERE idProgEncuesta = p_idProgEncuesta;

  /* 7) Actualizar ficha del cliente */
  IF p_acepta = 1 THEN
    UPDATE clientes
       SET consentimientoDatos = 1,
           bloqueadoEncuestas  = 0,
           fechaConsentimiento = v_now,
           fechaActualizacion  = NOW()
     WHERE idCliente = v_idCliente;
  ELSE
    UPDATE clientes
       SET consentimientoDatos = 0,
           bloqueadoEncuestas  = 1,
           fechaConsentimiento = v_now,
           fechaActualizacion  = NOW()
     WHERE idCliente = v_idCliente;
  END IF;

  COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_registrar_respuesta` (IN `p_idProgEncuesta` BIGINT, IN `p_idPregunta` INT, IN `p_idOpcion` BIGINT, IN `p_valorNumerico` INT, IN `p_valorTexto` VARCHAR(255), IN `p_comentario` TEXT)   BEGIN
  INSERT INTO respuestasCliente (idProgEncuesta, idPregunta, idOpcion, valorNumerico, valorTexto, comentario, fechaRespuesta)
  VALUES (p_idProgEncuesta, p_idPregunta, p_idOpcion, p_valorNumerico, p_valorTexto, p_comentario, NOW())
  ON DUPLICATE KEY UPDATE
    idOpcion = VALUES(idOpcion),
    valorNumerico = VALUES(valorNumerico),
    valorTexto = VALUES(valorTexto),
    comentario = VALUES(comentario),
    fechaRespuesta = NOW();
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_simular_seguimientos` (IN `p_idpqrs` BIGINT, IN `p_escalar` TINYINT, IN `p_cerrar` TINYINT, IN `p_h1` INT, IN `p_h2` INT, IN `p_h3` INT, IN `p_h4` INT, IN `p_h5` INT, IN `p_text1` VARCHAR(500), IN `p_text2` VARCHAR(500), IN `p_text3` VARCHAR(500), IN `p_text4` VARCHAR(500), IN `p_text5` VARCHAR(500), IN `p_adj4` VARCHAR(500), IN `p_nivel_destino` INT, IN `p_usuario_n1` INT, IN `p_usuario_n2` INT)   BEGIN
  /* ===== Variables ===== */
  DECLARE v_idencuesta      BIGINT;
  DECLARE v_idagencia       INT;
  DECLARE v_nivel_actual    INT;
  DECLARE v_st_proceso      INT;
  DECLARE v_st_escalado     INT;
  DECLARE v_st_cerrado      INT;

  DECLARE v_st_curr         INT;  -- estado vigente del PQR en memoria

  DECLARE v_u_n1            INT;
  DECLARE v_u_n2            INT;
  DECLARE v_u_any           INT;

  DECLARE v_t1 DATETIME;  DECLARE v_t2 DATETIME;  DECLARE v_t3 DATETIME;
  DECLARE v_t4 DATETIME;  DECLARE v_t5 DATETIME;

  DECLARE v_hh1 INT; DECLARE v_hh2 INT; DECLARE v_hh3 INT; DECLARE v_hh4 INT; DECLARE v_hh5 INT;
  DECLARE v_txt1 VARCHAR(500); DECLARE v_txt2 VARCHAR(500); DECLARE v_txt3 VARCHAR(500);
  DECLARE v_txt4 VARCHAR(500); DECLARE v_txt5 VARCHAR(500); DECLARE v_adj4 VARCHAR(500);

  DECLARE v_escalar  TINYINT; DECLARE v_cerrar TINYINT;

  DECLARE v_niv_sig         INT;
  DECLARE v_sla_sig         INT;
  DECLARE v_niv_max_pr      INT;
  DECLARE v_niv_max_cfg     INT;
  DECLARE v_niv_max         INT;

  /* Handler */
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK; RESIGNAL;
  END;

  START TRANSACTION;

  /* ===== 1) Contexto del PQR ===== */
  SELECT idEncuesta, idAgencia, nivelActual, idEstado
    INTO v_idencuesta, v_idagencia, v_nivel_actual, v_st_curr
  FROM pqrs
  WHERE idPqrs = p_idpqrs
  LIMIT 1;

  IF v_idencuesta IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_simular_seguimientos: PQR no existe.';
  END IF;

  /* Estados */
  SET v_st_proceso  := (SELECT idEstado FROM estadosPqrs WHERE nombre='EN_PROCESO' LIMIT 1);
  SET v_st_escalado := (SELECT idEstado FROM estadosPqrs WHERE nombre='ESCALADO'   LIMIT 1);
  SET v_st_cerrado  := (SELECT idEstado FROM estadosPqrs WHERE nombre='CERRADO'    LIMIT 1);

  /* Si YA está CERRADO, no hacer nada */
  IF v_st_curr = v_st_cerrado THEN
    COMMIT;
  ELSE
    /* ===== 2) Defaults ===== */
    SET v_escalar := COALESCE(p_escalar, 1);
    SET v_cerrar  := COALESCE(p_cerrar,  1);
    SET v_hh1 := COALESCE(p_h1, 48);
    SET v_hh2 := COALESCE(p_h2, 44);
    SET v_hh3 := COALESCE(p_h3, 24);
    SET v_hh4 := COALESCE(p_h4, 12);
    SET v_hh5 := COALESCE(p_h5,  1);
    SET v_txt1 := COALESCE(p_text1, 'Se contactó al cliente y se levantó el caso.');
    SET v_txt2 := COALESCE(p_text2, 'Se solicitó evidencia al asesor responsable.');
    SET v_txt3 := COALESCE(p_text3, 'Escalado a nivel superior por cumplimiento de SLA.');
    SET v_txt4 := COALESCE(p_text4, 'Recibida documentación. En análisis con responsable de nivel 2.');
    SET v_txt5 := COALESCE(p_text5, 'Caso resuelto y validado con el cliente.');
    SET v_adj4 := COALESCE(p_adj4,  'docs/soporte.pdf');

    SET v_t1 := NOW() - INTERVAL v_hh1 HOUR;
    SET v_t2 := NOW() - INTERVAL v_hh2 HOUR;
    SET v_t3 := NOW() - INTERVAL v_hh3 HOUR;
    SET v_t4 := NOW() - INTERVAL v_hh4 HOUR;
    SET v_t5 := NOW() - INTERVAL v_hh5 HOUR;

    /* ===== 3) Responsables por nivel (merge config + pr) ===== */
    DROP TEMPORARY TABLE IF EXISTS tmp_resp;
    CREATE TEMPORARY TABLE tmp_resp (
      nivel         INT PRIMARY KEY,
      idResponsable INT NOT NULL,
      horasSLA      INT NULL
    ) ENGINE=MEMORY;

    INSERT INTO tmp_resp (nivel, idResponsable, horasSLA)
    SELECT ce.nivel, ce.idResponsable, ce.horasSLA
    FROM config_escalamiento ce
    WHERE ce.idEncuesta = v_idencuesta
      AND ce.idAgencia  = v_idagencia;

    INSERT INTO tmp_resp (nivel, idResponsable, horasSLA)
    SELECT pr.nivel, pr.idResponsable, pr.horasSLA
    FROM pqrs_responsables pr
    WHERE pr.idPqrs = p_idpqrs
    ON DUPLICATE KEY UPDATE
      idResponsable = VALUES(idResponsable),
      horasSLA      = VALUES(horasSLA);

    IF p_usuario_n1 IS NOT NULL THEN
      SET v_u_n1 := p_usuario_n1;
    ELSE
      SET v_u_n1 := (SELECT u.idUsuario FROM tmp_resp tr JOIN usuarios u ON u.idUsuario = tr.idResponsable
                     WHERE tr.nivel = 1 LIMIT 1);
    END IF;

    IF p_usuario_n2 IS NOT NULL THEN
      SET v_u_n2 := p_usuario_n2;
    ELSE
      SET v_u_n2 := (SELECT u.idUsuario FROM tmp_resp tr JOIN usuarios u ON u.idUsuario = tr.idResponsable
                     WHERE tr.nivel = 2 LIMIT 1);
    END IF;

    SET v_u_any := (SELECT u.idUsuario FROM tmp_resp tr JOIN usuarios u ON u.idUsuario = tr.idResponsable
                    ORDER BY tr.nivel LIMIT 1);
    IF v_u_any IS NULL THEN
      SET v_u_any := (SELECT idUsuario FROM usuarios ORDER BY idUsuario LIMIT 1);
    END IF;
    SET v_u_n1 := COALESCE(v_u_n1, v_u_any);
    SET v_u_n2 := COALESCE(v_u_n2, v_u_any);

    /* ===== Helper: actualizar nivelActual según el usuario del seguimiento ===== */
    /* (si ese usuario no está en pqrs_responsables, no cambia) */
    /* lo usaremos tras cada INSERT, excepto en el bloque de ESCALADO donde fijamos nivel explícito */
    /* ------------------------------------------------------------------------- */
    /* EN_PROCESO: cambia estado y actualiza nivel por usuario de N1 */
    INSERT INTO seguimientospqrs (idPqrs, idUsuario, comentario, cambioEstado, adjuntosUrl, fechaCreacion)
    VALUES (p_idpqrs, v_u_n1, v_txt1, v_st_proceso, NULL, v_t1);

    UPDATE pqrs
    SET idEstado = v_st_proceso,
        fechaActualizacion = NOW()
    WHERE idPqrs = p_idpqrs;

    /* nivel por usuario que registró el seguimiento */
    UPDATE pqrs p
    JOIN pqrs_responsables pr
      ON pr.idPqrs = p.idPqrs AND pr.idResponsable = v_u_n1
    SET p.nivelActual = pr.nivel,
        p.fechaActualizacion = NOW()
    WHERE p.idPqrs = p_idpqrs;

    SET v_st_curr := v_st_proceso;

    /* Comentario informativo (sin cambio de estado): guarda estado vigente y ajusta nivel al usuario N1 */
    INSERT INTO seguimientospqrs (idPqrs, idUsuario, comentario, cambioEstado, adjuntosUrl, fechaCreacion)
    VALUES (p_idpqrs, v_u_n1, v_txt2, v_st_curr, NULL, v_t2);

    UPDATE pqrs p
    JOIN pqrs_responsables pr
      ON pr.idPqrs = p.idPqrs AND pr.idResponsable = v_u_n1
    SET p.nivelActual = pr.nivel,
        p.fechaActualizacion = NOW()
    WHERE p.idPqrs = p_idpqrs;

    /* ===== ESCALADO (opcional) ===== */
    IF v_escalar = 1 THEN
      SET v_niv_max_pr  := (SELECT MAX(nivel) FROM pqrs_responsables WHERE idPqrs = p_idpqrs);
      SET v_niv_max_cfg := (SELECT MAX(nivel) FROM tmp_resp);
      SET v_niv_max     := COALESCE(v_niv_max_pr, v_niv_max_cfg);

      IF p_nivel_destino IS NOT NULL THEN
        SET v_niv_sig := p_nivel_destino;
      ELSE
        SET v_niv_sig := (SELECT MIN(nivel) FROM tmp_resp WHERE nivel > v_nivel_actual);
      END IF;

      IF v_niv_max IS NOT NULL AND v_niv_sig IS NOT NULL AND v_niv_sig > v_niv_max THEN
        SET v_niv_sig := v_niv_max;
      END IF;

      IF v_niv_sig IS NOT NULL AND v_niv_sig > v_nivel_actual AND (v_niv_max IS NULL OR v_nivel_actual < v_niv_max) THEN
        /* Seguimiento de ESCALADO (estado cambia) */
        INSERT INTO seguimientospqrs (idPqrs, idUsuario, comentario, cambioEstado, adjuntosUrl, fechaCreacion)
        VALUES (p_idpqrs, v_u_n1, v_txt3, v_st_escalado, NULL, v_t3);

        /* SLA del nivel destino */
        SET v_sla_sig := (SELECT pr.horasSLA FROM pqrs_responsables pr
                          WHERE pr.idPqrs = p_idpqrs AND pr.nivel = v_niv_sig LIMIT 1);
        IF v_sla_sig IS NULL THEN
          SET v_sla_sig := (SELECT horasSLA FROM tmp_resp WHERE nivel = v_niv_sig LIMIT 1);
        END IF;

        /* actualizar PQR: nivel destino (explícito), estado y fecha límite */
        UPDATE pqrs
        SET nivelActual = v_niv_sig,
            idEstado = v_st_escalado,
            fechaLimiteNivel = DATE_ADD(v_t3, INTERVAL COALESCE(v_sla_sig, 24) HOUR),
            fechaActualizacion = NOW()
        WHERE idPqrs = p_idpqrs;

        SET v_st_curr := v_st_escalado;

      ELSE
        /* Sin cambio: comentario y nivel según usuario N1 (no mueve nivel explícito) */
        INSERT INTO seguimientospqrs (idPqrs, idUsuario, comentario, cambioEstado, adjuntosUrl, fechaCreacion)
        VALUES (p_idpqrs, v_u_n1, CONCAT(v_txt3, ' (sin cambio: nivel máximo alcanzado)'), v_st_curr, NULL, v_t3);

        UPDATE pqrs p
        JOIN pqrs_responsables pr
          ON pr.idPqrs = p.idPqrs AND pr.idResponsable = v_u_n1
        SET p.nivelActual = pr.nivel,
            p.fechaActualizacion = NOW()
        WHERE p.idPqrs = p_idpqrs;
      END IF;
    END IF;

    /* Comentario de usuario N2 (o fallback): guarda estado vigente y ajusta nivel según N2 */
    INSERT INTO seguimientospqrs (idPqrs, idUsuario, comentario, cambioEstado, adjuntosUrl, fechaCreacion)
    VALUES (p_idpqrs, v_u_n2, v_txt4, v_st_curr, v_adj4, v_t4);

    UPDATE pqrs p
    JOIN pqrs_responsables pr
      ON pr.idPqrs = p.idPqrs AND pr.idResponsable = v_u_n2
    SET p.nivelActual = pr.nivel,
        p.fechaActualizacion = NOW()
    WHERE p.idPqrs = p_idpqrs;

    /* CIERRE (opcional): estado y nivel según usuario N2 que cierra */
    IF v_cerrar = 1 THEN
      INSERT INTO seguimientospqrs (idPqrs, idUsuario, comentario, cambioEstado, adjuntosUrl, fechaCreacion)
      VALUES (p_idpqrs, v_u_n2, v_txt5, v_st_cerrado, NULL, v_t5);

      UPDATE pqrs p
      LEFT JOIN pqrs_responsables pr
        ON pr.idPqrs = p.idPqrs AND pr.idResponsable = v_u_n2
      SET p.idEstado = v_st_cerrado,
          p.fechaCierre = v_t5,
          p.nivelActual = COALESCE(pr.nivel, p.nivelActual),
          p.fechaActualizacion = NOW()
      WHERE p.idPqrs = p_idpqrs;

      SET v_st_curr := v_st_cerrado;
    END IF;

    COMMIT;
  END IF;

  DROP TEMPORARY TABLE IF EXISTS tmp_resp;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_upsert_cliente_y_atencion` (IN `p_idClienteErp` VARCHAR(60), IN `p_cedula` VARCHAR(150), IN `p_nombres` VARCHAR(150), IN `p_apellidos` VARCHAR(150), IN `p_email` VARCHAR(200), IN `p_telefono` VARCHAR(60), IN `p_celular` VARCHAR(60), IN `p_idAgencia` INT, IN `p_fechaAtencion` DATE, IN `p_numeroDocumento` VARCHAR(60), IN `p_tipoDocumento` VARCHAR(60), IN `p_numeroFactura` VARCHAR(60), IN `p_idCanal` INT, IN `p_detalle` MEDIUMTEXT, IN `p_cedulaAsesor` VARCHAR(150))   BEGIN
  DECLARE v_idCliente   BIGINT;
  DECLARE v_idAtencion  BIGINT;
  DECLARE v_idAsesor    INT;

  /* Handler para errores SQL */
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK; 
    RESIGNAL;
  END;

  START TRANSACTION;

  /* (0) Validaciones básicas */
  IF p_idCanal IS NOT NULL 
     AND NOT EXISTS (SELECT 1 FROM canales WHERE idCanal = p_idCanal) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'idCanal no existe en canales';
  END IF;

  /* (0.1) Resolver idAsesor por cédula → usuarios.idUsuario (vía personas) */
  SET v_idAsesor := NULL;
  IF p_cedulaAsesor IS NOT NULL AND p_cedulaAsesor <> '' THEN
    SELECT u.idUsuario
      INTO v_idAsesor
    FROM usuarios u
    JOIN personas pe ON pe.idPersona = u.idPersona
    WHERE pe.cedula = p_cedulaAsesor
    ORDER BY u.estado DESC, u.fechaActualizacion DESC, u.fechaCreacion DESC
    LIMIT 1;

    /* FALLAR si se envió cédula y no existe usuario asociado */
    IF v_idAsesor IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'La cédula del asesor no corresponde a ningún usuario';
    END IF;
  END IF;

  /* (1) Resolver idCliente: por idClienteErp, si no por cédula */
  IF p_idClienteErp IS NOT NULL AND p_idClienteErp <> '' THEN
    SELECT c.idCliente
      INTO v_idCliente
    FROM clientes c
    WHERE c.idClienteErp = p_idClienteErp
    ORDER BY c.fechaActualizacion DESC, c.fechaCreacion DESC
    LIMIT 1;
  END IF;

  IF v_idCliente IS NULL THEN
    SELECT c.idCliente
      INTO v_idCliente
    FROM clientes c
    WHERE c.cedula = p_cedula
    ORDER BY c.fechaActualizacion DESC, c.fechaCreacion DESC
    LIMIT 1;
  END IF;

  /* (2) UPSERT cliente */
  IF v_idCliente IS NULL THEN
    INSERT INTO clientes (
      idClienteErp, cedula, nombres, apellidos, email, telefono, celular,
      estado, fechaCreacion, fechaActualizacion
    ) VALUES (
      NULLIF(p_idClienteErp,''), p_cedula, p_nombres, p_apellidos, p_email, p_telefono, p_celular,
      1, NOW(), NOW()
    );
    SET v_idCliente = LAST_INSERT_ID();
  ELSE
    UPDATE clientes
       SET nombres            = p_nombres,
           apellidos          = p_apellidos,
           email              = p_email,
           telefono           = p_telefono,
           celular            = p_celular,
           estado             = 1,
           /* Solo setea ERP si viene y está vacío en BD */
           idClienteErp       = IF(
                                  (p_idClienteErp IS NOT NULL AND p_idClienteErp <> '' 
                                   AND (idClienteErp IS NULL OR idClienteErp = '')),
                                  p_idClienteErp,
                                  idClienteErp
                                ),
           fechaActualizacion = NOW()
     WHERE idCliente = v_idCliente;
  END IF;

  /* (3) UPSERT atención por claves naturales */
  SELECT a.idAtencion
    INTO v_idAtencion
  FROM atenciones a
  WHERE a.idCliente       = v_idCliente
    AND a.numeroDocumento = p_numeroDocumento
    AND a.tipoDocumento   = p_tipoDocumento
    AND ((p_numeroFactura IS NULL AND a.numeroFactura IS NULL) OR a.numeroFactura = p_numeroFactura)
  ORDER BY a.idAtencion DESC
  LIMIT 1;

  IF v_idAtencion IS NULL THEN
    INSERT INTO atenciones (
      idCliente, idAgencia, idAsesor, idCanal, fechaAtencion,
      numeroDocumento, tipoDocumento, numeroFactura,
      detalle, estado, fechaCreacion, fechaActualizacion
    ) VALUES (
      v_idCliente, p_idAgencia, v_idAsesor, p_idCanal, p_fechaAtencion,
      p_numeroDocumento, p_tipoDocumento, p_numeroFactura,
      p_detalle, 1, NOW(), NOW()
    );
    SET v_idAtencion = LAST_INSERT_ID();
  ELSE
    UPDATE atenciones
       SET idAgencia          = p_idAgencia,
           /* Si se envió cédula válida se actualiza; si no, se conserva */
           idAsesor           = COALESCE(v_idAsesor, idAsesor),
           idCanal            = p_idCanal,
           fechaAtencion      = p_fechaAtencion,
           numeroDocumento    = p_numeroDocumento,
           tipoDocumento      = p_tipoDocumento,
           numeroFactura      = p_numeroFactura,
           detalle            = p_detalle,
           estado             = 1,
           fechaActualizacion = NOW()
     WHERE idAtencion = v_idAtencion;
  END IF;

  COMMIT;

  /* Salida informativa */
  SELECT v_idCliente AS idCliente, v_idAtencion AS idAtencion, v_idAsesor AS idAsesor;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_upsert_y_programar_autoencuesta` (IN `p_canalEnvio` VARCHAR(16), IN `p_idClienteErp` VARCHAR(60), IN `p_cedula` VARCHAR(150), IN `p_nombres` VARCHAR(150), IN `p_apellidos` VARCHAR(150), IN `p_email` VARCHAR(200), IN `p_telefono` VARCHAR(60), IN `p_celular` VARCHAR(60), IN `p_idAgencia` INT, IN `p_fechaAtencion` DATE, IN `p_numeroDocumento` VARCHAR(60), IN `p_tipoDocumento` VARCHAR(60), IN `p_numeroFactura` VARCHAR(60), IN `p_idCanal` INT, IN `p_detalle` MEDIUMTEXT)   BEGIN
  DECLARE v_idCliente   BIGINT;
  DECLARE v_idAtencion  BIGINT;
  DECLARE v_idEncuesta  BIGINT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN ROLLBACK; RESIGNAL; END;

  START TRANSACTION;

  /* ⚠️ Ahora sí pasamos p_idClienteErp como primer parámetro */
  CALL sp_upsert_cliente_y_atencion(
    p_idClienteErp,               -- <--- agregado
    p_cedula, p_nombres, p_apellidos, p_email, p_telefono, p_celular,
    p_idAgencia, p_fechaAtencion, p_numeroDocumento, p_tipoDocumento, p_numeroFactura,
    p_idCanal, p_detalle
  );

  /* Resolver idCliente (prioriza ERP cuando viene) */
  IF p_idClienteErp IS NOT NULL AND p_idClienteErp <> '' THEN
    SELECT c.idCliente INTO v_idCliente
    FROM clientes c
    WHERE c.idClienteErp = p_idClienteErp
    ORDER BY c.fechaActualizacion DESC, c.fechaCreacion DESC
    LIMIT 1;
  END IF;

  IF v_idCliente IS NULL THEN
    SELECT c.idCliente INTO v_idCliente
    FROM clientes c
    WHERE c.cedula = p_cedula
    ORDER BY c.fechaActualizacion DESC, c.fechaCreacion DESC
    LIMIT 1;
  END IF;

  IF v_idCliente IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se pudo resolver idCliente tras el upsert.';
  END IF;

  /* Resolver v_idAtencion por claves naturales */
  SELECT a.idAtencion INTO v_idAtencion
  FROM atenciones a
  WHERE a.idCliente = v_idCliente
    AND a.numeroDocumento = p_numeroDocumento
    AND a.tipoDocumento   = p_tipoDocumento
    AND ((p_numeroFactura IS NULL AND a.numeroFactura IS NULL) OR a.numeroFactura = p_numeroFactura)
  ORDER BY a.idAtencion DESC
  LIMIT 1;

  IF v_idAtencion IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se pudo resolver idAtencion tras el upsert.';
  END IF;

  /* Programar automáticamente según el canal de la atención */
  CALL sp_programar_despues_de_upsert_auto(v_idAtencion, p_canalEnvio);

  /* Encuesta usada = ACTIVA más reciente del mismo idCanal de la atención */
  SELECT e.idEncuesta INTO v_idEncuesta
  FROM atenciones a
  JOIN encuestas  e ON e.idCanal = a.idCanal AND e.activa = 1
  WHERE a.idAtencion = v_idAtencion
  ORDER BY e.idEncuesta DESC
  LIMIT 1;

  /* Devolver la fila programada (gracias a UNIQUE (idEncuesta,idAtencion)) */
  SELECT ep.idProgEncuesta AS idProgEncuesta,
         v_idAtencion     AS idAtencion,
         v_idEncuesta     AS idEncuesta,
         'OK'             AS status
  FROM encuestasprogramadas ep
  WHERE ep.idAtencion = v_idAtencion
    AND ep.idEncuesta = v_idEncuesta
  LIMIT 1;

  COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_upsert_y_programar_idem` (IN `p_idEncuesta` BIGINT, IN `p_canal` VARCHAR(16), IN `p_idClienteErp` VARCHAR(60), IN `p_cedula` VARCHAR(150), IN `p_nombres` VARCHAR(150), IN `p_apellidos` VARCHAR(150), IN `p_email` VARCHAR(200), IN `p_telefono` VARCHAR(60), IN `p_celular` VARCHAR(60), IN `p_idAgencia` INT, IN `p_fechaAtencion` DATE, IN `p_numeroDocumento` VARCHAR(60), IN `p_tipoDocumento` VARCHAR(60), IN `p_numeroFactura` VARCHAR(60))   BEGIN
  DECLARE v_idCliente  BIGINT;
  DECLARE v_idAtencion BIGINT;
  DECLARE v_idProg     BIGINT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN ROLLBACK; RESIGNAL; END;

  START TRANSACTION;

  /* Pasamos la nueva firma (idCanal/detalle como NULL si no aplican aquí) */
  CALL sp_upsert_cliente_y_atencion(
    p_idClienteErp,
    p_cedula, p_nombres, p_apellidos, p_email, p_telefono, p_celular,
    p_idAgencia, p_fechaAtencion, p_numeroDocumento, p_tipoDocumento, p_numeroFactura,
    NULL,   -- p_idCanal
    NULL    -- p_detalle
  );

  /* Resolver cliente */
  IF p_idClienteErp IS NOT NULL AND p_idClienteErp <> '' THEN
    SELECT c.idCliente INTO v_idCliente
    FROM clientes c
    WHERE c.idClienteErp = p_idClienteErp
    ORDER BY c.fechaActualizacion DESC, c.fechaCreacion DESC
    LIMIT 1;
  END IF;

  IF v_idCliente IS NULL THEN
    SELECT c.idCliente INTO v_idCliente
    FROM clientes c
    WHERE c.cedula = p_cedula
    ORDER BY c.fechaActualizacion DESC, c.fechaCreacion DESC
    LIMIT 1;
  END IF;

  IF v_idCliente IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se pudo resolver idCliente tras el upsert.';
  END IF;

  /* Resolver atención por claves naturales */
  SELECT a.idAtencion INTO v_idAtencion
  FROM atenciones a
  WHERE a.idCliente       = v_idCliente
    AND a.numeroDocumento = p_numeroDocumento
    AND a.tipoDocumento   = p_tipoDocumento
    AND ((p_numeroFactura IS NULL AND a.numeroFactura IS NULL) OR a.numeroFactura = p_numeroFactura)
  ORDER BY a.idAtencion DESC
  LIMIT 1;

  IF v_idAtencion IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se pudo resolver idAtencion tras el upsert.';
  END IF;

  /* Programación idempotente con la encuesta indicada */
  CALL sp_programar_despues_de_upsert_idem(p_idEncuesta, v_idAtencion, p_canal);

  /* Retornar id programado (nuevo o existente) */
  SELECT ep.idProgEncuesta AS idProgEncuesta, 'OK' AS status
  FROM encuestasprogramadas ep
  WHERE ep.idEncuesta = p_idEncuesta
    AND ep.idAtencion = v_idAtencion
  LIMIT 1;

  COMMIT;
END$$

--
-- Funciones
--
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_siguiente_codigo_pqrs` () RETURNS VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_general_ci  BEGIN
  DECLARE v_hoy DATE;
  DECLARE v_num INT;
  SET v_hoy = CURRENT_DATE();

  INSERT INTO secuencial_diario (fecha, ultimo)
  VALUES (v_hoy, 0)
  ON DUPLICATE KEY UPDATE ultimo = ultimo;

  UPDATE secuencial_diario SET ultimo = ultimo + 1 WHERE fecha = v_hoy;
  SELECT ultimo INTO v_num FROM secuencial_diario WHERE fecha = v_hoy;

  RETURN CONCAT('PQRS-', DATE_FORMAT(v_hoy,'%Y%m%d'), '-', LPAD(v_num, 3, '0'));
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `agencias`
--

CREATE TABLE `agencias` (
  `idAgencia` int(11) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `agencias`
--

INSERT INTO `agencias` (`idAgencia`, `nombre`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'IBARRA', 1, '2025-09-06 03:50:22', '2025-09-06 03:51:35'),
(2, 'TULCAN', 1, '2025-09-06 03:50:22', '2025-09-06 03:51:42'),
(3, 'ESMERALDAS', 1, '2025-09-06 03:50:22', '2025-09-06 03:51:48'),
(4, 'EL COCA', 1, '2025-09-06 03:50:22', '2025-09-06 03:51:56'),
(5, 'LAGO AGRIO', 1, '2025-09-06 03:50:22', '2025-09-06 03:52:28');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `atenciones`
--

CREATE TABLE `atenciones` (
  `idAtencion` bigint(20) NOT NULL,
  `idCliente` bigint(20) NOT NULL,
  `idAgencia` int(11) DEFAULT NULL,
  `idAsesor` int(11) DEFAULT NULL,
  `idCanal` int(11) DEFAULT NULL,
  `fechaAtencion` date NOT NULL,
  `numeroDocumento` varchar(50) NOT NULL,
  `tipoDocumento` varchar(50) NOT NULL,
  `numeroFactura` varchar(50) DEFAULT NULL,
  `detalle` mediumtext DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `atenciones`
--

INSERT INTO `atenciones` (`idAtencion`, `idCliente`, `idAgencia`, `idAsesor`, `idCanal`, `fechaAtencion`, `numeroDocumento`, `tipoDocumento`, `numeroFactura`, `detalle`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 1, 3, 7, 2, '2025-01-23', '1211538', 'SFV', '001100000015738', 'CHASIS: LVVDB11B9SE003069, MODELO: TIGGO 2 PRO A1X AC 1.5 5P 4X2 TM, COLOR: BLANCO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(2, 2, 3, 7, 2, '2025-01-29', '1212008', 'SFV', '001100000015739', 'CHASIS: LVVDB11B2SE003043, MODELO: TIGGO 2 PRO A1X AC 1.5 5P 4X2 TM, COLOR: NEGRO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(3, 3, 3, 7, 2, '2025-02-27', '1213958', 'SFV', '001100000015740', 'CHASIS: LVAV2MAB4SU313112, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: ROJO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(4, 4, 3, 7, 2, '2025-02-27', '1213959', 'SFV', '001100000015741', 'CHASIS: LVAV2MAB9SU302543, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: AZUL, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(5, 5, 1, 5, 2, '2025-02-28', '1214052', 'SFV', '001100000015742', 'CHASIS: LVBV3JBB6SY005132, MODELO: AUMARK E BJ1069 AC 2.8 2P 4X2 TM DIESEL, COLOR: BLANCO, VENDEDOR: SIGUENZA SANCHEZ DANIEL ALEJANDRO', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(6, 6, 3, 7, 2, '2025-02-28', '1214080', 'SFV', '001100000015743', 'CHASIS: L6T7722D7RU007332, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: NEGRO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(7, 7, 3, 7, 2, '2025-03-07', '1214315', 'SFV', '001100000015744', 'CHASIS: 9FBHJD204SM132889, MODELO: DUSTER INTENS AC 1.6 5P 4X2 TM, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(8, 8, 3, 7, 2, '2025-03-20', '1215216', 'SFV', '001100000015745', 'CHASIS: LVAV2MAB0SU313091, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: ROJO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(9, 9, 3, 7, 2, '2025-03-26', '1215523', 'SFV', '001100000015746', 'CHASIS: LNBSCCAH6RD909035, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(10, 10, 1, 5, 2, '2025-03-27', '1215590', 'SFV', '001100000015747', 'CHASIS: LVAV2MAB1SU313231, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: PLOMO, VENDEDOR: SIGUENZA SANCHEZ DANIEL ALEJANDRO', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(11, 11, 1, 5, 2, '2025-03-29', '1215657', 'SFV', '001100000015748', 'CHASIS: L6T7722D2RU007335, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: NEGRO, VENDEDOR: IMBAUTO S.A', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(12, 12, 3, 7, 2, '2025-03-31', '1215752', 'SFV', '001100000015749', 'CHASIS: LVAV2MAB5SU311630, MODELO: TUNLAND G AC 2.0 CD 4X2 TM DIESEL, COLOR: PLOMO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(13, 13, 1, 5, 2, '2025-04-02', '1215885', 'SFV', '001100000015751', 'CHASIS: MP2TFS40JPT005725, MODELO: NEW BT-50 AC 3.0 CD 4X4 TA DIESEL, COLOR: NEGRO, VENDEDOR: SIGUENZA SANCHEZ DANIEL ALEJANDRO', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(14, 14, 3, 7, 2, '2025-04-03', '1215924', 'SFV', '001100000015752', 'CHASIS: LVAV2MAB7SU311676, MODELO: TUNLAND G AC 2.0 CD 4X2 TM DIESEL, COLOR: NEGRO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(15, 15, 1, 5, 2, '2025-04-07', '1216157', 'SFV', '001100000015753', 'CHASIS: LVAV2MAB0SU313074, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: NEGRO, VENDEDOR: SIGUENZA SANCHEZ DANIEL ALEJANDRO', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(16, 16, 3, 7, 2, '2025-04-10', '1216407', 'SFV', '001100000015754', 'CHASIS: LNBSCCAH8RD979233, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: PLOMO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(17, 17, 3, 7, 2, '2025-04-15', '1216677', 'SFV', '001100000015755', 'CHASIS: LNBSCUAK4RR194082, MODELO: X35 ELITE AC 1.5 5P 4X2 TA, COLOR: ROJO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(18, 18, 3, 7, 2, '2025-04-15', '1216684', 'SFV', '001100000015757', 'CHASIS: L6T7722D7RU007380, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: NEGRO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(19, 19, 1, 5, 2, '2025-04-23', '1217030', 'SFV', '001100000015758', 'CHASIS: HJRPBGJB7RF010998, MODELO: X90 PLUS AC 2.0 5P 4X2 TA, COLOR: PLOMO, VENDEDOR: QUINTANA CIFUENTES VERONICA BELEN', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(20, 20, 3, 7, 2, '2025-04-23', '1217039', 'SFV', '001100000015760', 'CHASIS: LNBSCCAH7RD909318, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: NEGRO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(21, 11, 1, 5, 2, '2025-04-30', '1217401', 'SFV', '001100000015761', 'CHASIS: L6T7722D2RU007609, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: PLATEADO, VENDEDOR: IMBAUTO S.A', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(22, 21, 3, 7, 2, '2025-05-08', '1217715', 'SFV', '001100000015762', 'CHASIS: LNBSCCAH1RD909038, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:07:35'),
(23, 22, 3, 7, 2, '2025-05-09', '1217872', 'SFV', '001100000015763', 'CHASIS: 9FBHJD20XSM166349, MODELO: DUSTER INTENS AC 1.6 5P 4X2 TM, COLOR: PLATEADO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:07:41'),
(24, 23, 3, 7, 2, '2025-05-09', '1217885', 'SFV', '001100000015764', 'CHASIS: LVVDC11B7SD007513, MODELO: ARRIZO 5 PRO COMFORT AC 1.5 4P 4X2 TM, COLOR: PLOMO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 1, '2025-09-15 16:28:45', '2025-09-20 18:07:44'),
(25, 24, 3, 7, 2, '2025-05-15', '1218147', 'SFV', '001100000015766', 'CHASIS: LVVDC11B3SD026673, MODELO: ARRIZO 5 PRO COMFORT AC 1.5 4P 4X2 TM, COLOR: BLANCO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 1, '2025-09-15 16:28:45', '2025-09-20 18:07:47'),
(26, 25, 1, 5, 2, '2025-05-19', '1218338', 'SFV', '001100000015767', 'CHASIS: LVVDC11B7SD026689, MODELO: ARRIZO 5 PRO COMFORT AC 1.5 4P 4X2 TM, COLOR: PLOMO, VENDEDOR: QUINTANA CIFUENTES VERONICA BELEN', 1, '2025-09-15 16:28:45', '2025-09-20 18:07:50'),
(27, 26, 3, 7, 2, '2025-05-20', '1218358', 'SFV', '001100000015768', 'CHASIS: LNBSCCAH8RD900949, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: ROJO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:07:56'),
(28, 27, 1, 5, 2, '2025-05-22', '1218508', 'SFV', '001100000015770', 'CHASIS: LVAV2MAB9SU302591, MODELO: TUNLAND G AC 2.0 CD 4X2 TM DIESEL, COLOR: BLANCO, VENDEDOR: MONTENEGRO JIMENEZ JENNY ELIZABETH', 1, '2025-09-15 16:28:45', '2025-09-20 18:07:59'),
(29, 28, 1, 5, 2, '2025-05-27', '1218690', 'SFV', '001100000015771', 'CHASIS: HJRPBGFB2RB164163, MODELO: DASHING 6DCT AC 1.5 5P 4X2 TA, COLOR: PLOMO, VENDEDOR: QUINTANA CIFUENTES VERONICA BELEN', 1, '2025-09-15 16:28:45', '2025-09-20 18:11:08'),
(30, 29, 3, 7, 2, '2025-05-28', '1218752', 'SFV', '001100000015773', 'CHASIS: LVAV2MAB8SU311668, MODELO: TUNLAND G AC 2.0 CD 4X2 TM DIESEL, COLOR: NEGRO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:11:11'),
(31, 28, 1, 5, 2, '2025-05-30', '1218948', 'SFV', '001100000015775', 'CHASIS: LJ11R2DH1R3500228, MODELO: HFC1160KR1T AC 6.7 2P 4X2 TM DIESEL CN, COLOR: BLANCO, VENDEDOR: QUINTANA CIFUENTES VERONICA BELEN', 1, '2025-09-15 16:28:45', '2025-09-20 18:11:14'),
(32, 30, 1, 5, 2, '2025-06-05', '1219222', 'SFV', '001100000015777', 'CHASIS: LVBV3JBBXSY003996, MODELO: AUMARK E BJ1069 AC 2.8 2P 4X2 TM DIESEL, COLOR: BLANCO, VENDEDOR: QUINTANA CIFUENTES VERONICA BELEN', 1, '2025-09-15 16:28:45', '2025-09-20 18:11:17'),
(33, 31, 5, 5, 2, '2025-06-12', '1219691', 'SFV', '001100000015779', 'CHASIS: LEFEDEF15STP09615, MODELO: GRAND AVENUE AC 2.3 CD 4X4 TM DIESEL, COLOR: BLANCO, VENDEDOR: CASTRO LABANDA ADRIANA LISETT', 1, '2025-09-15 16:28:45', '2025-09-20 18:12:03'),
(34, 32, 5, 34, 2, '2025-06-13', '1219785', 'SFV', '001100000015780', 'CHASIS: LEFEDEF10STP09375, MODELO: GRAND AVENUE AC 2.3 CD 4X4 TM DIESEL, COLOR: PLATEADO, VENDEDOR: CASTRO LABANDA ADRIANA LISETT', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:11'),
(35, 33, 3, 7, 2, '2025-06-26', '1220411', 'SFV', '001100000015782', 'CHASIS: 9FBRBB00XTM314106, MODELO: KWID ZEN AC 1.0 5P 4X2 TM, COLOR: BLANCO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:20'),
(36, 34, 3, 7, 2, '2025-06-30', '1220494', 'SFV', '001100000015783', 'CHASIS: L6T7722D9RU007610, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: PLATEADO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:26'),
(37, 35, 1, 5, 2, '2025-06-30', '1220570', 'SFV', '001100000015784', 'CHASIS: L6T7722D2RU007612, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: PLATEADO, VENDEDOR: MONTENEGRO JIMENEZ JENNY ELIZABETH', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:29'),
(38, 36, 3, 7, 2, '2025-06-30', '1220580', 'SFV', '001100000015785', 'CHASIS: 9FBHJD20XTM317269, MODELO: DUSTER ZEN AC 1.6 5P 4X2 TM, COLOR: PLOMO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:34'),
(39, 37, 3, 7, 2, '2025-07-04', '1220813', 'SFV', '001100000015786', 'CHASIS: LNBSCCAH3SD080480, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: ROJO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:37'),
(40, 38, 3, 7, 2, '2025-07-04', '1220815', 'SFV', '001100000015787', 'CHASIS: LEFEDDE13STP08667, MODELO: VIGUS WORK AC 2.5 CD 4X4 TM DIESEL, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:42'),
(41, 11, 1, 5, 2, '2025-07-07', '1220873', 'SFV', '001100000015788', 'CHASIS: L6T7722D5RU007314, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: ROJO, VENDEDOR: IMBAUTO S.A', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:44'),
(42, 11, 1, 5, 2, '2025-07-17', '1221429', 'SFV', '001100000015789', 'CHASIS: LVBVCJB51SE100239, MODELO: CN F250-SR AC 2.5 2P 4X2 TM DIESEL, COLOR: BLANCO, VENDEDOR: IMBAUTO S.A', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:47'),
(43, 39, 1, 5, 2, '2025-07-18', '1221555', 'SFV', '001100000015790', 'CHASIS: L6T7722D6RU007516, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: BLANCO, VENDEDOR: MONTENEGRO JIMENEZ JENNY ELIZABETH', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:50'),
(44, 40, 3, 7, 2, '2025-07-23', '1221739', 'SFV', '001100000015791', 'CHASIS: 9FB5SR1EGTM317059, MODELO: STEPWAY INTENS FASE II AC 1.6 5P 4X2 TM, COLOR: ROJO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:54'),
(45, 11, 1, 5, 2, '2025-07-25', '1221801', 'SFV', '001100000015792', 'CHASIS: L6T7722D7RU007489, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: BLANCO, VENDEDOR: IMBAUTO S.A', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:56'),
(46, 11, 1, 5, 2, '2025-07-31', '1222073', 'SFV', '001100000015793', 'CHASIS: L6T7722D8RU007288, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: ROJO, VENDEDOR: IMBAUTO S.A', 1, '2025-09-15 16:28:45', '2025-09-20 18:13:58'),
(47, 41, 1, 5, 2, '2025-07-31', '1222099', 'SFV', '001100000015794', 'CHASIS: L6T7722D5RU007510, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: BLANCO, VENDEDOR: QUINTANA CIFUENTES VERONICA BELEN', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:02'),
(48, 42, 3, 7, 2, '2025-07-31', '1222117', 'SFV', '001100000015795', 'CHASIS: LVAV2MAB8SU302744, MODELO: TUNLAND G AC 2.0 CD 4X2 TM DIESEL, COLOR: NEGRO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:05'),
(49, 43, 1, 5, 2, '2025-07-31', '1222120', 'SFV', '001100000015796', 'CHASIS: LVAV2MAB3TU308260, MODELO: TUNLAND V7 AC 2.0 CD 4X4 TM DIESEL HYBRID, COLOR: PLOMO, VENDEDOR: MONTENEGRO JIMENEZ JENNY ELIZABETH', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:08'),
(50, 44, 1, 5, 2, '2025-08-12', '1222610', 'SFV', '001100000015797', 'CHASIS: LVAV2MAB9TU308036, MODELO: TUNLAND V7 AC 2.0 CD 4X4 TM DIESEL HYBRID, COLOR: BLANCO, VENDEDOR: SANCHEZ LEONARDO JESUS', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:12'),
(51, 45, 3, 7, 2, '2025-08-21', '1223044', 'SFV', '001100000015798', 'CHASIS: L6T7722D1RU007326, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: ROJO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:15'),
(52, 46, 3, 7, 2, '2025-08-21', '1223075', 'SFV', '001100000015799', 'CHASIS: LVAV2MAB1TU450784, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:19'),
(53, 47, 3, 7, 2, '2025-08-23', '1223153', 'SFV', '001100000015802', 'CHASIS: 9FB4SR1E5TM376965, MODELO: LOGAN ZEN FASE II AC 1.6 4P 4X2 TM, COLOR: NEGRO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:22'),
(54, 48, 1, 5, 2, '2025-08-26', '1223279', 'SFV', '001100000015803', 'CHASIS: LJNTGUC36RN408115, MODELO: RICH 6 AC 2.5 CD 4X4 TM DIESEL, COLOR: BLANCO, VENDEDOR: MONTENEGRO JIMENEZ JENNY ELIZABETH', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:24'),
(55, 49, 3, 7, 2, '2025-08-29', '1223412', 'SFV', '001100000015807', 'CHASIS: LEFBMCHC2ST060099, MODELO: JMC N520 TOURING AC 2.8 5P 4X2 TM DIESEL, COLOR: AMARILLO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:28'),
(56, 50, 1, 5, 2, '2025-09-02', '1223510', 'SFV', '001100000015811', 'CHASIS: LEFEDEF17STP05050, MODELO: GRAND AVENUE AC 2.3 CD 4X4 TM DIESEL, COLOR: PLOMO, VENDEDOR: TITO MEJIA GENESSIS LORENA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(57, 51, 1, 5, 2, '2025-09-02', '1223511', 'SFV', '001100000015812', 'CHASIS: LEFBMCHC9ST060116, MODELO: JMC N520 TOURING AC 2.8 5P 4X2 TM DIESEL, COLOR: AMARILLO, VENDEDOR: TITO MEJIA GENESSIS LORENA', 0, '2025-09-15 16:28:45', '2025-09-25 05:52:31'),
(58, 52, 3, 7, 2, '2025-09-03', '1223544', 'SFV', '001100000015813', 'CHASIS: LEFEDDE19STP08818, MODELO: VIGUS WORK AC 2.5 CD 4X4 TM DIESEL, COLOR: PLATEADO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 1, '2025-09-15 16:28:45', '2025-09-20 18:15:44'),
(59, 53, 3, 7, 2, '2025-09-03', '1223547', 'SFV', '001100000015814', 'CHASIS: LVAV2MAB0TU450789, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:14:43'),
(60, 54, 3, 7, 2, '2025-09-04', '1223643', 'SFV', '001100000015815', 'CHASIS: LVAV2MAB3SU313067, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: NEGRO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:15:48'),
(61, 55, 3, 7, 2, '2025-09-04', '1223644', 'SFV', '001100000015816', 'CHASIS: LVAV2MAB2TU450776, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-09-15 16:28:45', '2025-09-20 18:15:51'),
(62, 56, 3, 31, 1, '2025-06-27', '1220429', 'SFT', '006400000035358', 'VENTAS DE TALLER|TALLER MECANICA|GROOVE PREMIER AC 1.5 5P 4X2 TM|PFG1131|KM.:50754|BONE ESTRADA JHONNY DANIEL|FEC_OT:20/06/2025|OT.668902|MANTENIMIENTO CORRECTIVO', 0, '2025-09-18 23:09:57', '2025-09-26 02:15:45'),
(63, 57, 5, 34, 1, '2025-07-29', '1221923', 'SFT', '015400000028890', 'VENTAS DE TALLER|TALLER MECANICA|SPARK GT AC 1.2 5P 4X2 TM|IBD5618|KM.:240093|JUMBO MORENO YOEL ALEXANDER|FEC_OT:28/07/2025|OT.669952|MANTENIMIENTO 40000 KM', 0, '2025-09-19 02:49:42', '2025-09-26 02:16:18'),
(64, 58, 4, 33, 1, '2025-06-24', '1220297', 'SFT', '010400000026706', 'VENTAS DE TALLER|ENDEREZADA Y PINTURA|HYUNDAI CAT1|PFF2037|KM.:30445|LARA RON EVELYN MADELEINE|FEC_OT:17/06/2025|OT.668800|MANTENIMIENTO CORRECTIVO', 1, '2025-09-20 17:58:34', '2025-09-26 02:17:03'),
(67, 61, 1, 18, 1, '2025-06-26', '1220393', 'SFT', '001400000072439', 'VENTAS DE TALLER|TALLER MECANICA|NPR 815 EIII 5.2 2P 4X2 TM DIESEL CN|IAI3175|KM.:190036|MENDEZ TUQUERREZ MIGUEL ANGEL|FEC_OT:26/06/2025|OT.669034|MANTENIMIENTO CORRECTIVO', 1, '2025-09-25 04:41:21', '2025-09-25 06:01:08'),
(68, 62, 5, 34, 1, '2025-07-29', '1221917', 'SFT', '015400000028889', 'VENTAS DE TALLER|TALLER MECANICA|CAPTIVA LT TURBO 5PAS AC 1.5 5P 4X2 TM|PDY9683|KM.:71148|JUMBO MORENO YOEL ALEXANDER|FEC_OT:29/07/2025|OT.669957|MANTENIMIENTO CORRECTIVO', 1, '2025-09-25 16:34:42', '2025-09-25 16:34:42'),
(69, 63, 1, 20, 1, '2025-06-23', '1220243', 'SFT', '001400000072417', 'VENTAS DE TALLER|TALLER MECANICA|CA1040P40K50EA84 AC 2.3 2P 4X2 TM|PDS9821|KM.:45026|VILLARREAL ESCOBAR MATIAS ALEJANDRO|FEC_OT:21/06/2025|OT.668917|MANTENIMIENTO CORRECTIVO', 1, '2025-09-28 04:11:54', '2025-09-28 04:11:54'),
(70, 64, 2, 16, 1, '2025-08-12', '1222573', 'SFT', '003400000014982', 'VENTAS DE TALLER|TALLER MECANICA|ONIX RS TURBO AC 1.0 5P 4X2 TM|IBF4621|KM.:30438|GER POTOSI MARIANA DE JESUS|FEC_OT:12/08/2025|OT.670328|MANTENIMIENTO CORRECTIVO', 1, '2025-09-28 04:11:54', '2025-09-28 04:11:54'),
(73, 66, 1, 31, 1, '2025-06-12', '1219701', 'SFT', '006400000035309', 'VENTAS DE TALLER|TALLER MECANICA|FORD F150|PBM1278|KM.:206000|BONE ESTRADA JHONNY DANIEL|FEC_OT:09/06/2025|OT.668638|MANTENIMIENTO CORRECTIVO', 1, '2025-09-29 21:14:15', '2025-09-29 21:14:15'),
(74, 67, 3, 31, 1, '2025-06-03', '1219093', 'SFT', '006400000035277', 'VENTAS DE TALLER|TALLER MECANICA|ONIX LTZ TURBO AC 1.0 4P 4X2 TM|IBE8492|KM.:60959|BONE ESTRADA JHONNY DANIEL|FEC_OT:03/06/2025|OT.668312|MANTENIMIENTO CORRECTIVO', 1, '2025-09-29 21:51:43', '2025-09-30 02:06:47'),
(75, 68, 3, 17, 1, '2025-07-17', '1221440', 'SFT', '006400000035467', 'VENTAS DE TALLER|TALLER MECANICA|SAIL LS AC 1.5 4P 4X2 TM|PDY7548|KM.:76600|VITE VALENCIA JAIME VIDAL|FEC_OT:17/07/2025|OT.669590|MANTENIMIENTO CORRECTIVO', 1, '2025-09-29 22:41:37', '2025-09-29 22:41:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `canales`
--

CREATE TABLE `canales` (
  `idCanal` int(11) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `canales`
--

INSERT INTO `canales` (`idCanal`, `nombre`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'Taller de servicio', 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(2, 'Vehículos nuevos', 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(3, 'Partes y accesorios', 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(4, 'Otros', 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categoriaspadre`
--

CREATE TABLE `categoriaspadre` (
  `idCategoriaPadre` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `categoriaspadre`
--

INSERT INTO `categoriaspadre` (`idCategoriaPadre`, `nombre`, `descripcion`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'T01', 'CLIENTE SATISFECHO, PERO CALIFICA CON BAJA NOTA', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(2, 'T02', 'DEMORA TIEMPO DE ENTREGA DEL VEHICULO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(3, 'T03', 'DEMORA TIEMPO DE RECEPCION DEL VEHICULO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(4, 'T04', 'REPUESTOS', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(5, 'T05', 'MALA ATENCION', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(6, 'T06', 'FALTA DE INFORMACION', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(7, 'T07', 'PERSISTE EL PROBLEMA TECNICO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(8, 'T08', 'PRECIO ELEVADO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(9, 'T09', 'FALTA LIMPIEZA DEL VEHICULO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(10, 'T10', 'AGENDAMIENTO DE CITAS', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(11, 'T11', 'OTROS', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(12, 'T12', 'INFRAESTRUCTURA', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(13, 'T13', 'AGENDAMIENTO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(14, 'T14', 'RECEPCION Y DIRECCIONAMIENTO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(15, 'T15', 'ENTREVISTA CONSULTIVA', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(16, 'T16', 'PRESUPUESTO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(17, 'T17', 'CENTRAL DE ATENCION', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(18, 'T18', 'SERVICIO TECNICO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(19, 'T19', 'PAGO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(20, 'T20', 'ENTREGA DEL VEHICULO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(21, 'T21', 'PERSONAS', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(22, 'V01', 'CLIENTE SATISFECHO, PERO CALIFICA CON BAJA NOTA', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(23, 'V02', 'MALA ATENCION', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(24, 'V03', 'FALLAS DEL VEHICULO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(25, 'V04', 'ACCESORIOS Y APLICACIONES', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(26, 'V05', 'MATRICULACION', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(27, 'V06', 'VEHICULO Y ENTREGA', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(28, 'V07', 'DISPOSITIVO SATELITAL', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(29, 'V08', 'FINANCIAMIENTO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(30, 'V09', 'STOCK DE VEHICULOS', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(31, 'V11', 'SEGURO', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(32, 'V12', 'PERSONAS', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(33, 'V13', 'INFRAESTRUCTURA', 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categoriaspqrs`
--

CREATE TABLE `categoriaspqrs` (
  `idCategoria` int(11) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `idCategoriaPadre` int(11) NOT NULL,
  `idCanal` int(11) NOT NULL,
  `idTipo` int(11) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `categoriaspqrs`
--

INSERT INTO `categoriaspqrs` (`idCategoria`, `nombre`, `descripcion`, `idCategoriaPadre`, `idCanal`, `idTipo`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'V03A', 'FALLAS DEL VEHICULO', 24, 2, 3, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(2, 'V04C', 'MAL INSTALADO LOS ACCESORIOS', 25, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(3, 'V04B', 'DEMORA O FALTA DE INSTALACION DE ACCESORIOS', 25, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(4, 'V04A', 'ACCESORIOS Y APLICACIONES', 25, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(5, 'V05C', 'AUN NO LE ENTREGAN LA MATRICULA O PLACAS', 26, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(6, 'V05B', 'DEMORA TRAMITE DE MATRICULACION', 26, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(7, 'V05A', 'MATRICULACION', 26, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(8, 'V06H', 'FALTA EXPLICACION DEL FUNCIONAMIENTO DE DISPOSITIVO SATELITAL', 27, 2, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(9, 'V06G', 'OTRAS NOVEDADES CON EL VEHICULO', 27, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(10, 'V06F', 'FALLAS DE PINTURA COLISION Y PULIDA', 27, 2, 3, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(11, 'V06E', 'FALLAS DEL VEHICULO', 27, 2, 3, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(12, 'V06D', 'NO ESTABA EL VEHICULO LISTO EN LA ENTREGA', 27, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(13, 'V06C', 'AUN NO LE ENTREGAN EL VH', 27, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(14, 'V06B', 'FALTA EXPLICACION DE LAS CARACTERISTICAS DEL VH, EN LA ENTREGA', 27, 2, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(15, 'V06A', 'DEMORA TIEMPO DE ENTREGA', 27, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(16, 'V07D', 'PROBLEMAS CON EL SERVICIO DE DISPOSITIVO SATELITAL', 28, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(17, 'V07C', 'DEMORA O FALTA DE ACTIVACION DEL DISPOSITIVO SATELITAL', 28, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(18, 'V07B', 'DEMORA O FALTA DE INSTALACION DEL DISPOSITIVO SATELITAL', 28, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(19, 'V07A', 'DISPOSITIVO SATELITAL', 28, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(20, 'V08C', 'DEMORA DE CONTRATOS', 29, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(21, 'V08B', 'DEMORA EN EL FINANCIAMIENTO', 29, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(22, 'V08A', 'FINANCIAMIENTO', 29, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(23, 'V09B', 'NO DISPONEN EL COLOR O MODELO QUE SOLICITO', 30, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(24, 'V09A', 'STOCK DE VEHICULOS', 30, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(25, 'V11C', 'DEMORA O FALTA DE LA POLIZA DE SEGURO', 31, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(26, 'V11B', 'FALTA DE INFORMACION DEL SEGURO', 31, 2, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(27, 'V11A', 'SEGURO', 31, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(28, 'V01B', 'CLIENTE SATISFECHO, PERO CALIFICA CON BAJA NOTA', 22, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(29, 'V01A', 'CLIENTE SATISFECHO', 22, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(30, 'V02A', 'MALA ATENCION', 23, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(31, 'T01A', 'CLIENTE SATISFECHO', 1, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(32, 'T01B', 'CLIENTE SATISFECHO, PERO CALIFICA CON BAJA NOTA', 1, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(33, 'T02A', 'DEMORA TIEMPO DE ENTREGA DEL VEHICULO', 2, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(34, 'T03B', 'DEMORA TIEMPO DE RECEPCION TECNICO', 3, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(35, 'T03A', 'DEMORA TIEMPO DE RECEPCION DEL VEHICULO', 3, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(36, 'T03D', 'DEMORA TIEMPO DE RECEPCION CENTRAL DE ATENCION', 3, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(37, 'T03C', 'DEMORA TIEMPO DE RECEPCION JEFE DE TALLER', 3, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(38, 'T04D', 'NO UTILIZAN PRODUCTOS NUEVOS O SELLADOS', 4, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(39, 'T04A', 'REPUESTOS', 4, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(40, 'T04B', 'NO HAY DISPONIBILIDAD DE REPUESTOS', 4, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(41, 'T04C', 'NO UTILIZAN REPUESTOS ORIGINALES', 4, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(42, 'T05F', 'ROBOS O PERDIDAS', 5, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(43, 'T05D', 'MALA ATENCION CENTRAL DE ATENCION', 5, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(44, 'T05C', 'MALA ATENCION JEFE DE TALLER', 5, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(45, 'T05B', 'MALA ATENCION TECNICO', 5, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(46, 'T05A', 'MALA ATENCION', 5, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(47, 'T05E', 'MALA ATENCION CAJA', 5, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(48, 'T06D', 'FALTA DE INFORMACION DE TRABAJOS REALIZADOS', 6, 1, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(49, 'T06C', 'FALTA DE INFORMACION FECHA Y HORA DE ENTREGA DEL VH', 6, 1, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(50, 'T06B', 'FALTA DE INFORMACION PRECIOS, DESCUENTOS Y PROMOCIONES', 6, 1, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(51, 'T06A', 'FALTA DE INFORMACION', 6, 1, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(52, 'T06E', 'FALTA DE INFORMACION EN LA REVISION DEL VH O TRABAJOS POR REALIZAR', 6, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(53, 'T06F', 'FALTA DE INFORMACION DE REPUESTOS UTILIZADOS', 6, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(54, 'T07E', 'POR TRABAJOS O INGRESOS ANTERIORES', 7, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(55, 'T07A', 'PERSISTE EL PROBLEMA TECNICO', 7, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(56, 'T07C', 'PERSISTE EL PROBLEMA DE GARANTIA', 7, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(57, 'T07B', 'VEHICULO SALIO CON OTRO DAÑO', 7, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(58, 'T07D', 'FALTO REALIZAR TRABAJOS', 7, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(59, 'T08A', 'PRECIO ELEVADO', 8, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(60, 'T09D', 'NO LE ENTREGARON EL VEHICULO LAVADO', 9, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(61, 'T09B', 'FALTA LIMPIEZA INTERIOR', 9, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(62, 'T09A', 'FALTA LIMPIEZA DEL VEHICULO', 9, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(63, 'T09C', 'FALTA LIMPIEZA EXTERIOR', 9, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(64, 'T10D', 'NO AGENDARON BIEN LA CITA', 10, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(65, 'T10A', 'AGENDAMIENTO DE CITAS', 10, 1, 1, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(66, 'T10C', 'NO CONTESTAN EL TELEFONO PARA AGENDAR UNA CITA', 10, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(67, 'T10B', 'NO HAY DISPONIBILIDAD DE CITAS', 10, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(68, 'T11A', 'OTROS', 11, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(69, 'T12C', 'EQUIPO Y HERRAMIENTAS', 12, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(70, 'T12B', 'INSTALACIONES E INFRAESTRUCTURA', 12, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(71, 'V12G', 'FALTA DE HONESTIDAD Y TRANSPARENCIA', 32, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(72, 'V12F', 'FALTA GESTION', 32, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(73, 'V12E', 'PRESENTACION E IMAGEN PERSONAL', 32, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(74, 'V12D', 'AUSENCIA DE EMPLEADOS O NO HAY QUIEN ATIENDA', 32, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(75, 'V12C', 'FALTA DE CONOCIMIENTO Y CAPACITACION', 32, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(76, 'V12B', 'ROBOS O PERDIDAS', 32, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(77, 'V12A', 'FALTA ATENCION Y AMABILIDAD', 32, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(78, 'V13B', 'EQUIPO Y HERRAMIENTAS', 33, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(79, 'V13A', 'INSTALACIONES E INFRAESTRUCTURA', 33, 2, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(80, 'T13C', 'NO AGENDARON BIEN LA CITA', 13, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(81, 'T13B', 'NO CONTESTAN EL TELEFONO PARA AGENDAR UNA CITA', 13, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(82, 'T13A', 'NO HAY DISPONIBILIDAD DE CITAS', 13, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(83, 'T14A', 'RECEPCION Y DIRECCIONAMIENTO', 14, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(84, 'T15A', 'FALTA INFORMACION DE LOS TRABAJOS POR REALIZAR O MAL DIAGNOSTICO', 15, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(85, 'T16B', 'NO HAY DISPONIBILIDAD DE REPUESTOS', 16, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(86, 'T16C', 'TRABAJOS REALIZADOS SIN AUTORIZACION', 16, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(87, 'T16A', 'FALTA DE INFORMACION PRECIOS, DESCUENTOS Y PROMOCIONES', 16, 1, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(88, 'T17A', 'CENTRAL DE ATENCION', 17, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(89, 'T18G', 'DEMASIADO TIEMPO PARA UN TRABAJO (PERCEPCION DEL CLIENTE)', 18, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(90, 'T18F', 'GARANTIA', 18, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(91, 'T18E', 'POR TRABAJOS ANTERIORES O RETORNOS', 18, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(92, 'T18D', 'FALTO REALIZAR TRABAJOS', 18, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(93, 'T18C', 'VEHICULO SALIO CON OTRO DAÑO', 18, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(94, 'T18B', 'PERSISTE EL PROBLEMA TECNICO', 18, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(95, 'T18A', 'NO SE UTILIZA PROTECTORES PLASTICOS PARA PROTEGER EL VH', 18, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(96, 'T19A', 'INCONVENIENTES EN EL PROCESO DE PAGO Y FACTURACION', 19, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(97, 'T20E', 'FALTA DE INFORMACION DE REPUESTOS UTILIZADOS', 20, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(98, 'T20D', 'VEHICULO SIN LAVAR O FALTA LIMPIEZA', 20, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(99, 'T20C', 'FALTA DE INFORMACION DE TRABAJOS REALIZADOS', 20, 1, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(100, 'T20B', 'FALTA DE INFORMACION FECHA Y HORA DE ENTREGA DEL VH', 20, 1, 4, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(101, 'T20A', 'DEMORA TIEMPO DE ENTREGA DEL VEHICULO', 20, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(102, 'T21F', 'FALTA GESTION', 21, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(103, 'T21G', 'FALTA DE HONESTIDAD Y TRANSPARENCIA', 21, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(104, 'T21E', 'PRESENTACION E IMAGEN PERSONAL', 21, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(105, 'T21D', 'AUSENCIA DE EMPLEADOS O NO HAY QUIEN ATIENDA', 21, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(106, 'T21C', 'FALTA DE CONOCIMIENTO Y CAPACITACION', 21, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(107, 'T21B', 'ROBOS O PERDIDAS', 21, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37'),
(108, 'T21A', 'FALTA ATENCION Y AMABILIDAD', 21, 1, 2, 1, '2025-09-05 05:52:37', '2025-09-05 05:52:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clientes`
--

CREATE TABLE `clientes` (
  `idCliente` bigint(20) NOT NULL,
  `idClienteErp` varchar(50) DEFAULT NULL,
  `cedula` varchar(20) NOT NULL,
  `nombres` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `celular` varchar(30) DEFAULT NULL,
  `email` varchar(120) NOT NULL,
  `consentimientoDatos` tinyint(1) NOT NULL DEFAULT 1,
  `fechaConsentimiento` timestamp NULL DEFAULT NULL,
  `bloqueadoEncuestas` tinyint(1) NOT NULL DEFAULT 0,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `clientes`
--

INSERT INTO `clientes` (`idCliente`, `idClienteErp`, `cedula`, `nombres`, `apellidos`, `telefono`, `celular`, `email`, `consentimientoDatos`, `fechaConsentimiento`, `bloqueadoEncuestas`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, '0801261462001', '0801261462', 'JUANITA MARIA', 'TENORIO DELGADO', '062751369', '0969276744', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(2, '0801956863001', '0801956863', 'JAQUELINE SONIA', 'ZURITA SACON', '0', '0985571153', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(3, '1313422923001', '1313422923', 'MAURICIO MOISES', 'ESMERALDAS MANZABA', '062720000', '0939083823', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(4, '0802435073001', '0802435073', 'PETER ANTONIO', 'PORTOCARRERO CAMACHO', '062766315', '0993665264', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(5, '0990006059001', '0990006059001', 'FRENOSEGURO CIA. LTDA.', ' ', '045004500', '0990103507', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(6, '0852159342001', '0852159342', 'JOSE LUIS', 'ROJAS GUIPPE', '062700000', '0986165283', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(7, '0400626883001', '0400626883', 'NELSON EDUARDO', 'CHULDE RUANO', '022700000', '0987434391', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(8, '0800288763001', '0800288763', 'MANUEL EDUARDO', 'RODRIGUEZ GAMEZ', '062720000', '0992275827', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(9, '0804095941001', '0804095941', 'ANA AMANDA', 'GONZALEZ OLIVES', '0627000000', '0962602397', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(10, '0400515375001', '0400515375', 'SEGUNDO RICARDO', 'REGALADO CUPUERAN', '0', '0962753297', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(11, '0990023859001', '0990023859001', 'VALLEJO ARAUJO S.A.', ' ', '042201651', '0958854701', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(12, '0850947219001', '0850947219', 'PATRICIA A LEXANDRA', 'CASQUETE BATIOJA', '062700000', '0962716078', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(13, '1711769511001', '1711769511001', 'BERONICA ERNESTINA', 'SULCA CHILLAN', '0', '0995512978', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(14, '1309154761001', '1309154761', 'MIXI MILENA', 'PARRALES PALACIOS', '062700000', '0994148196', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(15, '1002550091001', '1002550091', 'JAIME TARQUINO', 'RUIZ ANDRADE', '098366500', '0998804275', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(16, '0802292045001', '0802292045', 'ANA MARIA FERNANDA', 'CEPEDA RODRIGUEZ', '0627000000', '0997788168', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(17, '0801732207001', '0801732207', 'RAMIRO MADONIO', 'MERCADO GARCES', '062700000', '0963403789', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(18, 'YB5079905', 'YB5079905', 'DIONIGI', 'GIANCASPRO', '0', '0967057147', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(19, '1803297116001', '1803297116', 'FREDY JAVIER', 'PAZMIÑO PALMA', '062000000', '0993413392', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(20, '0803592021001', '0803592021', 'MARCOS ANTONIO', 'CHICA AVILA', '0627000000', '0997056264', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(21, '0953274826001', '0953274826', 'MIRIAN MARGARITA', 'SALTOS VALLA', '0', '0999848071', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(22, '1756170484001', '1756170484', 'RICARDO', 'ALMEIDA VARELA', '0627000000', '0960893122', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(23, '1718872540001', '1718872540', 'MARLON EMILIO', 'PILLASAGUA LUNA', '062700000', '0959167583', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(24, '0804238186001', '0804238186', 'EMILY JULIANA', 'GUERRERO SALAZAR', '062700000', '0990311457', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(25, '1705506275001', '1705506275001', 'JUSTO PASTOR', 'REVELO ORTEGA', '062653470', '0992078963', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(26, '0850546656001', '0850546656', 'ROMEL IGNACIO', 'QUINTERO MARTINEZ', '0', '0989412013', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(27, '0490041877001', '0490041877001', 'SINDICATO DE CHOFERES PROFESIONALES DEL CANTON ESPEJO', ' ', '062977206', '0981907709', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(28, '1717728115001', '1717728115001', 'JUAN CARLOS', 'IMBAGO TOAPANTA', '022000000', '0979069162', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(29, '0801148933001', '0801148933', 'DOMINGA PERSIDES', 'MINA RODRIGUEZ', '0627000000', '0991093289', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(30, '0491534184001', '0491534184001', 'FUNDACION DE VIVIENDA SOCIAL PADRE LUIS CLEMENTE DE LA VEGA', ' ', '062750000', '0982345104', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(31, '2100196530001', '2100196530', 'JHONNY SEFERINO', 'CEDEÑO ZAMBRANO', '062000000', '0994443175', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(32, '2100189071001', '2100189071001', 'ROBERTO CARLOS', 'CALLE GONZAGA', '062343008', '0988938669', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(33, '0803156371001', '0803156371', 'MARIUXI MAOLI', 'OBANDO LUGO', '062700000', '0999640411', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(34, '0801660358001', '0801660358', 'SHANEPROL ALEXANDRA', 'VITERI CHAVEZ', '062700000', '0990858240', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(35, '1000881118001', '1000881118', 'JORGE RUBEN', 'RIVADENEIRA POSSO', '062951432', '0995877132', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(36, '0802256123001', '0802256123', 'LIDIA BEATRIZ', 'CEVALLOS QUINTERO', '062700000', '0993237610', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(37, '1716592074001', '1716592074', 'CARMEN DEL ROCIO', 'REYES CEDEÑO', '0', '0962911569', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(38, '1600325904001', '1600325904', 'CARLOS MANUEL', 'ACOSTA COCA', '0', '0990715675', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(39, '0401671987001', '0401671987001', 'CARLOS MARIO', 'CORAL VILLOTA', '062000000', '0992095722', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(40, '0801827221001', '0801827221', 'ROXANA SOLANGE', 'SALAZAR MARTINEZ', '062700000', '0939834682', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(41, '1793130755001', '1793130755001', 'AUTO LEATHER', ' ', '022000000', '0988254580', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(42, '1713602868001', '1713602868', 'ROSA ARCELIA', 'LOOR', '062700000', '0985105109', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(43, '0400497210001', '0400497210001', 'EDWIN HERNANDO', 'SANTAFE POZO', '062607974', '0994416106', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(44, '0803934355001', '0803934355', 'TANIA ELIZABETH', 'OLMEDO YELA', '0', '0979606648', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(45, '0801980665001', '0801980665', 'JOSE DAVID', 'VERA RIVERA', '062700000', '0996688138', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(46, '0803416684001', '0803416684', 'ROVER MANUEL', 'NAZARENO MICOLTA', '062700000', '0989809764', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(47, '1754483020001', '1754483020', 'CARLOS STEVEN', 'PILAPAÑA ESPIN', '062700000', '0993607360', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(48, '0803453778001', '0803453778', 'BELTRAN MODESTO', 'VALENCIA MONTES', '062000000', '0994828633', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(49, '0801820457001', '0801820457', 'JOFFRE WASHINGTON', 'MAZA JAYA', '062700000', '0998061378', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(50, '0402063085001', '0402063085', 'MIGUEL ANGEL', 'ACERO MUÑOS', '0', '0962281327', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(51, '1004815682001', '1004815682001', 'JENNY PAMELA', 'CAIZA CONLAGO', '0', '0939692862', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(52, '0801714510001', '0801714510', 'EDITH OFELIA', 'NIEVES ESTRADA', '062700000', '0985084208', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(53, '0801943135001', '0801943135', 'GEOVANNYDE JESUS', 'PERDOMO GALLEGOS', '062700000', '0990032531', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(54, '0800847600001', '0800847600', 'AUDRY ROCIO', 'AYOVI QUIÑONEZ', '0', '0989472145', 'adrian.merlo.am3+50@gmail.com', 1, NULL, 0, 1, '2025-09-15 16:28:45', '2025-09-15 16:28:45'),
(55, '0801391434001', '0801391434', 'WALTER WILFRIDO', 'CEDEÑO RIVADENEIRA', '062700000', '0995124869', 'adrian.merlo.am3+50@gmail.com', 1, '2025-09-30 02:08:43', 0, 1, '2025-09-15 16:28:45', '2025-09-30 02:08:43'),
(56, '0803157221001', '0803157221', 'JOHANNA ELIZABETH', 'MENDOZA ZAMBRANO', '062000000', '0994258036', 'adrian.merlo.am3@gmail.com', 1, NULL, 0, 1, '2025-09-18 23:09:57', '2025-09-29 21:39:56'),
(57, '2100758370001', '2100758370', 'JOSUE PAUL', 'PINDO TENESACA', '062831592', '0968232073', 'adrian.merlo.am3@gmail.com', 1, NULL, 0, 1, '2025-09-19 02:49:42', '2025-09-29 21:39:56'),
(58, '1102951850001', '1102951850', 'LUIS GONZALO', 'CANGO PATIÑO', '072585168', '0991000965', 'adrian.merlo.am3@gmail.com', 1, NULL, 0, 1, '2025-09-20 17:58:34', '2025-09-29 21:39:56'),
(61, '1709109563001', '1709109563', 'JULIO BENIGNO', 'MEDRANDA GARCIA', '062000000', '0980423300', 'adrian.merlo.am3+52@gmail.com', 1, NULL, 0, 1, '2025-09-25 04:41:21', '2025-09-25 06:01:08'),
(62, '0800360042001', '0800360042', 'FELIX SATURNFO', 'HURTADO ALARCON', '062000000', '0993580720', 'adrian.merlo.am3+52@gmail.com', 1, '2025-09-27 15:29:30', 0, 1, '2025-09-25 16:34:42', '2025-09-27 15:29:30'),
(63, '3050779341001', '3050779341', 'IVAN CAMILO', 'RUBIO ZAMBRANO', NULL, '0999101191', 'adrian.merlo.am3+52@gmail.com', 1, '2025-09-29 20:43:44', 0, 1, '2025-09-28 04:11:54', '2025-09-29 20:43:44'),
(64, '0401510029001', '0401510029', 'LUIS ANDERSON', 'FUEL VILLARREAL', '0985321701', '0984806883', 'adrian.merlo.am3+52@gmail.com', 1, NULL, 0, 1, '2025-09-28 04:11:54', '2025-09-28 04:11:54'),
(65, '0800715930001', '0800715930', 'GUSTAVO ALFREDO', 'LOZANO MIDEROS', '062700000', '0988134931', 'adrian.merlo.am3+52@gmail.com', 1, NULL, 0, 1, '2025-09-29 20:53:55', '2025-09-29 20:55:25'),
(66, '0401364039001', '0401364039', 'JUAN CARLOS', 'CRUZ ALMEIDA', '062364003', '0985692431', 'adrian.merlo.am3@gmail.com', 1, '2025-09-29 21:43:58', 0, 1, '2025-09-29 21:02:29', '2025-09-29 21:43:58'),
(67, '0804211761001', '0804211761', 'OLGER ROLANDO', 'LARGO PIANCHICHE', '062780774', '0960787465', 'adrian.merlo.am3@gmail.com', 1, '2025-09-29 22:38:13', 0, 1, '2025-09-29 21:51:43', '2025-09-30 02:06:47'),
(68, '0804168631001', '0804168631', 'JOSE DAVID', 'KLINGER MARTINEZ', '062700000', '0999367131', 'adrian.merlo.am3@gmail.com', 1, '2025-09-29 23:24:26', 0, 1, '2025-09-29 22:41:37', '2025-09-29 23:24:26');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clientes_consentimientos`
--

CREATE TABLE `clientes_consentimientos` (
  `id` bigint(20) NOT NULL,
  `idCliente` bigint(20) NOT NULL,
  `aceptado` tinyint(1) NOT NULL,
  `origen` enum('ENCUESTA','PORTAL','AGENTE') NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `userAgent` varchar(255) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `clientes_consentimientos`
--

INSERT INTO `clientes_consentimientos` (`id`, `idCliente`, `aceptado`, `origen`, `ip`, `userAgent`, `fecha`) VALUES
(1, 1, 1, 'ENCUESTA', '190.95.88.153', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-15 22:11:01'),
(2, 2, 1, 'ENCUESTA', '190.95.210.21', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 03:08:46'),
(3, 3, 1, 'ENCUESTA', '201.219.148.219', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 04:11:21'),
(4, 4, 1, 'ENCUESTA', '181.39.221.101', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 15:40:45'),
(5, 5, 1, 'ENCUESTA', '190.95.207.121', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(6, 6, 1, 'ENCUESTA', '179.189.209.117', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(7, 7, 1, 'ENCUESTA', '181.39.152.233', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(8, 8, 1, 'ENCUESTA', '179.189.10.127', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(9, 9, 1, 'ENCUESTA', '190.95.49.240', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(10, 10, 1, 'ENCUESTA', '190.95.116.42', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(11, 11, 1, 'ENCUESTA', '186.46.109.226', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(12, 12, 1, 'ENCUESTA', '201.219.204.35', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(13, 13, 1, 'ENCUESTA', '179.189.180.36', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(14, 14, 1, 'ENCUESTA', '186.46.119.42', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(15, 15, 1, 'ENCUESTA', '181.39.42.153', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(16, 16, 1, 'ENCUESTA', '190.95.127.64', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(17, 17, 1, 'ENCUESTA', '179.189.247.147', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(18, 18, 1, 'ENCUESTA', '201.219.175.94', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(19, 19, 1, 'ENCUESTA', '186.46.234.179', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(20, 20, 1, 'ENCUESTA', '186.46.55.107', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(21, 21, 1, 'ENCUESTA', '190.95.239.6', 'ENCUESTA: 1 CSI Ventas Livianos Online', '2025-09-17 17:23:42'),
(59, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5NTYzNzZ9.1XNu8PWH29drea1Pn0mkd2WNHOVHQnUySERyoK5cut8', '2025-09-27 06:59:36'),
(60, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5NTY0Njd9.IHOWnMbpBFgdhFsKawqLrgiw_wQanpexDUMYYf7WRyk', '2025-09-27 07:01:07'),
(61, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5NTY3NDF9.9rbTQd4OsiKNlVx0y4kicGj1GFiVQUZamdRMTvf9RFQ', '2025-09-27 07:05:41'),
(62, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5NTY3NTJ9.cyD78NlXlci8kftOW4YDVPdKDAVCXJe5Ifh6yhEspTo', '2025-09-27 07:05:52'),
(63, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5NTY3NjN9.33eNl4aYM3_rBQAih1vTvWr63qgsEpjboEo0_i73RwI', '2025-09-27 07:06:03'),
(64, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5NTY3OTh9.RbzBvbqdcpxY_7CkMDNf_Vj2VPQ0F0IUVaeIyoOPwlM', '2025-09-27 07:06:38'),
(65, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5NTY4ODF9.EbP06C3hlqR-s8aP4ibx79hd0RcJWVulcLTCVPmu16U', '2025-09-27 07:08:01'),
(66, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5NTc0NzZ9.1e76aLVKfVvO6yBiI2bNdletasAU2wmHugLyv5cVq54', '2025-09-27 07:17:56'),
(67, 62, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYyLCJwaWQiOjUwLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTg5ODY5NzB9.Mb0oEKs85OD5FgeT0x1hgDw-q6-pl8dwExtafkt3BSc', '2025-09-27 15:29:30'),
(68, 63, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYzLCJwaWQiOjUxLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTkwMzI4MjB9.2HxeQobZZso7ncJDV6hgpIu3OUOT-etGngLPUhAS6oo', '2025-09-28 04:13:40'),
(69, 63, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjYzLCJwaWQiOjUxLCJlbSI6IjFhYTUzMjdlOTFlOWFjYjA3YTFhYjU5YzVlZDc3ZGE2YTAwYWNjYzM3ZGUzMGIyMThjMTYxZjdhYTM0MGQ5ODciLCJpYXQiOjE3NTkxNzg2MjR9.IuDVjwlNjl53aQBargt2ibAIv-70FRCs2frDuRwBH84', '2025-09-29 20:43:44'),
(70, 66, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjY2LCJwaWQiOjUzLCJlbSI6ImEzZjA2YTE1OGQ2YTlkNDAxZTNmYjRlYmJhZjdjNGYwZWEyNzFhNjE4YmIxZDVlODI4NWM0NzVmYTAyNmY1MTAiLCJpYXQiOjE3NTkxODIyMzh9.cdKi7HwHXxnctGUQw3ns15yZtJWLLbAc34BChbFtRCw', '2025-09-29 21:43:58'),
(71, 67, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjY3LCJwaWQiOjU0LCJlbSI6ImEzZjA2YTE1OGQ2YTlkNDAxZTNmYjRlYmJhZjdjNGYwZWEyNzFhNjE4YmIxZDVlODI4NWM0NzVmYTAyNmY1MTAiLCJpYXQiOjE3NTkxODI3Mzl9.Mm0SwVhAjwG1Kavl5wqv--F4inwuiVCVUU1QE4ezsuU', '2025-09-29 21:52:20'),
(72, 67, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjY3LCJwaWQiOjU0LCJlbSI6ImEzZjA2YTE1OGQ2YTlkNDAxZTNmYjRlYmJhZjdjNGYwZWEyNzFhNjE4YmIxZDVlODI4NWM0NzVmYTAyNmY1MTAiLCJpYXQiOjE3NTkxODUxMTJ9.urUgJk6dbYF3JLfOymrgGMNV2Kdl8xTJ0CI9_-8RsRE', '2025-09-29 22:31:52'),
(73, 67, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjY3LCJwaWQiOjU0LCJlbSI6ImEzZjA2YTE1OGQ2YTlkNDAxZTNmYjRlYmJhZjdjNGYwZWEyNzFhNjE4YmIxZDVlODI4NWM0NzVmYTAyNmY1MTAiLCJpYXQiOjE3NTkxODU0OTN9.C5e2WDzLH68nm2smbdNO93vSpGdcyk4eaLrS3I6fLcI', '2025-09-29 22:38:13'),
(74, 68, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjY4LCJwaWQiOjU1LCJlbSI6ImEzZjA2YTE1OGQ2YTlkNDAxZTNmYjRlYmJhZjdjNGYwZWEyNzFhNjE4YmIxZDVlODI4NWM0NzVmYTAyNmY1MTAiLCJpYXQiOjE3NTkxODU3MzR9.Z-MxCDzO2wAnmTG2CHiNZbIIj_mc-ws2NvT5AdUqENs', '2025-09-29 22:42:14'),
(75, 68, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjY4LCJwaWQiOjU1LCJlbSI6ImEzZjA2YTE1OGQ2YTlkNDAxZTNmYjRlYmJhZjdjNGYwZWEyNzFhNjE4YmIxZDVlODI4NWM0NzVmYTAyNmY1MTAiLCJpYXQiOjE3NTkxODgyNjZ9.P21ZyN3Inx8XQfCZuGaecMIi0PfvRwExmKpMAoPTSv0', '2025-09-29 23:24:26'),
(76, 55, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjU1LCJwaWQiOjU2LCJlbSI6IjI0NWU0NzU0ZTYxMDg3YWI4ZjgyODdiODVmZjg3MzY1YjJlZmM0MWM2M2ExMjU3NDdkZWUwOTY4OGM4MWRjZTMiLCJpYXQiOjE3NTkxOTAzODR9.5eFe4Mrv7HCA3z81GAaFXIpUI77cA0gW9CHD_Lynxn8', '2025-09-29 23:59:44'),
(77, 55, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjU1LCJwaWQiOjU2LCJlbSI6IjI0NWU0NzU0ZTYxMDg3YWI4ZjgyODdiODVmZjg3MzY1YjJlZmM0MWM2M2ExMjU3NDdkZWUwOTY4OGM4MWRjZTMiLCJpYXQiOjE3NTkxOTU2NDN9.q_LV5zVzs4ZgEenhfZk1jLtmsIdaArggeOoZe3E38rs', '2025-09-30 01:27:23'),
(78, 55, 1, 'ENCUESTA', '127.0.0.1', 'eyJjaWQiOjU1LCJwaWQiOjU2LCJlbSI6IjI0NWU0NzU0ZTYxMDg3YWI4ZjgyODdiODVmZjg3MzY1YjJlZmM0MWM2M2ExMjU3NDdkZWUwOTY4OGM4MWRjZTMiLCJpYXQiOjE3NTkxOTgxMjN9.chPwfKshIYCx-PCnSKqapsRS2SK84NtN10kxl3CNc4M', '2025-09-30 02:08:43');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `config_escalamiento`
--

CREATE TABLE `config_escalamiento` (
  `idConfig` int(11) NOT NULL,
  `idAgencia` int(11) NOT NULL,
  `idEncuesta` bigint(20) NOT NULL,
  `nivel` tinyint(4) NOT NULL,
  `idResponsable` int(11) NOT NULL,
  `horasSLA` int(11) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `config_escalamiento`
--

INSERT INTO `config_escalamiento` (`idConfig`, `idAgencia`, `idEncuesta`, `nivel`, `idResponsable`, `horasSLA`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 1, 1, 1, 5, 24, 1, '2025-09-16 00:20:17', '2025-09-16 00:21:53'),
(2, 1, 1, 2, 10, 24, 1, '2025-09-16 00:21:37', '2025-09-16 00:21:37'),
(3, 1, 1, 3, 15, 24, 1, '2025-09-16 00:22:43', '2025-09-16 00:22:43'),
(4, 2, 1, 1, 6, 24, 1, '2025-09-16 00:38:19', '2025-09-16 00:38:19'),
(5, 2, 1, 2, 6, 24, 1, '2025-09-16 00:38:19', '2025-09-16 00:38:19'),
(6, 2, 1, 3, 15, 24, 1, '2025-09-16 00:38:48', '2025-09-16 00:38:48'),
(7, 3, 1, 1, 7, 24, 1, '2025-09-16 00:39:48', '2025-09-16 00:39:48'),
(8, 3, 1, 2, 12, 24, 1, '2025-09-16 00:39:48', '2025-09-16 00:39:48'),
(9, 3, 1, 3, 15, 24, 1, '2025-09-16 00:40:33', '2025-09-16 00:40:33'),
(10, 4, 1, 1, 8, 24, 1, '2025-09-16 00:41:06', '2025-09-16 00:41:06'),
(11, 4, 1, 2, 13, 24, 1, '2025-09-16 00:41:06', '2025-09-16 00:42:00'),
(12, 4, 1, 3, 15, 24, 1, '2025-09-16 00:41:06', '2025-09-16 00:42:00'),
(13, 5, 1, 1, 9, 24, 1, '2025-09-16 01:44:54', '2025-09-16 01:44:54'),
(14, 5, 1, 2, 14, 24, 1, '2025-09-16 01:44:54', '2025-09-16 01:44:54'),
(15, 5, 1, 3, 15, 24, 1, '2025-09-16 01:45:21', '2025-09-16 01:45:21');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `encuestas`
--

CREATE TABLE `encuestas` (
  `idEncuesta` bigint(20) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `asuntoCorreo` varchar(200) NOT NULL,
  `remitenteNombre` varchar(120) NOT NULL,
  `scriptInicio` mediumtext NOT NULL,
  `scriptFinal` mediumtext DEFAULT NULL,
  `idCanal` int(11) NOT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `encuestas`
--

INSERT INTO `encuestas` (`idEncuesta`, `nombre`, `asuntoCorreo`, `remitenteNombre`, `scriptInicio`, `scriptFinal`, `idCanal`, `activa`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'CSI Ventas Livianos Online', 'Correo: Cuentenos su experiencia', 'IMBAUTO VEHICULOS NUEVOS', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos', 'Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 2, 1, '2025-09-06 03:06:03', '2025-09-17 03:22:56'),
(2, 'CSI Posventa Online', 'Correo: Cuentenos su experiencia', 'IMBAUTO POSTVENTA TALLER DE SERVICIO', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos', 'Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 1, 1, '2025-09-06 03:29:29', '2025-09-17 03:22:32');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `encuestasprogramadas`
--

CREATE TABLE `encuestasprogramadas` (
  `idProgEncuesta` bigint(20) NOT NULL,
  `idEncuesta` bigint(20) NOT NULL,
  `idAtencion` bigint(20) NOT NULL,
  `idCliente` bigint(20) NOT NULL,
  `fechaProgramadaInicial` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `intentosEnviados` tinyint(4) NOT NULL DEFAULT 0,
  `maxIntentos` tinyint(4) NOT NULL DEFAULT 3,
  `proximoEnvio` timestamp NULL DEFAULT NULL,
  `estadoEnvio` enum('PENDIENTE','ENVIADA','RESPONDIDA','NO_CONTESTADA','EXCLUIDA') NOT NULL DEFAULT 'PENDIENTE',
  `canalEnvio` enum('EMAIL','WHATSAPP','SMS','OTRO') DEFAULT NULL,
  `enviadoPor` int(11) DEFAULT NULL,
  `observacionEnvio` varchar(255) DEFAULT NULL,
  `ultimoEnvio` timestamp NULL DEFAULT NULL,
  `asuntoCache` varchar(200) DEFAULT NULL,
  `cuerpoHtmlCache` mediumtext DEFAULT NULL,
  `tokenEncuesta` varchar(64) DEFAULT NULL,
  `consentimientoAceptado` tinyint(1) DEFAULT NULL,
  `fechaConsentimiento` timestamp NULL DEFAULT NULL,
  `observaciones` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `encuestasprogramadas`
--

INSERT INTO `encuestasprogramadas` (`idProgEncuesta`, `idEncuesta`, `idAtencion`, `idCliente`, `fechaProgramadaInicial`, `intentosEnviados`, `maxIntentos`, `proximoEnvio`, `estadoEnvio`, `canalEnvio`, `enviadoPor`, `observacionEnvio`, `ultimoEnvio`, `asuntoCache`, `cuerpoHtmlCache`, `tokenEncuesta`, `consentimientoAceptado`, `fechaConsentimiento`, `observaciones`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 1, 1, 1, '2025-09-17 22:14:04', 0, 3, '2025-01-26 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', 'Bienvenido/a <PERSONA> a la encuesta de experiencia del cliente Chevrolet IMBAUTO<p><a href=\"https://app.imbauto.com.ec/encuesta?token=0d2bede655a612630300fc91f50f96ca3dd0933df8665380\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '0d2bede655a612630300fc91f50f96ca3dd0933df8665380', 1, '2025-09-15 22:11:01', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-15 16:32:09', '2025-09-17 22:14:04'),
(2, 1, 2, 2, '2025-09-17 22:14:04', 0, 3, '2025-02-01 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', 'Bienvenido/a <PERSONA> a la encuesta de experiencia del cliente Chevrolet IMBAUTO<p><a href=\"https://app.imbauto.com.ec/encuesta?token=83e92cc1ff7736c856617fd276af8038aeb06eb782e04ff3\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '83e92cc1ff7736c856617fd276af8038aeb06eb782e04ff3', 1, '2025-09-17 03:08:46', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-16 22:57:37', '2025-09-17 22:14:04'),
(3, 1, 3, 3, '2025-09-17 22:14:04', 0, 3, '2025-03-02 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=005243d70e58c1760cac38f0fdb5ceee4f6025446eadb5d4\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '005243d70e58c1760cac38f0fdb5ceee4f6025446eadb5d4', 1, '2025-09-17 04:11:21', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:40:50', '2025-09-17 22:14:04'),
(4, 1, 4, 4, '2025-09-17 22:14:04', 0, 3, '2025-03-02 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=956e33eb2ee3445d16410430c95be8e4a29f0d5cc021384c\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '956e33eb2ee3445d16410430c95be8e4a29f0d5cc021384c', 1, '2025-09-17 15:40:45', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(5, 1, 5, 5, '2025-09-17 22:14:04', 0, 3, '2025-03-03 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=782387bf56caa49ce3ede3712ba1612f1913966727893885\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '782387bf56caa49ce3ede3712ba1612f1913966727893885', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(6, 1, 6, 6, '2025-09-17 22:14:04', 0, 3, '2025-03-03 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=5c5f5f09bc54b71942b091e52871da3abfe93cd219aebd7c\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '5c5f5f09bc54b71942b091e52871da3abfe93cd219aebd7c', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(7, 1, 7, 7, '2025-09-17 22:14:04', 0, 3, '2025-03-10 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=0a6e232328266c7fec41eb9500e3350ff263500999e64415\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '0a6e232328266c7fec41eb9500e3350ff263500999e64415', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(8, 1, 8, 8, '2025-09-17 22:14:04', 0, 3, '2025-03-23 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=075cf4736da2ec2b0221e4f643804c0815c0017dbeaa624e\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '075cf4736da2ec2b0221e4f643804c0815c0017dbeaa624e', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(9, 1, 9, 9, '2025-09-17 22:14:04', 0, 3, '2025-03-29 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=a51c734961c2c4a57ea8ae1f6894504d5e23b479ea871795\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'a51c734961c2c4a57ea8ae1f6894504d5e23b479ea871795', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(10, 1, 10, 10, '2025-09-17 22:14:04', 0, 3, '2025-03-30 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=abd2503e8639d0cc3e0da5ea8f175047079bbe8e729c00b3\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'abd2503e8639d0cc3e0da5ea8f175047079bbe8e729c00b3', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(11, 1, 11, 11, '2025-09-17 22:14:04', 0, 3, '2025-04-01 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=b825d437bb35a8520a978f8a125bd7b70dd2e88fa2b0b77f\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'b825d437bb35a8520a978f8a125bd7b70dd2e88fa2b0b77f', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(12, 1, 12, 12, '2025-09-17 22:14:04', 0, 3, '2025-04-03 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=f922931e00c8bc7c19f1b152648700aa997268c65ac40e05\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'f922931e00c8bc7c19f1b152648700aa997268c65ac40e05', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(13, 1, 13, 13, '2025-09-17 22:14:04', 0, 3, '2025-04-05 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=c718827b4fcffd777087fefe0fbc30de0074729a041fd71b\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'c718827b4fcffd777087fefe0fbc30de0074729a041fd71b', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(14, 1, 14, 14, '2025-09-17 22:14:04', 0, 3, '2025-04-06 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=3355d73b773fe974821623a9f2bbb411a4ad600fb5c2e1ab\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '3355d73b773fe974821623a9f2bbb411a4ad600fb5c2e1ab', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(15, 1, 15, 15, '2025-09-17 22:14:04', 0, 3, '2025-04-10 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=d9b4c74c1e83f80f86d2ea7cad51440fea7dc00fc500e4fb\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'd9b4c74c1e83f80f86d2ea7cad51440fea7dc00fc500e4fb', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(16, 1, 16, 16, '2025-09-17 22:14:04', 0, 3, '2025-04-13 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=68518c928d45946badbccfb91ad3efaadc5f1541d06560d1\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '68518c928d45946badbccfb91ad3efaadc5f1541d06560d1', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(17, 1, 17, 17, '2025-09-17 22:14:04', 0, 3, '2025-04-18 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=c82b71fd0f56da836098cfb9c7a3e45b3d01635aa8da0cc3\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'c82b71fd0f56da836098cfb9c7a3e45b3d01635aa8da0cc3', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(18, 1, 18, 18, '2025-09-17 22:14:04', 0, 3, '2025-04-18 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=bf75071c77640a2167e9927ce59d78992d7aadb641c3f016\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'bf75071c77640a2167e9927ce59d78992d7aadb641c3f016', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(19, 1, 19, 19, '2025-09-17 22:14:04', 0, 3, '2025-04-26 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=5d1f1f0e9e727e2f23239915fab877af00ce530405de8013\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '5d1f1f0e9e727e2f23239915fab877af00ce530405de8013', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(20, 1, 20, 20, '2025-09-17 22:14:04', 0, 3, '2025-04-26 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=f2ebf9c8c825a1a578effcf7ac2c837a12bb412430af7e74\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'f2ebf9c8c825a1a578effcf7ac2c837a12bb412430af7e74', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(21, 1, 21, 21, '2025-09-17 22:14:04', 0, 3, '2025-05-03 14:00:00', 'RESPONDIDA', 'EMAIL', 2, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=60c52c308f0ebf8c65015d50e73aa21ed32973d90b23686b\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '60c52c308f0ebf8c65015d50e73aa21ed32973d90b23686b', 1, '2025-09-17 17:23:42', 'ENCUESTA: 1 CSI Ventas Livianos Online', 1, '2025-09-17 03:43:10', '2025-09-17 22:14:04'),
(22, 1, 56, 50, '2025-09-05 14:00:00', 0, 3, '2025-09-05 14:00:00', 'PENDIENTE', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=3c03749f5f0013bb1df92105beea240b45eddbc82fbb9611\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '3c03749f5f0013bb1df92105beea240b45eddbc82fbb9611', NULL, NULL, NULL, 1, '2025-09-19 02:59:39', '2025-09-19 02:59:39'),
(23, 1, 57, 51, '2025-09-05 14:00:00', 0, 3, '2025-09-05 14:00:00', 'PENDIENTE', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=ae0d2e3f096e790dba527c792d4ec409a259ad64d470b08a\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'ae0d2e3f096e790dba527c792d4ec409a259ad64d470b08a', NULL, NULL, NULL, 1, '2025-09-19 03:02:02', '2025-09-19 03:02:02'),
(24, 2, 62, 56, '2025-09-28 02:11:10', 0, 3, NULL, 'RESPONDIDA', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=76d2a686244d56213e8f5bb50150d7d75a2b9d615bc7141b\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '76d2a686244d56213e8f5bb50150d7d75a2b9d615bc7141b', NULL, NULL, NULL, 1, '2025-09-19 03:39:24', '2025-09-28 02:11:10'),
(25, 2, 63, 57, '2025-09-28 02:11:10', 0, 3, NULL, 'RESPONDIDA', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=c6d75427613afdd45ecda4d28689d805871e40bc4899f7eb\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'c6d75427613afdd45ecda4d28689d805871e40bc4899f7eb', NULL, NULL, NULL, 1, '2025-09-19 03:39:30', '2025-09-28 02:11:10'),
(35, 2, 64, 58, '2025-09-26 02:08:43', 0, 3, NULL, 'PENDIENTE', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=c6570bdebc7a7213c1dac81cca6b0d908a9f95bc1db71cfe\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', 'c6570bdebc7a7213c1dac81cca6b0d908a9f95bc1db71cfe', NULL, NULL, NULL, 1, '2025-09-26 02:08:43', '2025-09-26 02:08:43'),
(50, 2, 68, 62, '2025-09-28 02:11:10', 0, 3, '2025-08-01 14:00:00', 'RESPONDIDA', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=2dbad0179599eea3c742b568093cdde3d75a2424d84087cb\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '2dbad0179599eea3c742b568093cdde3d75a2424d84087cb', 1, '2025-09-27 15:29:30', 'ENCUESTA: 2-CSI Posventa Online', 1, '2025-09-26 06:03:06', '2025-09-28 02:11:10'),
(51, 2, 69, 63, '2025-09-29 20:43:44', 0, 3, '2025-06-26 14:00:00', 'PENDIENTE', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=27353c3bed1d6e6930d66726aa8d473381080d6ee2f75ac1\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '27353c3bed1d6e6930d66726aa8d473381080d6ee2f75ac1', 1, '2025-09-29 20:43:44', 'ENCUESTA: 2-CSI Posventa Online', 1, '2025-09-28 04:12:59', '2025-09-29 20:43:44'),
(53, 2, 73, 66, '2025-09-29 21:43:58', 0, 3, '2025-06-15 14:00:00', 'PENDIENTE', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=2e0e43c76ccfad80c1f085d88c26625a80ccc40b06638be3\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '2e0e43c76ccfad80c1f085d88c26625a80ccc40b06638be3', 1, '2025-09-29 21:43:58', 'ENCUESTA: 2-CSI Posventa Online', 1, '2025-09-29 21:43:00', '2025-09-29 21:43:58'),
(54, 2, 74, 67, '2025-09-29 22:38:13', 0, 3, '2025-06-06 14:00:00', 'PENDIENTE', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=47b6a26e0f27620c138b0325439f383e67fc0308738f1ce0\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '47b6a26e0f27620c138b0325439f383e67fc0308738f1ce0', 1, '2025-09-29 22:38:13', 'ENCUESTA: 2-CSI Posventa Online', 1, '2025-09-29 21:51:56', '2025-09-29 22:38:13'),
(55, 2, 75, 68, '2025-09-29 23:24:26', 0, 3, '2025-07-20 14:00:00', 'RESPONDIDA', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=65e40bcb2faa3ca6666cbf50a73f9cb590a99cafe1a098fd\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '65e40bcb2faa3ca6666cbf50a73f9cb590a99cafe1a098fd', 1, '2025-09-29 23:24:26', 'ENCUESTA: 2-CSI Posventa Online', 1, '2025-09-29 22:41:57', '2025-09-29 23:24:26'),
(56, 1, 61, 55, '2025-09-30 02:08:43', 0, 3, '2025-09-07 14:00:00', 'RESPONDIDA', 'EMAIL', NULL, NULL, NULL, 'Correo: Cuentenos su experiencia', '[IMAGEN]\r\n[LINEA]\r\n\r\nEstimado/a [PERSONA]:\r\n\r\nBienvenido a esta nueva experiencia IMBAUTO\r\n\r\nEn IMBAUTO nos preocupamos por la satisfacción de nuestros clientes. Nos esforzamos por ofrecerle una experiencia extraordinaria, desde el momento en que compra su vehículo y en cada kilómetro que recorre con él.\r\n\r\nQueremos conocer su opinión sobre su experiencia con su concesionario IMBAUTO y para ello lo invitamos a tomarse unos minutos para completar su evaluación.\r\n\r\nPuede empezar haciendo clic abajo.\r\n\r\n[ENCUESTA]\r\n\r\nLa mayoría de personas tardan unos 2 minutos en completar la encuesta. Sus comentarios nos ayudarán a mejorar continuamente la experiencia de los clientes de IMBAUTO.\r\n\r\nUna vez más, gracias por elegir IMBAUTO.\r\n\r\nAtentamente,\r\nDepartamento de Calidad y Procesos<p><a href=\"https://app.imbauto.com.ec/encuesta?token=149dffcadffb91a2a71580f3ccfd71ad8c6a1c05fe02cf54\">Responder encuesta</a></p>Muchas gracias por su tiempo; con base a la información que nos ha brindado, podremos mejorar nuestro servicio.', '149dffcadffb91a2a71580f3ccfd71ad8c6a1c05fe02cf54', 1, '2025-09-30 02:08:43', 'ENCUESTA: 1-CSI Ventas Livianos Online', 1, '2025-09-29 23:58:33', '2025-09-30 02:08:43');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estadospqrs`
--

CREATE TABLE `estadospqrs` (
  `idEstado` int(11) NOT NULL,
  `nombre` enum('ABIERTO','EN_PROCESO','ESCALADO','CERRADO') NOT NULL,
  `orden` tinyint(4) NOT NULL DEFAULT 1,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estadospqrs`
--

INSERT INTO `estadospqrs` (`idEstado`, `nombre`, `orden`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'ABIERTO', 1, 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(2, 'EN_PROCESO', 2, 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(3, 'ESCALADO', 3, 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(4, 'CERRADO', 4, 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `personas`
--

CREATE TABLE `personas` (
  `idPersona` int(11) NOT NULL,
  `cedula` varchar(150) NOT NULL,
  `nombres` varchar(150) NOT NULL,
  `apellidos` varchar(150) NOT NULL,
  `direccion` varchar(200) DEFAULT NULL,
  `telefono` varchar(150) DEFAULT NULL,
  `extension` varchar(15) DEFAULT NULL,
  `celular` varchar(150) NOT NULL,
  `email` varchar(100) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `personas`
--

INSERT INTO `personas` (`idPersona`, `cedula`, `nombres`, `apellidos`, `direccion`, `telefono`, `extension`, `celular`, `email`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'piZM+zV2s8zHdrVALR9Cng==', 'Adrian Adolfo', 'Merlo Arcos', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+1@gmail.com', 1, '2025-09-10 03:27:21', '2025-09-10 03:29:09'),
(2, 'piZM+zV2s8zHdrVALR9Cng==', 'Xavier', 'Cangas', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+20@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:31:20'),
(3, 'piZM+zV2s8zHdrVALR9Cng==', 'Adolfo', 'Arcos', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+21@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(4, 'piZM+zV2s8zHdrVALR9Cng==', 'Ricardo', 'Arroyo', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+25@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(5, 'piZM+zV2s8zHdrVALR9Cng==', 'Asesor1', 'Asesor1', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+26@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(6, 'piZM+zV2s8zHdrVALR9Cng==', 'Asesor2', 'Asesor2', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+27@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(7, 'piZM+zV2s8zHdrVALR9Cng==', 'Asesor3', 'Asesor3', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+28@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(8, 'piZM+zV2s8zHdrVALR9Cng==', 'Asesor4', 'Asesor4', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+29@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(9, 'piZM+zV2s8zHdrVALR9Cng==', 'Asesor5', 'Asesor5', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+30@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(10, 'piZM+zV2s8zHdrVALR9Cng==', 'Jefe1', 'Jefe1', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+31@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(11, 'piZM+zV2s8zHdrVALR9Cng==', 'Jefe2', 'Jefe2', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+32@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(12, 'piZM+zV2s8zHdrVALR9Cng==', 'Jefe3', 'Jefe3', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+33@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(13, 'piZM+zV2s8zHdrVALR9Cng==', 'Jefe4', 'Jefe4', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+34@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(14, 'piZM+zV2s8zHdrVALR9Cng==', 'Jefe5', 'Jefe5', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+35@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(15, 'piZM+zV2s8zHdrVALR9Cng==', 'cordinador1', 'cordinador1', 'Ibarra', 'kbNaV1TGC4xBsj8qy6fXcQ==', NULL, 'zZf9GZD5XI8b+Kfr+wNecg==', 'adrian.merlo.am3+41@gmail.com', 1, '2025-09-10 03:28:30', '2025-09-10 03:33:00'),
(16, 'eAcmxMj78gmU0qZYFYsFuA==', 'MARIANA DE JESUS', 'GER POTOSI', 'Tulcan', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3101@gmail.com', 1, '2025-09-20 15:57:20', '2025-09-20 15:57:20'),
(17, '+kpuyRWiw6n+OzN+r/Neqw==', 'ANGEL GABRIEL', 'HERNANDEZ RODRIGUEZ', 'Tulcan', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3102@gmail.com', 1, '2025-09-20 16:03:49', '2025-09-20 16:03:49'),
(18, '9S9w5QYM0i1W5m/tqztXbg==', 'MIGUEL ANGEL', 'MENDEZ TUQUERREZ', 'Ibarra', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3103@gmail.com', 1, '2025-09-20 16:05:58', '2025-09-20 16:05:58'),
(19, 'R4GOWQzII05IWUY0MWfNsQ==', 'MARCO AUGUSTO', 'JATIVA POZO', 'Ibarra', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3104@gmail.com', 1, '2025-09-20 16:08:38', '2025-09-20 16:08:38'),
(20, 'ITBRjKzyqAvQP/7lrZfrow==', 'MATIAS ALEJANDRO', 'VILLARREAL ESCOBAR', 'Ibarra', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3105@gmail.com', 1, '2025-09-20 16:10:16', '2025-09-20 16:10:16'),
(22, 'RScdEHsJfZI87dvZdZ9O6g==', 'ANDERSON JOEL', 'CAMPOS ÑACATO', 'Ibarra', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3106@gmail.com', 1, '2025-09-20 16:27:23', '2025-09-20 16:27:23'),
(23, '+kpuyRWiw6n+OzN+r/Neqw==', 'JAIME VIDAL', 'VITE VALENCIA', 'Ibarra', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3107@gmail.com', 1, '2025-09-20 16:29:07', '2025-09-20 16:29:07'),
(24, 'RiKxOBZaKLHhcE2BE77Idw==', 'JHONNY DANIEL', 'BONE ESTRADA', 'Esmeraldas', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3108@gmail.com', 1, '2025-09-20 16:30:03', '2025-09-20 16:30:03'),
(25, 'fPDvu+R7Q6/n+PFwVyTLHg==', 'MICHAEL JORDAN', 'SALTOS MIGUEZ', 'El Coca', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3109@gmail.com', 1, '2025-09-20 16:30:56', '2025-09-20 16:30:56'),
(27, 'quzlSi3aWgo8m7940ORzeQ==', 'EVELYN MADELEINE', 'LARA RON', 'El Coca', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3109@gmail.com', 1, '2025-09-20 16:31:51', '2025-09-20 16:31:51'),
(28, '9JvrOgFWd8B4f9ztfz66NQ==', 'YOEL ALEXANDER', 'JUMBO MORENO', 'Lago Agrio', 'slzwy/szAXeDS+JCmEYOJA==', '', 'sRnRBwdwLEjWf6JPbXeHPA==', 'adrian.merlo.am3110@gmail.com', 1, '2025-09-20 16:32:37', '2025-09-20 16:32:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pqrs`
--

CREATE TABLE `pqrs` (
  `idPqrs` bigint(20) NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `idTipo` int(11) NOT NULL,
  `idCategoria` int(11) DEFAULT NULL,
  `idCanal` int(11) NOT NULL,
  `idEstado` int(11) NOT NULL,
  `idAgencia` int(11) DEFAULT NULL,
  `idCliente` bigint(20) NOT NULL,
  `idEncuesta` bigint(20) NOT NULL,
  `idProgEncuesta` bigint(20) NOT NULL,
  `asunto` varchar(200) NOT NULL,
  `detalle` mediumtext DEFAULT NULL,
  `nivelActual` tinyint(4) NOT NULL DEFAULT 1,
  `fechaLimiteNivel` timestamp NULL DEFAULT NULL,
  `fechaCierre` timestamp NULL DEFAULT NULL,
  `estadoRegistro` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `pqrs`
--

INSERT INTO `pqrs` (`idPqrs`, `codigo`, `idTipo`, `idCategoria`, `idCanal`, `idEstado`, `idAgencia`, `idCliente`, `idEncuesta`, `idProgEncuesta`, `asunto`, `detalle`, `nivelActual`, `fechaLimiteNivel`, `fechaCierre`, `estadoRegistro`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'PQRS-20250916-004', 2, 13, 2, 4, 3, 1, 1, 1, 'QUEJA Entrega con observaciones menores | QUEJA No informaron el estado', 'Cliente: JUANITA MARIA TENORIO DELGADO - ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, QUEJA, Entrega con observaciones menores | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, No informaron el estado | Atención: SFV, 1211538, 001100000015738, CHASIS: LVVDB11B9SE003069, MODELO: TIGGO 2 PRO A1X AC 1.5 5P 4X2 TM, COLOR: BLANCO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 2, '2025-09-16 10:56:53', '2025-09-16 20:56:53', 1, '2025-09-16 22:55:56', '2025-09-21 06:54:04'),
(2, 'PQRS-20250916-005', 2, 71, 2, 4, 3, 2, 1, 2, 'QUEJA Comentario ep2: falta de conocimiento y capacitacion | QUEJA Se reporta falta de honestidad y transparencia (ep2)', 'Cliente: JAQUELINE SONIA ZURITA SACON - ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Algo Satisfecho, QUEJA, Comentario ep2: falta de conocimiento y capacitacion | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Se reporta falta de honestidad y transparencia (ep2) | Atención: SFV, 1212008, 001100000015739, CHASIS: LVVDB11B2SE003043, MODELO: TIGGO 2 PRO A1X AC 1.5 5P 4X2 TM, COLOR: NEGRO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 2, '2025-09-18 03:11:01', '2025-09-17 02:37:11', 1, '2025-09-17 03:11:01', '2025-09-21 06:54:14'),
(3, 'PQRS-20250916-006', 2, 3, 2, 3, 3, 3, 1, 3, 'QUEJA Demora tiempo de entrega. Caso ep3 | QUEJA Demora o falta de instalacion de accesorios (ep3)', 'Cliente: MAURICIO MOISES ESMERALDAS MANZABA - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Algo Satisfecho, QUEJA, Demora tiempo de entrega. Caso ep3 | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, QUEJA, Demora o falta de instalacion de accesorios (ep3) | Atención: SFV, 1213958, 001100000015740, CHASIS: LVAV2MAB4SU313112, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: ROJO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 3, '2025-09-22 06:27:39', NULL, 1, '2025-09-17 04:12:59', '2025-09-21 06:54:52'),
(4, 'PQRS-20250917-001', 2, 5, 2, 4, 3, 4, 1, 4, 'QUEJA Stock de vehiculos limitado. Comentario ep4 | QUEJA Falta gestion segun cliente (ep4) | RECLAMO Fallas de pintura colision y pulida (ep4) | QUEJA Aun no le entregan la matricula o placas (ep4) |', 'Cliente: PETER ANTONIO PORTOCARRERO CAMACHO - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Algo Satisfecho, QUEJA, Stock de vehiculos limitado. Comentario ep4 | ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Algo Satisfecho, QUEJA, Falta gestion segun cliente (ep4) | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, RECLAMO, Fallas de pintura colision y pulida (ep4) | ¿Su vehículo fue entregado en la fecha y horario acordado?: NO, QUEJA, Aun no le entregan la matricula o placas (ep4) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Falta de conocimiento y capacitacion (ep4) | Atención: SFV, 1213959, 001100000015741, CHASIS: LVAV2MAB9SU302543, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: AZUL, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 2, '2025-09-17 05:33:56', '2025-09-17 15:33:56', 1, '2025-09-17 17:33:48', '2025-09-21 06:55:07'),
(5, 'PQRS-20250917-002', 2, 2, 2, 3, 1, 5, 1, 5, 'QUEJA Mal instalado los accesorios (ep5) | QUEJA Demora tiempo de entrega (ep5)', 'Cliente: FRENOSEGURO CIA. LTDA.  - ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, QUEJA, Mal instalado los accesorios (ep5) | ¿Su vehículo fue entregado en la fecha y horario acordado?: NO, QUEJA, Demora tiempo de entrega (ep5) | Atención: SFV, 1214052, 001100000015742, CHASIS: LVBV3JBB6SY005132, MODELO: AUMARK E BJ1069 AC 2.8 2P 4X2 TM DIESEL, COLOR: BLANCO, VENDEDOR: SIGUENZA SANCHEZ DANIEL ALEJANDRO', 3, '2025-09-22 06:27:39', NULL, 1, '2025-09-17 17:33:48', '2025-09-21 06:55:14'),
(6, 'PQRS-20250917-003', 2, 20, 2, 3, 3, 6, 1, 6, 'QUEJA Demora contratos (ep6)', 'Cliente: JOSE LUIS ROJAS GUIPPE - ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Demora contratos (ep6) | Atención: SFV, 1214080, 001100000015743, CHASIS: L6T7722D7RU007332, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: NEGRO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 3, '2025-09-22 06:27:39', NULL, 1, '2025-09-17 17:33:48', '2025-09-21 06:55:26'),
(7, 'PQRS-20250917-004', 3, 11, 2, 4, 3, 7, 1, 7, 'RECLAMO Fallas del vehiculo. Caso ep7 | SUGERENCIA Seguro: falta de informacion (ep7)', 'Cliente: NELSON EDUARDO CHULDE RUANO - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Algo Satisfecho, RECLAMO, Fallas del vehiculo. Caso ep7 | ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Algo Satisfecho, SUGERENCIA, Seguro: falta de informacion (ep7) | Atención: SFV, 1214315, 001100000015744, CHASIS: 9FBHJD204SM132889, MODELO: DUSTER INTENS AC 1.6 5P 4X2 TM, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 2, '2025-09-17 05:34:18', '2025-09-17 16:34:18', 1, '2025-09-17 17:33:48', '2025-09-17 17:34:18'),
(8, 'PQRS-20250917-005', 3, 3, 2, 4, 3, 8, 1, 8, 'QUEJA Demora o falta de activacion del dispositivo satelital (ep8) | QUEJA Demora o falta de instalacion de accesorios (ep8) | QUEJA Falta de conocimiento y capacitacion (ep8)', 'Cliente: MANUEL EDUARDO RODRIGUEZ GAMEZ - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Poco Satisfecho, QUEJA, Demora o falta de activacion del dispositivo satelital (ep8) | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Poco Satisfecho, QUEJA, Demora o falta de instalacion de accesorios (ep8) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Falta de conocimiento y capacitacion (ep8) | Atención: SFV, 1215216, 001100000015745, CHASIS: LVAV2MAB0SU313091, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: ROJO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 2, '2025-09-18 17:33:48', '2025-09-17 16:34:25', 1, '2025-09-17 17:33:48', '2025-09-17 17:34:25'),
(9, 'PQRS-20250917-006', 2, 2, 2, 4, 3, 9, 1, 9, 'QUEJA Demora contratos (ep9) | QUEJA Cliente menciona falta atencion y amabilidad (ep9) | QUEJA Mal instalado los accesorios (ep9) | QUEJA Demora tramite de matriculacion (ep9) | QUEJA Demora o falta ', 'Cliente: ANA AMANDA GONZALEZ OLIVES - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Totalmente Insatisfecho, QUEJA, Demora contratos (ep9) | ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Algo Satisfecho, QUEJA, Cliente menciona falta atencion y amabilidad (ep9) | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, QUEJA, Mal instalado los accesorios (ep9) | ¿Su vehículo fue entregado en la fecha y horario acordado?: NO, QUEJA, Demora tramite de matriculacion (ep9) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Demora o falta de instalacion del dispositivo satelital (ep9) | Atención: SFV, 1215523, 001100000015746, CHASIS: LNBSCCAH6RD909035, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 2, '2025-09-16 17:34:25', '2025-09-17 15:34:25', 1, '2025-09-17 17:33:48', '2025-09-21 06:55:46'),
(10, 'PQRS-20250917-007', 2, 3, 2, 3, 1, 10, 1, 10, 'QUEJA Demora o falta de instalacion de accesorios (ep10)', 'Cliente: SEGUNDO RICARDO REGALADO CUPUERAN - ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Poco Satisfecho, QUEJA, Demora o falta de instalacion de accesorios (ep10) | Atención: SFV, 1215590, 001100000015747, CHASIS: LVAV2MAB1SU313231, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: PLOMO, VENDEDOR: SIGUENZA SANCHEZ DANIEL ALEJANDRO', 3, '2025-09-22 06:27:39', NULL, 1, '2025-09-17 17:33:48', '2025-09-21 06:55:58'),
(11, 'PQRS-20250917-008', 4, 8, 2, 4, 1, 11, 1, 11, 'SUGERENCIA Falta explicacion del funcionamiento de dispositivos (ep11) | QUEJA No estaba el vehiculo listo en la entrega (ep11) | QUEJA Falta de honestidad y transparencia (ep11)', 'Cliente: VALLEJO ARAUJO S.A.  - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Algo Satisfecho, SUGERENCIA, Falta explicacion del funcionamiento de dispositivos (ep11) | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, QUEJA, No estaba el vehiculo listo en la entrega (ep11) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Falta de honestidad y transparencia (ep11) | Atención: SFV, 1215657, 001100000015748, CHASIS: L6T7722D2RU007335, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: NEGRO, VENDEDOR: IMBAUTO S.A', 2, '2025-09-18 17:33:48', '2025-09-17 16:34:25', 1, '2025-09-17 17:33:48', '2025-09-21 06:56:11'),
(12, 'PQRS-20250917-009', 2, 9, 2, 3, 3, 12, 1, 12, 'QUEJA Otras novedades con el vehiculo (ep12) | QUEJA Falta de conocimiento y capacitacion (ep12) | QUEJA Aun no le entregan el VH (ep12)', 'Cliente: PATRICIA A LEXANDRA CASQUETE BATIOJA - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Poco Satisfecho, QUEJA, Otras novedades con el vehiculo (ep12) | ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Algo Satisfecho, QUEJA, Falta de conocimiento y capacitacion (ep12) | ¿Su vehículo fue entregado en la fecha y horario acordado?: NO, QUEJA, Aun no le entregan el VH (ep12) | Atención: SFV, 1215752, 001100000015749, CHASIS: LVAV2MAB5SU311630, MODELO: TUNLAND G AC 2.0 CD 4X2 TM DIESEL, COLOR: PLOMO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 3, '2025-09-22 06:27:39', NULL, 1, '2025-09-17 17:33:48', '2025-09-21 06:56:26'),
(13, 'PQRS-20250917-010', 2, 3, 2, 4, 1, 13, 1, 13, 'QUEJA Equipo y herramientas deficientes (ep13) | QUEJA Falta gestion (ep13) | QUEJA Demora o falta de instalacion de accesorios (ep13) | QUEJA Demora tramite de matriculacion (ep13) | QUEJA Falta de h', 'Cliente: BERONICA ERNESTINA SULCA CHILLAN - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Totalmente Insatisfecho, QUEJA, Equipo y herramientas deficientes (ep13) | ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Poco Satisfecho, QUEJA, Falta gestion (ep13) | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, QUEJA, Demora o falta de instalacion de accesorios (ep13) | ¿Su vehículo fue entregado en la fecha y horario acordado?: NO, QUEJA, Demora tramite de matriculacion (ep13) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Falta de honestidad y transparencia (ep13) | Atención: SFV, 1215885, 001100000015751, CHASIS: MP2TFS40JPT005725, MODELO: NEW BT-50 AC 3.0 CD 4X4 TA DIESEL, COLOR: NEGRO, VENDEDOR: SIGUENZA SANCHEZ DANIEL ALEJANDRO', 2, '2025-09-17 17:34:25', '2025-09-17 15:34:25', 1, '2025-09-17 17:33:48', '2025-09-21 06:56:41'),
(14, 'PQRS-20250917-011', 2, 5, 2, 3, 3, 14, 1, 14, 'QUEJA Aun no le entregan la matricula o placas (ep14)', 'Cliente: MIXI MILENA PARRALES PALACIOS - ¿Su vehículo fue entregado en la fecha y horario acordado?: NO, QUEJA, Aun no le entregan la matricula o placas (ep14) | Atención: SFV, 1215924, 001100000015752, CHASIS: LVAV2MAB7SU311676, MODELO: TUNLAND G AC 2.0 CD 4X2 TM DIESEL, COLOR: NEGRO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 3, '2025-09-22 06:27:39', NULL, 1, '2025-09-17 17:33:48', '2025-09-21 06:56:47'),
(15, 'PQRS-20250917-012', 2, 12, 2, 3, 1, 15, 1, 15, 'QUEJA Cliente satisfecho, pero califica con baja nota (ep15) | QUEJA Falta gestion (ep15) | QUEJA No estaba el vehiculo listo en la entrega (ep15)', 'Cliente: JAIME TARQUINO RUIZ ANDRADE - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Algo Satisfecho, QUEJA, Cliente satisfecho, pero califica con baja nota (ep15) | ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Poco Satisfecho, QUEJA, Falta gestion (ep15) | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Poco Satisfecho, QUEJA, No estaba el vehiculo listo en la entrega (ep15) | Atención: SFV, 1216157, 001100000015753, CHASIS: LVAV2MAB0SU313074, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: NEGRO, VENDEDOR: SIGUENZA SANCHEZ DANIEL ALEJANDRO', 3, '2025-09-22 06:27:39', NULL, 1, '2025-09-17 17:33:48', '2025-09-21 06:56:54'),
(16, 'PQRS-20250917-013', 4, 13, 2, 4, 3, 16, 1, 16, 'SUGERENCIA Falta explicacion de las caracteristicas del vehiculo (ep16) | QUEJA Aun no le entregan el VH (ep16) | QUEJA Robos o perdidas (ep16)', 'Cliente: ANA MARIA FERNANDA CEPEDA RODRIGUEZ - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Poco Satisfecho, SUGERENCIA, Falta explicacion de las caracteristicas del vehiculo (ep16) | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, QUEJA, Aun no le entregan el VH (ep16) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Robos o perdidas (ep16) | Atención: SFV, 1216407, 001100000015754, CHASIS: LNBSCCAH8RD979233, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: PLOMO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 2, '2025-09-18 01:34:25', '2025-09-17 16:34:25', 1, '2025-09-17 17:33:48', '2025-09-21 06:57:02'),
(17, 'PQRS-20250917-014', 2, 26, 2, 4, 3, 17, 1, 17, 'QUEJA Falta atencion y amabilidad leve (ep17) | SUGERENCIA Falta de informacion del seguro (ep17)', 'Cliente: RAMIRO MADONIO MERCADO GARCES - ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Algo Satisfecho, QUEJA, Falta atencion y amabilidad leve (ep17) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, SUGERENCIA, Falta de informacion del seguro (ep17) | Atención: SFV, 1216677, 001100000015755, CHASIS: LNBSCUAK4RR194082, MODELO: X35 ELITE AC 1.5 5P 4X2 TA, COLOR: ROJO, VENDEDOR: ZAPATA CASTILLO GABRIELA NATALI', 2, '2025-09-18 17:33:48', '2025-09-17 16:34:25', 1, '2025-09-17 17:33:48', '2025-09-21 06:57:09'),
(18, 'PQRS-20250917-015', 3, 10, 2, 3, 3, 18, 1, 18, 'RECLAMO Fallas del vehiculo. Caso ep18 | RECLAMO Fallas de pintura colision y pulida (ep18) | QUEJA Demora tiempo de entrega (ep18) | QUEJA Falta de honestidad y transparencia (ep18)', 'Cliente: DIONIGI GIANCASPRO - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Algo Satisfecho, RECLAMO, Fallas del vehiculo. Caso ep18 | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, RECLAMO, Fallas de pintura colision y pulida (ep18) | ¿Su vehículo fue entregado en la fecha y horario acordado?: NO, QUEJA, Demora tiempo de entrega (ep18) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, QUEJA, Falta de honestidad y transparencia (ep18) | Atención: SFV, 1216684, 001100000015757, CHASIS: L6T7722D7RU007380, MODELO: AZKARRA I AC 1.5 5P 4X4 TA HYBRID, COLOR: NEGRO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 3, '2025-09-22 06:27:39', NULL, 1, '2025-09-17 17:33:48', '2025-09-21 06:27:39'),
(19, 'PQRS-20250917-016', 4, 8, 2, 4, 1, 19, 1, 19, 'SUGERENCIA Falta explicacion del funcionamiento de dispositivos (ep19) | QUEJA Falta gestion (ep19) | SUGERENCIA Falta de informacion del seguro (ep19)', 'Cliente: FREDY JAVIER PAZMIÑO PALMA - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Poco Satisfecho, SUGERENCIA, Falta explicacion del funcionamiento de dispositivos (ep19) | ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Poco Satisfecho, QUEJA, Falta gestion (ep19) | ¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?: NO, SUGERENCIA, Falta de informacion del seguro (ep19) | Atención: SFV, 1217030, 001100000015758, CHASIS: HJRPBGJB7RF010998, MODELO: X90 PLUS AC 2.0 5P 4X2 TA, COLOR: PLOMO, VENDEDOR: QUINTANA CIFUENTES VERONICA BELEN', 2, '2025-09-17 09:34:25', '2025-09-17 15:34:25', 1, '2025-09-17 17:33:48', '2025-09-21 06:57:19'),
(20, 'PQRS-20250917-017', 2, 10, 2, 4, 3, 20, 1, 20, 'QUEJA No disponen el color o modelo que solicito (ep20) | RECLAMO Fallas de pintura colision y pulida (ep20) | QUEJA Demora tiempo de entrega (ep20)', 'Cliente: MARCOS ANTONIO CHICA AVILA - Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.: Totalmente Insatisfecho, QUEJA, No disponen el color o modelo que solicito (ep20) | ¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?: Algo Satisfecho, RECLAMO, Fallas de pintura colision y pulida (ep20) | ¿Su vehículo fue entregado en la fecha y horario acordado?: NO, QUEJA, Demora tiempo de entrega (ep20) | Atención: SFV, 1217039, 001100000015760, CHASIS: LNBSCCAH7RD909318, MODELO: U5 PLUS AC 1.5 4P 4X2 TM, COLOR: NEGRO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 2, '2025-09-18 17:33:48', '2025-09-17 16:34:25', 1, '2025-09-17 17:33:48', '2025-09-21 06:57:30'),
(21, 'PQRS-20250917-019', 2, 72, 2, 4, 1, 21, 1, 21, 'QUEJA Falta gestion moderada (ep21)', 'Cliente: MIRIAN MARGARITA.  - ¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?: Algo Satisfecho, QUEJA, Falta gestion moderada (ep21) | Atención: SFV, 1217715, 001100000015762, CHASIS: LNBSCCAH1RD909038, MODELO: U5 PLUS AC 1.5 4P 4X2 TM: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 2, '2025-09-17 05:47:07', '2025-09-17 15:47:07', 1, '2025-09-17 17:46:20', '2025-09-21 06:57:39'),
(22, 'PQRS-20250929-001', 4, 87, 1, 2, 3, 68, 2, 55, 'SUGERENCIA nose informo del descuento', 'Cliente: JOSE DAVID KLINGER MARTINEZ - ¿Cuál es su nivel de satisfacción con el asesor/técnico de servicio?: Algo Satisfecho, SUGERENCIA, nose informo del descuento | Atención: SFT, 1221440, 006400000035467, VENTAS DE TALLER|TALLER MECANICA|SAIL LS AC 1.5 4P 4X2 TM|PDY7548|KM.:76600|VITE VALENCIA JAIME VIDAL|FEC_OT:17/07/2025|OT.669590|MANTENIMIENTO CORRECTIVO', 1, '2025-09-30 22:43:31', NULL, 1, '2025-09-29 22:43:31', '2025-09-29 22:50:13'),
(23, 'PQRS-20250929-002', 2, 108, 2, 4, 3, 55, 1, 56, 'QUEJA mensaje de prueba', 'Cliente: WALTER WILFRIDO CEDEÑO RIVADENEIRA - En una escala de 0 a 10, donde 0 significa nada probable y 10 significa muy probable, ¿Qué tan probable es que usted recomiende al concesionario IMBAUTO a sus familiares o amigos?: 3, QUEJA, mensaje de prueba | Atención: SFV, 1223644, 001100000015816, CHASIS: LVAV2MAB2TU450776, MODELO: TUNLAND G AC 2.0 CD 4X4 TM DIESEL, COLOR: BLANCO, VENDEDOR: CENTENO CHAVARRIA DOLORES OLIMPIA', 1, '2025-10-01 00:03:11', '2025-09-30 02:13:01', 1, '2025-09-30 00:03:11', '2025-09-30 02:13:01');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pqrs_preguntas`
--

CREATE TABLE `pqrs_preguntas` (
  `id` bigint(20) NOT NULL,
  `idPqrs` bigint(20) NOT NULL,
  `idPregunta` int(11) NOT NULL,
  `idCategoria` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `pqrs_preguntas`
--

INSERT INTO `pqrs_preguntas` (`id`, `idPqrs`, `idPregunta`, `idCategoria`) VALUES
(1, 1, 4, 13),
(2, 1, 6, 17),
(4, 2, 3, 75),
(5, 2, 6, 71),
(7, 3, 1, 15),
(8, 3, 4, 3),
(10, 4, 1, 24),
(11, 4, 3, 72),
(12, 4, 4, 10),
(13, 4, 5, 5),
(14, 4, 6, 75),
(17, 5, 4, 2),
(18, 5, 5, 15),
(20, 6, 6, 20),
(21, 7, 1, 11),
(22, 7, 3, 26),
(24, 8, 1, 17),
(25, 8, 4, 3),
(26, 8, 6, 75),
(27, 9, 1, 20),
(28, 9, 3, 77),
(29, 9, 4, 2),
(30, 9, 5, 6),
(31, 9, 6, 18),
(34, 10, 4, 3),
(35, 11, 1, 8),
(36, 11, 4, 12),
(37, 11, 6, 71),
(38, 12, 1, 9),
(39, 12, 3, 75),
(40, 12, 5, 13),
(41, 13, 1, 78),
(42, 13, 3, 72),
(43, 13, 4, 3),
(44, 13, 5, 6),
(45, 13, 6, 71),
(48, 14, 5, 5),
(49, 15, 1, 28),
(50, 15, 3, 72),
(51, 15, 4, 12),
(52, 16, 1, 14),
(53, 16, 4, 13),
(54, 16, 6, 76),
(55, 17, 3, 77),
(56, 17, 6, 26),
(58, 18, 1, 11),
(59, 18, 4, 10),
(60, 18, 5, 15),
(61, 18, 6, 71),
(65, 19, 1, 8),
(66, 19, 3, 72),
(67, 19, 6, 26),
(68, 20, 1, 23),
(69, 20, 4, 10),
(70, 20, 5, 15),
(71, 21, 3, 72),
(72, 22, 10, 87),
(73, 23, 2, 108);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pqrs_responsables`
--

CREATE TABLE `pqrs_responsables` (
  `id` bigint(20) NOT NULL,
  `idPqrs` bigint(20) NOT NULL,
  `nivel` tinyint(4) NOT NULL,
  `idResponsable` int(11) NOT NULL,
  `horasSLA` int(11) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `pqrs_responsables`
--

INSERT INTO `pqrs_responsables` (`id`, `idPqrs`, `nivel`, `idResponsable`, `horasSLA`, `activo`) VALUES
(1, 1, 1, 7, 24, 1),
(2, 1, 2, 12, 24, 0),
(3, 1, 3, 15, 24, 0),
(4, 2, 1, 7, 24, 1),
(5, 2, 2, 12, 24, 0),
(6, 2, 3, 15, 24, 0),
(7, 3, 1, 7, 24, 1),
(8, 3, 2, 12, 24, 0),
(9, 3, 3, 15, 24, 0),
(10, 4, 1, 7, 24, 1),
(11, 4, 2, 12, 24, 0),
(12, 4, 3, 15, 24, 0),
(13, 5, 1, 5, 24, 1),
(14, 5, 2, 10, 24, 0),
(15, 5, 3, 15, 24, 0),
(16, 6, 1, 7, 24, 1),
(17, 6, 2, 12, 24, 0),
(18, 6, 3, 15, 24, 0),
(19, 7, 1, 7, 24, 1),
(20, 7, 2, 12, 24, 0),
(21, 7, 3, 15, 24, 0),
(22, 8, 1, 7, 24, 1),
(23, 8, 2, 12, 24, 0),
(24, 8, 3, 15, 24, 0),
(25, 9, 1, 7, 24, 1),
(26, 9, 2, 12, 24, 0),
(27, 9, 3, 15, 24, 0),
(28, 10, 1, 5, 24, 1),
(29, 10, 2, 10, 24, 0),
(30, 10, 3, 15, 24, 0),
(31, 11, 1, 5, 24, 1),
(32, 11, 2, 10, 24, 0),
(33, 11, 3, 15, 24, 0),
(34, 12, 1, 7, 24, 1),
(35, 12, 2, 12, 24, 0),
(36, 12, 3, 15, 24, 0),
(37, 13, 1, 5, 24, 1),
(38, 13, 2, 10, 24, 0),
(39, 13, 3, 15, 24, 0),
(40, 14, 1, 7, 24, 1),
(41, 14, 2, 12, 24, 0),
(42, 14, 3, 15, 24, 0),
(43, 15, 1, 5, 24, 1),
(44, 15, 2, 10, 24, 0),
(45, 15, 3, 15, 24, 0),
(46, 16, 1, 7, 24, 1),
(47, 16, 2, 12, 24, 0),
(48, 16, 3, 15, 24, 0),
(49, 17, 1, 7, 24, 1),
(50, 17, 2, 12, 24, 0),
(51, 17, 3, 15, 24, 0),
(52, 18, 1, 7, 24, 1),
(53, 18, 2, 12, 24, 0),
(54, 18, 3, 15, 24, 0),
(55, 19, 1, 5, 24, 1),
(56, 19, 2, 10, 24, 0),
(57, 19, 3, 15, 24, 0),
(58, 20, 1, 7, 24, 1),
(59, 20, 2, 12, 24, 0),
(60, 20, 3, 15, 24, 0),
(61, 21, 1, 5, 24, 1),
(62, 21, 2, 10, 24, 0),
(63, 21, 3, 15, 24, 0),
(64, 22, 1, 5, 24, 1),
(65, 22, 2, 10, 24, 0),
(66, 22, 3, 15, 24, 0),
(67, 23, 1, 7, 24, 0),
(68, 23, 2, 12, 24, 1),
(69, 23, 3, 15, 24, 0);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `preguntas`
--

CREATE TABLE `preguntas` (
  `idPregunta` int(11) NOT NULL,
  `idEncuesta` bigint(20) NOT NULL,
  `orden` int(11) NOT NULL,
  `texto` varchar(255) NOT NULL,
  `tipo` enum('ESCALA_1_10','SI_NO','SELECCION','ABIERTA') NOT NULL DEFAULT 'ESCALA_1_10',
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `permiteComentario` tinyint(1) NOT NULL DEFAULT 0,
  `generaPqr` tinyint(1) NOT NULL DEFAULT 0,
  `umbralMinimo` int(11) DEFAULT 7,
  `scriptFinal` mediumtext DEFAULT NULL,
  `esNps` tinyint(1) NOT NULL DEFAULT 0,
  `esCes` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = esta pregunta es CES (facilidad/esfuerzo); 0 = no CES',
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `preguntas`
--

INSERT INTO `preguntas` (`idPregunta`, `idEncuesta`, `orden`, `texto`, `tipo`, `activa`, `permiteComentario`, `generaPqr`, `umbralMinimo`, `scriptFinal`, `esNps`, `esCes`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 1, 1, 'Basándose en su experiencia general de compra y entrega de vehículo ¿Cuál es su grado de satisfacción con el concesionario IMBAUTO?.', 'SELECCION', 1, 1, 1, 3, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-17 03:31:11'),
(2, 1, 2, 'En una escala de 0 a 10, donde 0 significa nada probable y 10 significa muy probable, ¿Qué tan probable es que usted recomiende al concesionario IMBAUTO a sus familiares o amigos?', 'ESCALA_1_10', 1, 1, 1, 7, NULL, 1, 0, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(3, 1, 3, '¿Cuál es su nivel de satisfacción con el conocimiento y amabilidad que recibió por parte del asesor de ventas?', 'SELECCION', 1, 1, 1, 3, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-17 03:31:11'),
(4, 1, 4, '¿Al momento de recibir su vehículo, que tan satisfecho se encuentra con las condiciones de entrega de su vehículo?', 'SELECCION', 1, 1, 1, 3, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-17 03:31:11'),
(5, 1, 5, '¿Su vehículo fue entregado en la fecha y horario acordado?', 'SI_NO', 1, 1, 1, 0, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-17 03:31:11'),
(6, 1, 6, '¿El asesor de ventas lo mantuvo informado sobre el estado de la entrega de su vehículo?', 'SI_NO', 1, 1, 1, 0, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-21 03:30:24'),
(7, 2, 1, 'Basándose en su experiencia de mantenimiento o reparación de su vehículo, ¿Cuál es su nivel de satisfacción con el concesionario IMBAUTO S.A?.', 'SELECCION', 1, 1, 1, 3, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(8, 2, 2, 'En una escala del 0 al 10, donde 0 significa nada probable y 10 significa muy probable, ¿Qué tan probable es que usted recomiende al concesionario IMBAUTO a sus familiares o amigos?', 'ESCALA_1_10', 1, 1, 1, 7, NULL, 1, 0, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(9, 2, 3, '¿Cuál es su nivel de satisfacción con el trabajo realizado en su vehículo?', 'SELECCION', 1, 1, 1, 3, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(10, 2, 4, '¿Cuál es su nivel de satisfacción con el asesor/técnico de servicio?', 'SELECCION', 1, 1, 1, 3, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(11, 2, 5, '¿Cuál es su nivel de satisfacción con el proceso de entrega de su vehículo?', 'SELECCION', 1, 1, 1, 3, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(12, 2, 6, '¿Su vehículo estuvo listo o se lo entregaron a la hora prometida?', 'SI_NO', 1, 1, 1, 0, NULL, 0, 0, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(13, 1, 7, '¿Qué tan fácil fue completar el proceso de compra y entrega de su vehículo?', 'SELECCION', 1, 0, 0, 3, NULL, 0, 1, '2025-09-06 03:06:03', '2025-09-17 03:31:11'),
(14, 2, 7, '¿Qué tan fácil fue completar el proceso de mantenimiento o reparación de su vehículo en IMBAUTO?', 'SELECCION', 1, 0, 0, 3, NULL, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `respuestascliente`
--

CREATE TABLE `respuestascliente` (
  `idRespCliente` bigint(20) NOT NULL,
  `idProgEncuesta` bigint(20) NOT NULL,
  `idPregunta` int(11) NOT NULL,
  `idOpcion` bigint(20) DEFAULT NULL,
  `valorNumerico` int(11) DEFAULT NULL,
  `valorTexto` varchar(255) DEFAULT NULL,
  `comentario` longtext DEFAULT NULL,
  `generaPqr` tinyint(1) DEFAULT NULL,
  `idcategoria` int(11) DEFAULT NULL,
  `fechaRespuesta` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `respuestascliente`
--

INSERT INTO `respuestascliente` (`idRespCliente`, `idProgEncuesta`, `idPregunta`, `idOpcion`, `valorNumerico`, `valorTexto`, `comentario`, `generaPqr`, `idcategoria`, `fechaRespuesta`, `estado`) VALUES
(1, 1, 1, 2, 4, 'Muy Satisfecho', 'Cliente satisfecho', 0, NULL, '2025-09-15 22:11:01', 1),
(2, 1, 2, 14, 9, '9', 'Cliente recomendaría 9/10', 0, NULL, '2025-09-15 22:11:01', 1),
(3, 1, 3, 17, 4, 'Muy Satisfecho', 'Atención cordial', 0, NULL, '2025-09-15 22:11:01', 1),
(4, 1, 4, 23, 3, 'Algo Satisfecho', 'Entrega con observaciones menores', 1, 13, '2025-09-15 22:11:01', 1),
(5, 1, 5, 26, 1, 'SI', 'Entregado en fecha y hora', 0, NULL, '2025-09-15 22:11:01', 1),
(6, 1, 6, 29, 0, 'NO', 'No informaron el estado', 1, 17, '2025-09-15 22:11:01', 1),
(7, 2, 1, 2, 4, 'Muy Satisfecho', 'Observación: fallas del vehiculo (ep2)', 0, NULL, '2025-09-17 03:08:46', 1),
(8, 2, 2, 14, 9, '9', 'NPS 9/10 ep2', 0, NULL, '2025-09-17 03:08:46', 1),
(9, 2, 3, 18, 3, 'Algo Satisfecho', 'Comentario ep2: falta de conocimiento y capacitacion', 1, 75, '2025-09-17 03:08:46', 1),
(10, 2, 4, 22, 4, 'Muy Satisfecho', 'Se reporta no estaba el vehiculo listo en la entrega (ep2)', 0, NULL, '2025-09-17 03:08:46', 1),
(11, 2, 5, 26, 1, 'SI', 'Matriculacion. Caso ep2', 0, NULL, '2025-09-17 03:08:46', 1),
(12, 2, 6, 29, 0, 'NO', 'Se reporta falta de honestidad y transparencia (ep2)', 1, 71, '2025-09-17 03:08:46', 1),
(14, 3, 1, 3, 3, 'Algo Satisfecho', 'Demora tiempo de entrega. Caso ep3', 1, 15, '2025-09-17 04:11:21', 1),
(15, 3, 2, 14, 9, '9', 'NPS 9/10 ep3', 0, NULL, '2025-09-17 04:11:21', 1),
(16, 3, 3, 17, 4, 'Muy Satisfecho', 'Falta de atencion y amabilidad segun cliente (ep3)', 0, NULL, '2025-09-17 04:11:21', 1),
(17, 3, 4, 23, 3, 'Algo Satisfecho', 'Demora o falta de instalacion de accesorios (ep3)', 1, 3, '2025-09-17 04:11:21', 1),
(18, 3, 5, 26, 1, 'SI', 'Demora tramite de matriculacion (ep3)', 0, NULL, '2025-09-17 04:11:21', 1),
(19, 3, 6, 28, 1, 'SI', 'Falta de informacion del seguro (ep3)', 0, NULL, '2025-09-17 04:11:21', 1),
(21, 4, 1, 3, 3, 'Algo Satisfecho', 'Stock de vehiculos limitado. Comentario ep4', 1, 24, '2025-09-17 15:40:45', 1),
(22, 4, 2, 13, 8, '8', 'NPS 8/10 ep4', 0, NULL, '2025-09-17 15:40:45', 1),
(23, 4, 3, 18, 3, 'Algo Satisfecho', 'Falta gestion segun cliente (ep4)', 1, 72, '2025-09-17 15:40:45', 1),
(24, 4, 4, 23, 3, 'Algo Satisfecho', 'Fallas de pintura colision y pulida (ep4)', 1, 10, '2025-09-17 15:40:45', 1),
(25, 4, 5, 27, 0, 'NO', 'Aun no le entregan la matricula o placas (ep4)', 1, 5, '2025-09-17 15:40:45', 1),
(26, 4, 6, 29, 0, 'NO', 'Falta de conocimiento y capacitacion (ep4)', 1, 75, '2025-09-17 15:40:45', 1),
(28, 5, 1, 2, 4, 'Muy Satisfecho', 'No disponen el color o modelo que solicito (ep5)', 0, NULL, '2025-09-17 17:23:42', 1),
(29, 5, 2, 14, 9, '9', 'NPS 9/10 ep5', 0, NULL, '2025-09-17 17:23:42', 1),
(30, 5, 3, 17, 4, 'Muy Satisfecho', 'Seguro: falta de informacion (ep5)', 0, NULL, '2025-09-17 17:23:42', 1),
(31, 5, 4, 23, 3, 'Algo Satisfecho', 'Mal instalado los accesorios (ep5)', 1, 2, '2025-09-17 17:23:42', 1),
(32, 5, 5, 27, 0, 'NO', 'Demora tiempo de entrega (ep5)', 1, 15, '2025-09-17 17:23:42', 1),
(33, 5, 6, 28, 1, 'SI', 'Dispositivo satelital: demora o falta de activacion (ep5)', 0, NULL, '2025-09-17 17:23:42', 1),
(35, 6, 1, 1, 5, 'Totalmente Satisfecho', 'Cliente satisfecho, accesorios y aplicaciones OK (ep6)', 0, NULL, '2025-09-17 17:23:42', 1),
(36, 6, 2, 15, 10, '10', 'NPS 10/10 ep6', 0, NULL, '2025-09-17 17:23:42', 1),
(37, 6, 3, 17, 4, 'Muy Satisfecho', 'Falta atencion y amabilidad: no aplica en este caso (ep6)', 0, NULL, '2025-09-17 17:23:42', 1),
(38, 6, 4, 22, 4, 'Muy Satisfecho', 'Dispositivo satelital - general (ep6)', 0, NULL, '2025-09-17 17:23:42', 1),
(39, 6, 5, 26, 1, 'SI', 'Aun no le entregan el VH: no corresponde (ep6)', 0, NULL, '2025-09-17 17:23:42', 1),
(40, 6, 6, 29, 0, 'NO', 'Demora contratos (ep6)', 1, 20, '2025-09-17 17:23:42', 1),
(42, 7, 1, 3, 3, 'Algo Satisfecho', 'Fallas del vehiculo. Caso ep7', 1, 11, '2025-09-17 17:23:42', 1),
(43, 7, 2, 14, 9, '9', 'NPS 9/10 ep7', 0, NULL, '2025-09-17 17:23:42', 1),
(44, 7, 3, 18, 3, 'Algo Satisfecho', 'Seguro: falta de informacion (ep7)', 1, 26, '2025-09-17 17:23:42', 1),
(45, 7, 4, 22, 4, 'Muy Satisfecho', 'Fallas de pintura colision y pulida (ep7)', 0, NULL, '2025-09-17 17:23:42', 1),
(46, 7, 5, 26, 1, 'SI', 'Demora tiempo de entrega (ep7)', 0, NULL, '2025-09-17 17:23:42', 1),
(47, 7, 6, 28, 1, 'SI', 'Falta atencion y amabilidad (ep7)', 0, NULL, '2025-09-17 17:23:42', 1),
(49, 8, 1, 4, 2, 'Poco Satisfecho', 'Demora o falta de activacion del dispositivo satelital (ep8)', 1, 17, '2025-09-17 17:23:42', 1),
(50, 8, 2, 14, 9, '9', 'NPS 9/10 ep8', 0, NULL, '2025-09-17 17:23:42', 1),
(51, 8, 3, 17, 4, 'Muy Satisfecho', 'Falta gestion no observada (ep8)', 0, NULL, '2025-09-17 17:23:42', 1),
(52, 8, 4, 24, 2, 'Poco Satisfecho', 'Demora o falta de instalacion de accesorios (ep8)', 1, 3, '2025-09-17 17:23:42', 1),
(53, 8, 5, 26, 1, 'SI', 'Aun no le entregan la matricula o placas (ep8)', 0, NULL, '2025-09-17 17:23:42', 1),
(54, 8, 6, 29, 0, 'NO', 'Falta de conocimiento y capacitacion (ep8)', 1, 75, '2025-09-17 17:23:42', 1),
(56, 9, 1, 5, 1, 'Totalmente Insatisfecho', 'Demora contratos (ep9)', 1, 20, '2025-09-17 17:23:42', 1),
(57, 9, 2, 14, 9, '9', 'NPS 9/10 ep9', 0, NULL, '2025-09-17 17:23:42', 1),
(58, 9, 3, 18, 3, 'Algo Satisfecho', 'Cliente menciona falta atencion y amabilidad (ep9)', 1, 77, '2025-09-17 17:23:42', 1),
(59, 9, 4, 23, 3, 'Algo Satisfecho', 'Mal instalado los accesorios (ep9)', 1, 2, '2025-09-17 17:23:42', 1),
(60, 9, 5, 27, 0, 'NO', 'Demora tramite de matriculacion (ep9)', 1, 6, '2025-09-17 17:23:42', 1),
(61, 9, 6, 29, 0, 'NO', 'Demora o falta de instalacion del dispositivo satelital (ep9)', 1, 18, '2025-09-17 17:23:42', 1),
(63, 10, 1, 2, 4, 'Muy Satisfecho', 'Accesorios y aplicaciones. Caso ep10', 0, NULL, '2025-09-17 17:23:42', 1),
(64, 10, 2, 13, 8, '8', 'NPS 8/10 ep10', 0, NULL, '2025-09-17 17:23:42', 1),
(65, 10, 3, 17, 4, 'Muy Satisfecho', 'Falta gestion no evidenciada (ep10)', 0, NULL, '2025-09-17 17:23:42', 1),
(66, 10, 4, 24, 2, 'Poco Satisfecho', 'Demora o falta de instalacion de accesorios (ep10)', 1, 3, '2025-09-17 17:23:42', 1),
(67, 10, 5, 26, 1, 'SI', 'Matriculacion (ep10)', 0, NULL, '2025-09-17 17:23:42', 1),
(68, 10, 6, 28, 1, 'SI', 'Falta de informacion del seguro (ep10)', 0, NULL, '2025-09-17 17:23:42', 1),
(70, 11, 1, 3, 3, 'Algo Satisfecho', 'Falta explicacion del funcionamiento de dispositivos (ep11)', 1, 8, '2025-09-17 17:23:42', 1),
(71, 11, 2, 15, 10, '10', 'NPS 10/10 ep11', 0, NULL, '2025-09-17 17:23:42', 1),
(72, 11, 3, 17, 4, 'Muy Satisfecho', 'Cliente no percibe falta de atencion y amabilidad (ep11)', 0, NULL, '2025-09-17 17:23:42', 1),
(73, 11, 4, 23, 3, 'Algo Satisfecho', 'No estaba el vehiculo listo en la entrega (ep11)', 1, 12, '2025-09-17 17:23:42', 1),
(74, 11, 5, 26, 1, 'SI', 'Demora tiempo de entrega (ep11)', 0, NULL, '2025-09-17 17:23:42', 1),
(75, 11, 6, 29, 0, 'NO', 'Falta de honestidad y transparencia (ep11)', 1, 71, '2025-09-17 17:23:42', 1),
(77, 12, 1, 4, 2, 'Poco Satisfecho', 'Otras novedades con el vehiculo (ep12)', 1, 9, '2025-09-17 17:23:42', 1),
(78, 12, 2, 13, 8, '8', 'NPS 8/10 ep12', 0, NULL, '2025-09-17 17:23:42', 1),
(79, 12, 3, 18, 3, 'Algo Satisfecho', 'Falta de conocimiento y capacitacion (ep12)', 1, 75, '2025-09-17 17:23:42', 1),
(80, 12, 4, 22, 4, 'Muy Satisfecho', 'Dispositivo satelital: general (ep12)', 0, NULL, '2025-09-17 17:23:42', 1),
(81, 12, 5, 27, 0, 'NO', 'Aun no le entregan el VH (ep12)', 1, 13, '2025-09-17 17:23:42', 1),
(82, 12, 6, 28, 1, 'SI', 'Demora de contratos (ep12)', 0, NULL, '2025-09-17 17:23:42', 1),
(84, 13, 1, 5, 1, 'Totalmente Insatisfecho', 'Equipo y herramientas deficientes (ep13)', 1, 78, '2025-09-17 17:23:42', 1),
(85, 13, 2, 15, 10, '10', 'NPS 10/10 ep13', 0, NULL, '2025-09-17 17:23:42', 1),
(86, 13, 3, 19, 2, 'Poco Satisfecho', 'Falta gestion (ep13)', 1, 72, '2025-09-17 17:23:42', 1),
(87, 13, 4, 23, 3, 'Algo Satisfecho', 'Demora o falta de instalacion de accesorios (ep13)', 1, 3, '2025-09-17 17:23:42', 1),
(88, 13, 5, 27, 0, 'NO', 'Demora tramite de matriculacion (ep13)', 1, 6, '2025-09-17 17:23:42', 1),
(89, 13, 6, 29, 0, 'NO', 'Falta de honestidad y transparencia (ep13)', 1, 71, '2025-09-17 17:23:42', 1),
(91, 14, 1, 2, 4, 'Muy Satisfecho', 'Instalaciones e infraestructura adecuadas (ep14)', 0, NULL, '2025-09-17 17:23:42', 1),
(92, 14, 2, 14, 9, '9', 'NPS 9/10 ep14', 0, NULL, '2025-09-17 17:23:42', 1),
(93, 14, 3, 17, 4, 'Muy Satisfecho', 'Falta atencion y amabilidad: sin incidencia ep14', 0, NULL, '2025-09-17 17:23:42', 1),
(94, 14, 4, 22, 4, 'Muy Satisfecho', 'Accesorios y aplicaciones correctos (ep14)', 0, NULL, '2025-09-17 17:23:42', 1),
(95, 14, 5, 27, 0, 'NO', 'Aun no le entregan la matricula o placas (ep14)', 1, 5, '2025-09-17 17:23:42', 1),
(96, 14, 6, 28, 1, 'SI', 'Falta de informacion del seguro (ep14)', 0, NULL, '2025-09-17 17:23:42', 1),
(98, 15, 1, 3, 3, 'Algo Satisfecho', 'Cliente satisfecho, pero califica con baja nota (ep15)', 1, 28, '2025-09-17 17:23:42', 1),
(99, 15, 2, 14, 9, '9', 'NPS 9/10 ep15', 0, NULL, '2025-09-17 17:23:42', 1),
(100, 15, 3, 19, 2, 'Poco Satisfecho', 'Falta gestion (ep15)', 1, 72, '2025-09-17 17:23:42', 1),
(101, 15, 4, 24, 2, 'Poco Satisfecho', 'No estaba el vehiculo listo en la entrega (ep15)', 1, 12, '2025-09-17 17:23:42', 1),
(102, 15, 5, 26, 1, 'SI', 'Demora tiempo de entrega (ep15)', 0, NULL, '2025-09-17 17:23:42', 1),
(103, 15, 6, 28, 1, 'SI', 'Falta de conocimiento y capacitacion (ep15)', 0, NULL, '2025-09-17 17:23:42', 1),
(105, 16, 1, 4, 2, 'Poco Satisfecho', 'Falta explicacion de las caracteristicas del vehiculo (ep16)', 1, 14, '2025-09-17 17:23:42', 1),
(106, 16, 2, 15, 10, '10', 'NPS 10/10 ep16', 0, NULL, '2025-09-17 17:23:42', 1),
(107, 16, 3, 17, 4, 'Muy Satisfecho', 'Falta atencion y amabilidad no aplica (ep16)', 0, NULL, '2025-09-17 17:23:42', 1),
(108, 16, 4, 23, 3, 'Algo Satisfecho', 'Aun no le entregan el VH (ep16)', 1, 13, '2025-09-17 17:23:42', 1),
(109, 16, 5, 26, 1, 'SI', 'Matriculacion (ep16)', 0, NULL, '2025-09-17 17:23:42', 1),
(110, 16, 6, 29, 0, 'NO', 'Robos o perdidas (ep16)', 1, 76, '2025-09-17 17:23:42', 1),
(112, 17, 1, 1, 5, 'Totalmente Satisfecho', 'Fallas del vehiculo no observadas (ep17)', 0, NULL, '2025-09-17 17:23:42', 1),
(113, 17, 2, 13, 8, '8', 'NPS 8/10 ep17', 0, NULL, '2025-09-17 17:23:42', 1),
(114, 17, 3, 18, 3, 'Algo Satisfecho', 'Falta atencion y amabilidad leve (ep17)', 1, 77, '2025-09-17 17:23:42', 1),
(115, 17, 4, 22, 4, 'Muy Satisfecho', 'No estaba el vehiculo listo en la entrega (ep17)', 0, NULL, '2025-09-17 17:23:42', 1),
(116, 17, 5, 26, 1, 'SI', 'Matriculacion sin inconvenientes (ep17)', 0, NULL, '2025-09-17 17:23:42', 1),
(117, 17, 6, 29, 0, 'NO', 'Falta de informacion del seguro (ep17)', 1, 26, '2025-09-17 17:23:42', 1),
(119, 18, 1, 3, 3, 'Algo Satisfecho', 'Fallas del vehiculo. Caso ep18', 1, 11, '2025-09-17 17:23:42', 1),
(120, 18, 2, 13, 8, '8', 'NPS 8/10 ep18', 0, NULL, '2025-09-17 17:23:42', 1),
(121, 18, 3, 17, 4, 'Muy Satisfecho', 'Seguro: falta de informacion puntual (ep18)', 0, NULL, '2025-09-17 17:23:42', 1),
(122, 18, 4, 23, 3, 'Algo Satisfecho', 'Fallas de pintura colision y pulida (ep18)', 1, 10, '2025-09-17 17:23:42', 1),
(123, 18, 5, 27, 0, 'NO', 'Demora tiempo de entrega (ep18)', 1, 15, '2025-09-17 17:23:42', 1),
(124, 18, 6, 29, 0, 'NO', 'Falta de honestidad y transparencia (ep18)', 1, 71, '2025-09-17 17:23:42', 1),
(126, 19, 1, 4, 2, 'Poco Satisfecho', 'Falta explicacion del funcionamiento de dispositivos (ep19)', 1, 8, '2025-09-17 17:23:42', 1),
(127, 19, 2, 14, 9, '9', 'NPS 9/10 ep19', 0, NULL, '2025-09-17 17:23:42', 1),
(128, 19, 3, 19, 2, 'Poco Satisfecho', 'Falta gestion (ep19)', 1, 72, '2025-09-17 17:23:42', 1),
(129, 19, 4, 22, 4, 'Muy Satisfecho', 'Accesorios y aplicaciones correctos (ep19)', 0, NULL, '2025-09-17 17:23:42', 1),
(130, 19, 5, 26, 1, 'SI', 'Demora tiempo de entrega (ep19)', 0, NULL, '2025-09-17 17:23:42', 1),
(131, 19, 6, 29, 0, 'NO', 'Falta de informacion del seguro (ep19)', 1, 26, '2025-09-17 17:23:42', 1),
(133, 20, 1, 5, 1, 'Totalmente Insatisfecho', 'No disponen el color o modelo que solicito (ep20)', 1, 23, '2025-09-17 17:23:42', 1),
(134, 20, 2, 14, 9, '9', 'NPS 9/10 ep20', 0, NULL, '2025-09-17 17:23:42', 1),
(135, 20, 3, 17, 4, 'Muy Satisfecho', 'Falta atencion y amabilidad no aplica (ep20)', 0, NULL, '2025-09-17 17:23:42', 1),
(136, 20, 4, 23, 3, 'Algo Satisfecho', 'Fallas de pintura colision y pulida (ep20)', 1, 10, '2025-09-17 17:23:42', 1),
(137, 20, 5, 27, 0, 'NO', 'Demora tiempo de entrega (ep20)', 1, 15, '2025-09-17 17:23:42', 1),
(138, 20, 6, 28, 1, 'SI', 'Falta de informacion del seguro (ep20)', 0, NULL, '2025-09-17 17:23:42', 1),
(140, 21, 1, 2, 4, 'Muy Satisfecho', 'Financiamiento sin demoras (ep21)', 0, NULL, '2025-09-17 17:23:42', 1),
(141, 21, 2, 15, 10, '10', 'NPS 10/10 ep21', 0, NULL, '2025-09-17 17:23:42', 1),
(142, 21, 3, 18, 3, 'Algo Satisfecho', 'Falta gestion moderada (ep21)', 1, 72, '2025-09-17 17:23:42', 1),
(143, 21, 4, 22, 4, 'Muy Satisfecho', 'Accesorios y aplicaciones en orden (ep21)', 0, NULL, '2025-09-17 17:23:42', 1),
(144, 21, 5, 26, 1, 'SI', 'Demora en el financiamiento no aplica (ep21)', 0, NULL, '2025-09-17 17:23:42', 1),
(145, 21, 6, 28, 1, 'SI', 'Demora de contratos sin novedad (ep21)', 0, NULL, '2025-09-17 17:23:42', 1),
(153, 24, 7, 31, 4, 'Muy Satisfecho', NULL, 0, NULL, '2025-09-19 05:50:34', 1),
(154, 24, 8, 43, 9, '9', NULL, 0, NULL, '2025-09-19 05:50:34', 1),
(155, 24, 9, 47, 3, 'Algo Satisfecho', 'Persiste un zumbido leve a baja velocidad tras la intervención.', 1, 55, '2025-09-19 05:50:34', 1),
(156, 24, 10, 53, 2, 'Poco Satisfecho', 'Percibió poca empatía y escasa proactividad al resolver dudas.', 1, 108, '2025-09-19 05:50:34', 1),
(157, 24, 11, 57, 3, 'Algo Satisfecho', 'Faltó confirmar con tiempo la hora exacta de entrega.', 1, 100, '2025-09-19 05:50:34', 1),
(158, 24, 12, 61, 0, 'NO', 'La entrega se retrasó respecto a lo informado inicialmente.', 1, 101, '2025-09-19 05:50:34', 1),
(160, 25, 7, 31, 4, 'Muy Satisfecho', NULL, 0, NULL, '2025-09-19 05:59:33', 1),
(161, 25, 8, 44, 10, '10', NULL, 0, NULL, '2025-09-19 05:59:33', 1),
(162, 25, 9, 46, 4, 'Muy Satisfecho', NULL, 0, NULL, '2025-09-19 05:59:33', 1),
(163, 25, 10, 51, 4, 'Muy Satisfecho', NULL, 0, NULL, '2025-09-19 05:59:33', 1),
(164, 25, 11, 56, 4, 'Muy Satisfecho', NULL, 0, NULL, '2025-09-19 05:59:33', 1),
(165, 25, 12, 60, 1, 'SI', NULL, 0, NULL, '2025-09-19 05:59:33', 1),
(166, 50, 7, 30, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-27 16:31:42', 1),
(167, 50, 8, 44, 10, '10', '', 0, NULL, '2025-09-27 16:31:42', 1),
(168, 50, 9, 45, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-27 16:31:42', 1),
(169, 50, 10, 50, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-27 16:31:42', 1),
(170, 50, 11, 55, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-27 16:31:42', 1),
(171, 50, 12, 60, 1, 'SI', '', 0, NULL, '2025-09-27 16:31:42', 1),
(172, 50, 14, 67, 5, 'Muy fácil', '', 0, NULL, '2025-09-27 16:31:42', 1),
(173, 51, 7, 30, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-28 04:26:08', 1),
(174, 51, 8, 35, 1, '1', 'No me comunicaron que tenian descuentos especiales, miestras estuvo en la reparqciuón si lo sabia antes pude haber solictado le realicen el mantenimiento completo no solo el cambio de aceite ', 1, 87, '2025-09-28 04:26:08', 1),
(175, 51, 9, 49, 1, 'Totalmente Insatisfecho', 'no informan del descuento oportunamente', 1, 87, '2025-09-28 04:26:08', 1),
(176, 51, 10, 54, 1, 'Totalmente Insatisfecho', 'No me comunicaron que tenian descuentos especiales, miestras estuvo en la reparqciuón si lo sabia antes pude haber solictado le realicen el mantenimiento completo no solo el cambio de aceite ', 1, 87, '2025-09-28 04:26:08', 1),
(177, 51, 11, 55, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-28 04:26:08', 1),
(178, 51, 12, 60, 1, 'SI', '', 0, NULL, '2025-09-28 04:26:08', 1),
(179, 51, 14, 71, 1, 'Muy difícil', '', 0, NULL, '2025-09-28 04:26:08', 1),
(182, 53, 7, 34, 1, 'Totalmente Insatisfecho', 'no se informo de los descuentos', 1, 87, '2025-09-29 21:45:39', 1),
(183, 53, 8, 44, 10, '10', '', 0, NULL, '2025-09-29 21:45:39', 1),
(184, 53, 9, 45, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-29 21:45:39', 1),
(185, 53, 10, 53, 2, 'Poco Satisfecho', 'no se informo de los descuentos', 1, 87, '2025-09-29 21:45:39', 1),
(186, 53, 11, 55, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-29 21:45:39', 1),
(187, 53, 12, 60, 1, 'SI', '', 0, NULL, '2025-09-29 21:45:39', 1),
(188, 53, 14, 69, 3, 'Ni fácil ni difícil', '', 0, NULL, '2025-09-29 21:45:39', 1),
(189, 54, 7, 34, 1, 'Totalmente Insatisfecho', 'mala actitud del técnico ', 1, 108, '2025-09-29 21:53:03', 1),
(190, 54, 8, 44, 10, '10', '', 0, NULL, '2025-09-29 21:53:03', 1),
(191, 54, 9, 46, 4, 'Muy Satisfecho', '', 0, NULL, '2025-09-29 21:53:03', 1),
(192, 54, 10, 51, 4, 'Muy Satisfecho', '', 0, NULL, '2025-09-29 21:53:03', 1),
(193, 54, 11, 56, 4, 'Muy Satisfecho', '', 0, NULL, '2025-09-29 21:53:03', 1),
(194, 54, 12, 60, 1, 'SI', '', 0, NULL, '2025-09-29 21:53:03', 1),
(195, 54, 14, 68, 4, 'Fácil', '', 0, NULL, '2025-09-29 21:53:03', 1),
(196, 55, 7, 30, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-29 22:43:31', 1),
(197, 55, 8, 43, 9, '9', '', 0, NULL, '2025-09-29 22:43:31', 1),
(198, 55, 9, 46, 4, 'Muy Satisfecho', '', 0, NULL, '2025-09-29 22:43:31', 1),
(199, 55, 10, 52, 3, 'Algo Satisfecho', 'nose informo del descuento ', 1, 87, '2025-09-29 22:43:31', 1),
(200, 55, 11, 56, 4, 'Muy Satisfecho', '', 0, NULL, '2025-09-29 22:43:31', 1),
(201, 55, 12, 60, 1, 'SI', '', 0, NULL, '2025-09-29 22:43:31', 1),
(202, 55, 14, 69, 3, 'Ni fácil ni difícil', '', 0, NULL, '2025-09-29 22:43:31', 1),
(203, 56, 1, 1, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-30 00:03:11', 1),
(204, 56, 2, 8, 3, '3', 'mensaje de prueba', 1, 108, '2025-09-30 00:03:11', 1),
(205, 56, 3, 16, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-30 00:03:11', 1),
(206, 56, 4, 21, 5, 'Totalmente Satisfecho', '', 0, NULL, '2025-09-30 00:03:11', 1),
(207, 56, 5, 26, 1, 'SI', '', 0, NULL, '2025-09-30 00:03:11', 1),
(208, 56, 6, 28, 1, 'SI', '', 0, NULL, '2025-09-30 00:03:11', 1),
(209, 56, 13, 62, 5, 'Muy fácil', '', 0, NULL, '2025-09-30 00:03:11', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `respuestasopciones`
--

CREATE TABLE `respuestasopciones` (
  `idOpcion` bigint(20) NOT NULL,
  `idPregunta` int(11) NOT NULL,
  `etiqueta` varchar(150) DEFAULT NULL,
  `valorNumerico` int(11) DEFAULT NULL,
  `secuenciaSiguiente` int(11) DEFAULT NULL,
  `generaPqr` tinyint(1) NOT NULL DEFAULT 0,
  `requiereComentario` tinyint(1) NOT NULL DEFAULT 0,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `respuestasopciones`
--

INSERT INTO `respuestasopciones` (`idOpcion`, `idPregunta`, `etiqueta`, `valorNumerico`, `secuenciaSiguiente`, `generaPqr`, `requiereComentario`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 1, 'Totalmente Satisfecho', 5, 2, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(2, 1, 'Muy Satisfecho', 4, 2, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(3, 1, 'Algo Satisfecho', 3, 2, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-07 23:54:18'),
(4, 1, 'Poco Satisfecho', 2, 2, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-07 23:54:20'),
(5, 1, 'Totalmente Insatisfecho', 1, 2, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-07 23:54:22'),
(6, 2, '1', 1, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(7, 2, '2', 2, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(8, 2, '3', 3, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(9, 2, '4', 4, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(10, 2, '5', 5, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(11, 2, '6', 6, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(12, 2, '7', 7, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(13, 2, '8', 8, 3, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(14, 2, '9', 9, 3, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(15, 2, '10', 10, 3, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(16, 3, 'Totalmente Satisfecho', 5, 4, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(17, 3, 'Muy Satisfecho', 4, 4, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(18, 3, 'Algo Satisfecho', 3, 4, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:14:28'),
(19, 3, 'Poco Satisfecho', 2, 4, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:14:29'),
(20, 3, 'Totalmente Insatisfecho', 1, 4, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:14:30'),
(21, 4, 'Totalmente Satisfecho', 5, 5, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:09'),
(22, 4, 'Muy Satisfecho', 4, 5, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(23, 4, 'Algo Satisfecho', 3, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:13'),
(24, 4, 'Poco Satisfecho', 2, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:14'),
(25, 4, 'Totalmente Insatisfecho', 1, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:15'),
(26, 5, 'SI', 1, 6, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(27, 5, 'NO', 0, 6, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:19'),
(28, 6, 'SI', 1, 7, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:42:07'),
(29, 6, 'NO', 0, 7, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-21 03:42:12'),
(30, 7, 'Totalmente Satisfecho', 5, 2, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(31, 7, 'Muy Satisfecho', 4, 2, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(32, 7, 'Algo Satisfecho', 3, 2, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-07 23:54:18'),
(33, 7, 'Poco Satisfecho', 2, 2, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-07 23:54:20'),
(34, 7, 'Totalmente Insatisfecho', 1, 2, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-07 23:54:22'),
(35, 8, '1', 1, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(36, 8, '2', 2, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(37, 8, '3', 3, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(38, 8, '4', 4, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(39, 8, '5', 5, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(40, 8, '6', 6, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(41, 8, '7', 7, 3, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(42, 8, '8', 8, 3, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(43, 8, '9', 9, 3, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(44, 8, '10', 10, 3, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(45, 9, 'Totalmente Satisfecho', 5, 4, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(46, 9, 'Muy Satisfecho', 4, 4, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(47, 9, 'Algo Satisfecho', 3, 4, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:14:28'),
(48, 9, 'Poco Satisfecho', 2, 4, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:14:29'),
(49, 9, 'Totalmente Insatisfecho', 1, 4, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:14:30'),
(50, 10, 'Totalmente Satisfecho', 5, 5, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:09'),
(51, 10, 'Muy Satisfecho', 4, 5, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(52, 10, 'Algo Satisfecho', 3, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:13'),
(53, 10, 'Poco Satisfecho', 2, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:14'),
(54, 10, 'Totalmente Insatisfecho', 1, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:15'),
(55, 11, 'Totalmente Satisfecho', 5, 5, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:09'),
(56, 11, 'Muy Satisfecho', 4, 5, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(57, 11, 'Algo Satisfecho', 3, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:13'),
(58, 11, 'Poco Satisfecho', 2, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:14'),
(59, 11, 'Totalmente Insatisfecho', 1, 5, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:15'),
(60, 12, 'SI', 1, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-06 03:06:03'),
(61, 12, 'NO', 0, NULL, 1, 1, 1, '2025-09-06 03:06:03', '2025-09-15 20:15:23'),
(62, 13, 'Muy fácil', 5, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:46:30'),
(63, 13, 'Fácil', 4, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:45:00'),
(64, 13, 'Ni fácil ni difícil', 3, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:45:05'),
(65, 13, 'Difícil', 2, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:46:36'),
(66, 13, 'Muy difícil', 1, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:46:40'),
(67, 14, 'Muy fácil', 5, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:46:44'),
(68, 14, 'Fácil', 4, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:45:30'),
(69, 14, 'Ni fácil ni difícil', 3, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:45:34'),
(70, 14, 'Difícil', 2, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:46:47'),
(71, 14, 'Muy difícil', 1, NULL, 0, 0, 1, '2025-09-06 03:06:03', '2025-09-21 03:46:51');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `idRol` int(11) NOT NULL,
  `nombreRol` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `accesos` text DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`idRol`, `nombreRol`, `descripcion`, `accesos`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'ADMINISTRADOR', 'Administrador del sistema', NULL, 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(2, 'COORDINADOR', 'Coordinación/Calidad', NULL, 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(3, 'JEFE DE AREA', 'Jefatura de área', NULL, 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(4, 'ASESOR COMERCIAL', 'Asesor comercial', NULL, 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `secuencial_diario`
--

CREATE TABLE `secuencial_diario` (
  `fecha` date NOT NULL,
  `ultimo` int(11) NOT NULL DEFAULT 0,
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `secuencial_diario`
--

INSERT INTO `secuencial_diario` (`fecha`, `ultimo`, `fechaActualizacion`) VALUES
('2025-09-15', 4, '2025-09-16 01:46:45'),
('2025-09-16', 6, '2025-09-17 04:12:59'),
('2025-09-17', 19, '2025-09-17 17:46:20'),
('2025-09-29', 2, '2025-09-30 00:03:11');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `seguimientospqrs`
--

CREATE TABLE `seguimientospqrs` (
  `idSeguimiento` bigint(20) NOT NULL,
  `idPqrs` bigint(20) NOT NULL,
  `idUsuario` int(11) DEFAULT NULL,
  `comentario` text DEFAULT NULL,
  `cambioEstado` int(11) DEFAULT NULL,
  `adjuntosUrl` varchar(500) DEFAULT NULL,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `seguimientospqrs`
--

INSERT INTO `seguimientospqrs` (`idSeguimiento`, `idPqrs`, `idUsuario`, `comentario`, `cambioEstado`, `adjuntosUrl`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 1, 7, 'Inicio gestión con el cliente.', 2, NULL, '2025-09-13 22:56:53', '2025-09-16 22:56:53'),
(2, 1, 7, 'Se solicita confirmación por correo.', 2, NULL, '2025-09-14 10:56:53', '2025-09-16 22:56:53'),
(3, 1, 7, 'Escalado por falta de respuesta.', 3, NULL, '2025-09-15 10:56:53', '2025-09-16 22:56:53'),
(4, 1, 12, 'Evidencia recibida; en análisis.', 3, 'docs/caso_123.pdf', '2025-09-16 04:56:53', '2025-09-16 22:56:53'),
(5, 1, 12, 'Cierre confirmado con satisfacción.', 4, NULL, '2025-09-16 20:56:53', '2025-09-16 22:56:53'),
(6, 2, 7, 'Contacto inicial y registro de caso.', 2, NULL, '2025-09-15 03:37:11', '2025-09-17 03:37:11'),
(7, 2, 7, 'Se envía recordatorio con propuesta de solución.', 2, NULL, '2025-09-15 15:37:11', '2025-09-17 03:37:11'),
(8, 2, 12, 'Validación de solución aplicada.', 2, 'docs/caso_002_correo.pdf', '2025-09-16 15:37:11', '2025-09-17 03:37:11'),
(9, 2, 12, 'Cierre por conformidad escrita del cliente.', 4, NULL, '2025-09-17 02:37:11', '2025-09-17 03:37:11'),
(10, 3, 7, 'Se realiza primera llamada, buzón de voz.', 2, NULL, '2025-09-13 04:13:28', '2025-09-17 04:13:28'),
(11, 3, 7, 'SMS y correo con pasos a seguir.', 2, NULL, '2025-09-14 04:13:28', '2025-09-17 04:13:28'),
(12, 3, 7, 'Escalado a supervisor por ausencia de respuesta.', 3, NULL, '2025-09-15 04:13:28', '2025-09-17 04:13:28'),
(13, 3, 12, 'Supervisor agenda llamada con el cliente.', 3, 'docs/caso_003_bitacora.pdf', '2025-09-16 04:13:28', '2025-09-17 04:13:28'),
(14, 3, 7, 'Se realiza primera llamada, buzón de voz.', 2, NULL, '2025-09-13 17:25:21', '2025-09-17 17:25:21'),
(15, 3, 7, 'SMS y correo con pasos a seguir.', 2, NULL, '2025-09-14 17:25:21', '2025-09-17 17:25:21'),
(16, 3, 7, 'Escalado a supervisor por ausencia de respuesta. (sin cambio: nivel máximo alcanzado)', 2, NULL, '2025-09-15 17:25:21', '2025-09-17 17:25:21'),
(17, 3, 12, 'Supervisor agenda llamada con el cliente.', 2, 'docs/caso_003_bitacora.pdf', '2025-09-16 17:25:21', '2025-09-17 17:25:21'),
(18, 4, 7, 'Apertura y clasificación del caso.', 2, NULL, '2025-09-15 05:33:56', '2025-09-17 17:33:56'),
(19, 4, 7, 'Se solicita soporte fotográfico.', 2, NULL, '2025-09-15 17:33:56', '2025-09-17 17:33:56'),
(20, 4, 7, 'Escalado automático por vencimiento parcial del SLA.', 3, NULL, '2025-09-16 05:33:56', '2025-09-17 17:33:56'),
(21, 4, 12, 'Análisis y veredicto emitido por el nivel superior.', 3, 'docs/caso_004_fotos.zip', '2025-09-17 05:33:56', '2025-09-17 17:33:56'),
(22, 4, 12, 'Cierre con compensación aprobada.', 4, NULL, '2025-09-17 15:33:56', '2025-09-17 17:33:56'),
(23, 5, 5, 'Se inicia gestión y se informa alcance.', 2, NULL, '2025-09-16 17:34:01', '2025-09-17 17:34:01'),
(24, 5, 5, 'Cliente solicita revisión adicional.', 2, NULL, '2025-09-17 01:34:01', '2025-09-17 17:34:01'),
(25, 5, 10, 'Se deja constancia de seguimiento activo.', 2, 'docs/caso_005_nota.pdf', '2025-09-17 13:34:01', '2025-09-17 17:34:01'),
(26, 6, 7, 'Llamada inicial, cliente expresa disconformidad.', 2, NULL, '2025-09-14 17:34:11', '2025-09-17 17:34:11'),
(27, 6, 7, 'Se envían opciones y se solicita respuesta.', 2, NULL, '2025-09-15 09:34:11', '2025-09-17 17:34:11'),
(28, 6, 7, 'Escalado a nivel 3 a pedido del cliente.', 3, NULL, '2025-09-16 01:34:11', '2025-09-17 17:34:11'),
(29, 6, 12, 'Área especializada toma contacto y analiza.', 3, 'docs/caso_006_audio.mp3', '2025-09-16 21:34:11', '2025-09-17 17:34:11'),
(30, 7, 7, 'Se comunica apertura del caso y tiempos de atención.', 2, NULL, '2025-09-14 05:34:18', '2025-09-17 17:34:18'),
(31, 7, 7, 'Cliente remite evidencia parcial.', 2, NULL, '2025-09-15 05:34:18', '2025-09-17 17:34:18'),
(32, 7, 7, 'Escalado a nivel superior por complejidad.', 3, NULL, '2025-09-16 05:34:18', '2025-09-17 17:34:18'),
(33, 7, 12, 'Validación con proveedor/fábrica.', 3, 'docs/caso_007_informe.pdf', '2025-09-17 05:34:18', '2025-09-17 17:34:18'),
(34, 7, 12, 'Cierre por solución técnica aplicada.', 4, NULL, '2025-09-17 16:34:18', '2025-09-17 17:34:18'),
(35, 8, 7, 'Gestión inicial: diagnóstico preliminar.', 2, NULL, '2025-09-16 05:34:25', '2025-09-17 17:34:25'),
(36, 8, 7, 'Se envía solución por correo y video explicativo.', 2, NULL, '2025-09-16 17:34:25', '2025-09-17 17:34:25'),
(37, 8, 12, 'Verificación de estabilidad del caso.', 2, 'docs/caso_008_video.mp4', '2025-09-17 11:34:25', '2025-09-17 17:34:25'),
(38, 8, 12, 'Cierre por resolución definitiva.', 4, NULL, '2025-09-17 16:34:25', '2025-09-17 17:34:25'),
(39, 9, 7, 'Primera gestión: recopilación de datos.', 2, NULL, '2025-09-13 23:34:25', '2025-09-17 17:34:25'),
(40, 9, 7, 'Cruce de información con otras áreas.', 2, NULL, '2025-09-14 17:34:25', '2025-09-17 17:34:25'),
(41, 9, 7, 'Escalado por hallazgos contradictorios.', 3, NULL, '2025-09-15 17:34:25', '2025-09-17 17:34:25'),
(42, 9, 12, 'Auditoría interna confirma procedimiento.', 3, 'docs/caso_009_auditoria.pdf', '2025-09-17 01:34:25', '2025-09-17 17:34:25'),
(43, 9, 12, 'Cierre con comunicación formal al cliente.', 4, NULL, '2025-09-17 15:34:25', '2025-09-17 17:34:25'),
(44, 10, 5, 'Se informa estado del caso.', 2, NULL, '2025-09-12 17:34:25', '2025-09-17 17:34:25'),
(45, 10, 5, 'Se solicita tiempo por disponibilidad de repuestos.', 2, NULL, '2025-09-13 17:34:25', '2025-09-17 17:34:25'),
(46, 10, 5, 'Escalado a nivel 2 para priorización de stock.', 3, NULL, '2025-09-15 17:34:25', '2025-09-17 17:34:25'),
(47, 10, 10, 'Se programa cita una vez llegue el material.', 3, 'docs/caso_010_ordencompra.pdf', '2025-09-16 17:34:25', '2025-09-17 17:34:25'),
(48, 11, 5, 'Contacto y explicación de cobertura.', 2, NULL, '2025-09-15 17:34:25', '2025-09-17 17:34:25'),
(49, 11, 5, 'Se ofrece alternativa y bonificación.', 2, NULL, '2025-09-16 11:34:25', '2025-09-17 17:34:25'),
(50, 11, 10, 'Se ejecuta ajuste comercial.', 2, 'docs/caso_011_acuerdo.pdf', '2025-09-17 11:34:25', '2025-09-17 17:34:25'),
(51, 11, 10, 'Cierre por acuerdo mutuo.', 4, NULL, '2025-09-17 16:34:25', '2025-09-17 17:34:25'),
(52, 12, 7, 'Registro de incidente y verificación inicial.', 2, NULL, '2025-09-13 17:34:25', '2025-09-17 17:34:25'),
(53, 12, 7, 'Recordatorio de envío de documentación.', 2, NULL, '2025-09-14 09:34:25', '2025-09-17 17:34:25'),
(54, 12, 7, 'Escalado por vencimiento de SLA intermedio.', 3, NULL, '2025-09-15 05:34:25', '2025-09-17 17:34:25'),
(55, 12, 12, 'Área de calidad solicita más datos.', 3, 'docs/caso_012_reqdatos.pdf', '2025-09-16 21:34:25', '2025-09-17 17:34:25'),
(56, 13, 5, 'Apertura y explicación del proceso.', 2, NULL, '2025-09-14 17:34:25', '2025-09-17 17:34:25'),
(57, 13, 5, 'Prueba de funcionamiento con el cliente.', 2, NULL, '2025-09-15 17:34:25', '2025-09-17 17:34:25'),
(58, 13, 5, 'Escalado a nivel 3 para autorización.', 3, NULL, '2025-09-16 17:34:25', '2025-09-17 17:34:25'),
(59, 13, 10, 'Autorización aprobada y aplicada.', 3, 'docs/caso_013_consentimiento.mp3', '2025-09-17 05:34:25', '2025-09-17 17:34:25'),
(60, 13, 10, 'Cierre con conformidad grabada.', 4, NULL, '2025-09-17 15:34:25', '2025-09-17 17:34:25'),
(61, 14, 7, 'Se informa recepción del caso.', 2, NULL, '2025-09-16 11:34:25', '2025-09-17 17:34:25'),
(62, 14, 7, 'Se calendariza visita de verificación.', 2, NULL, '2025-09-16 21:34:25', '2025-09-17 17:34:25'),
(63, 14, 12, 'Se deja constancia de seguimiento.', 2, 'docs/caso_014_agenda.ics', '2025-09-17 11:34:25', '2025-09-17 17:34:25'),
(64, 15, 5, 'Contacto con el cliente para diagnóstico.', 2, NULL, '2025-09-15 05:34:25', '2025-09-17 17:34:25'),
(65, 15, 5, 'Se coordinan pruebas adicionales.', 2, NULL, '2025-09-15 17:34:25', '2025-09-17 17:34:25'),
(66, 15, 5, 'Escalado a nivel 2 para visita técnica.', 3, NULL, '2025-09-16 17:34:25', '2025-09-17 17:34:25'),
(67, 15, 10, 'Técnico confirma agenda y requerimientos.', 3, 'docs/caso_015_visita.pdf', '2025-09-17 05:34:25', '2025-09-17 17:34:25'),
(68, 16, 7, 'Se crea caso y se prioriza.', 2, NULL, '2025-09-16 01:34:25', '2025-09-17 17:34:25'),
(69, 16, 7, 'Cliente comparte evidencia suficiente.', 2, NULL, '2025-09-16 13:34:25', '2025-09-17 17:34:25'),
(70, 16, 7, 'Escalado a nivel 2 para validación.', 3, NULL, '2025-09-17 01:34:25', '2025-09-17 17:34:25'),
(71, 16, 12, 'Validación en sitio y prueba final.', 3, 'docs/caso_016_checklist.xlsx', '2025-09-17 09:34:25', '2025-09-17 17:34:25'),
(72, 16, 12, 'Cierre por solución confirmada en campo.', 4, NULL, '2025-09-17 16:34:25', '2025-09-17 17:34:25'),
(73, 17, 7, 'Apertura y categorización.', 2, NULL, '2025-09-15 11:34:25', '2025-09-17 17:34:25'),
(74, 17, 7, 'Se envía certificado y manual al cliente.', 2, NULL, '2025-09-16 05:34:25', '2025-09-17 17:34:25'),
(75, 17, 12, 'Revisión de soporte legal.', 2, 'docs/caso_017_certificado.pdf', '2025-09-17 11:34:25', '2025-09-17 17:34:25'),
(76, 17, 12, 'Cierre por solución documental.', 4, NULL, '2025-09-17 16:34:25', '2025-09-17 17:34:25'),
(77, 18, 7, 'Recepción de caso complejo.', 2, NULL, '2025-09-12 17:34:25', '2025-09-17 17:34:25'),
(78, 18, 7, 'Se consolidan pruebas y trazas.', 2, NULL, '2025-09-14 17:34:25', '2025-09-17 17:34:25'),
(79, 18, 7, 'Escalado a nivel 4 por análisis de fabricante.', 3, NULL, '2025-09-15 17:34:25', '2025-09-17 17:34:25'),
(80, 18, 12, 'Fábrica solicita pruebas adicionales.', 3, 'docs/caso_018_logs.zip', '2025-09-17 05:34:25', '2025-09-17 17:34:25'),
(81, 19, 5, 'Se informa proceso y condiciones.', 2, NULL, '2025-09-14 09:34:25', '2025-09-17 17:34:25'),
(82, 19, 5, 'Cliente aporta video y correos.', 2, NULL, '2025-09-15 01:34:25', '2025-09-17 17:34:25'),
(83, 19, 5, 'Escalado por compromiso de tiempo.', 3, NULL, '2025-09-16 09:34:25', '2025-09-17 17:34:25'),
(84, 19, 10, 'Aprobación de ajuste comercial.', 3, 'docs/caso_019_nc.pdf', '2025-09-17 01:34:25', '2025-09-17 17:34:25'),
(85, 19, 10, 'Cierre con nota de crédito emitida.', 4, NULL, '2025-09-17 15:34:25', '2025-09-17 17:34:25'),
(86, 20, 7, 'Se abre caso y se investiga.', 2, NULL, '2025-09-15 17:34:25', '2025-09-17 17:34:25'),
(87, 20, 7, 'Se revisa reglamento y garantías.', 2, NULL, '2025-09-16 11:34:25', '2025-09-17 17:34:25'),
(88, 20, 12, 'Se adjunta informe conclusivo.', 2, 'docs/caso_020_informe_final.pdf', '2025-09-17 09:34:25', '2025-09-17 17:34:25'),
(89, 20, 12, 'Cierre: no procede, se deja respaldo.', 4, NULL, '2025-09-17 16:34:25', '2025-09-17 17:34:25'),
(90, 21, 5, 'Inicio de gestión: contacto telefónico con el cliente.', 2, NULL, '2025-09-14 17:47:07', '2025-09-17 17:47:07'),
(91, 21, 5, 'Se solicita confirmación y documentos por correo.', 2, NULL, '2025-09-15 05:47:07', '2025-09-17 17:47:07'),
(92, 21, 5, 'Escalado por falta de respuesta dentro del SLA.', 3, NULL, '2025-09-16 05:47:07', '2025-09-17 17:47:07'),
(93, 21, 10, 'Evidencia recibida; en análisis técnico.', 3, 'docs/caso_001.pdf', '2025-09-16 23:47:07', '2025-09-17 17:47:07'),
(94, 21, 10, 'Cierre confirmado con satisfacción del cliente.', 4, NULL, '2025-09-17 15:47:07', '2025-09-17 17:47:07'),
(95, 22, 5, 'Inicio de seguimiento del PQR.', NULL, NULL, '2025-09-29 22:50:13', '2025-09-29 22:50:13'),
(96, 22, 5, 'Inicio de seguimiento del PQR.', NULL, NULL, '2025-09-29 22:50:24', '2025-09-29 22:50:24'),
(97, 22, 5, 'Inicio de seguimiento del PQR.', NULL, NULL, '2025-09-29 22:50:51', '2025-09-29 22:50:51'),
(98, 22, 5, '<p>Se&nbsp;revisó&nbsp;con&nbsp;el&nbsp;usuario.</p>', NULL, NULL, '2025-09-29 22:56:02', '2025-09-30 00:14:46'),
(99, 22, 5, '<p>SE&nbsp;LLAMÓ&nbsp;AL&nbsp;CLIENTE.</p>', NULL, NULL, '2025-09-30 00:20:22', '2025-09-30 00:26:06'),
(100, 22, 5, '<p>Reunión&nbsp;con&nbsp;el&nbsp;cliente.</p>', NULL, NULL, '2025-09-30 00:21:26', '2025-09-30 00:26:10'),
(101, 22, 5, '<p>Se&nbsp;planifica&nbsp;nueva&nbsp;visita&nbsp;del&nbsp;cliente&nbsp;a&nbsp;Imbauto.</p>', NULL, NULL, '2025-09-30 00:25:27', '2025-09-30 00:25:27'),
(104, 23, 7, 'Inicio de seguimiento del PQR.', NULL, NULL, '2025-09-30 02:11:33', '2025-09-30 02:11:33'),
(105, 23, 2, '<p>asadkadkddsdffer</p>', NULL, NULL, '2025-09-30 02:12:22', '2025-09-30 02:12:22'),
(106, 23, 2, 'PQR escalado.', NULL, NULL, '2025-09-30 02:12:46', '2025-09-30 02:12:46'),
(107, 23, 2, '<p>wdwdwewqe</p>', NULL, NULL, '2025-09-30 02:12:56', '2025-09-30 02:12:56'),
(108, 23, 2, 'PQR Cerrado.', NULL, NULL, '2025-09-30 02:13:01', '2025-09-30 02:13:01');

--
-- Disparadores `seguimientospqrs`
--
DELIMITER $$
CREATE TRIGGER `trg_pqrs_primer_seguimiento` AFTER INSERT ON `seguimientospqrs` FOR EACH ROW BEGIN
  DECLARE v_en_proceso INT;
  DECLARE v_abierto INT;
  SELECT idEstado INTO v_en_proceso FROM estadosPqrs WHERE nombre='EN_PROCESO' LIMIT 1;
  SELECT idEstado INTO v_abierto     FROM estadosPqrs WHERE nombre='ABIERTO' LIMIT 1;

  UPDATE pqrs
     SET idEstado = v_en_proceso,
         fechaActualizacion = NOW()
   WHERE idPqrs = NEW.idPqrs
     AND idEstado = v_abierto;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipospqrs`
--

CREATE TABLE `tipospqrs` (
  `idTipo` int(11) NOT NULL,
  `nombre` enum('PETICION','QUEJA','RECLAMO','SUGERENCIA') NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `tipospqrs`
--

INSERT INTO `tipospqrs` (`idTipo`, `nombre`, `descripcion`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'PETICION', 'Solicitud', 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(2, 'QUEJA', 'Inconformidad menor', 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(3, 'RECLAMO', 'Inconformidad mayor', 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40'),
(4, 'SUGERENCIA', 'Mejora', 1, '2025-09-04 04:59:40', '2025-09-04 04:59:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `idUsuario` int(11) NOT NULL,
  `usuario` varchar(60) NOT NULL,
  `password` varchar(255) NOT NULL,
  `descripcion` text NOT NULL,
  `idPersona` int(11) NOT NULL,
  `idAgencia` int(11) NOT NULL,
  `idRol` int(11) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fechaCreacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechaActualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`idUsuario`, `usuario`, `password`, `descripcion`, `idPersona`, `idAgencia`, `idRol`, `estado`, `fechaCreacion`, `fechaActualizacion`) VALUES
(1, 'usuarioAdmin', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'usuarioAdmin', 1, 1, 1, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(2, 'usuarioCordinador', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'usuarioCordinador', 2, 1, 2, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(3, 'usuarioJefe', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'usuarioJefe', 3, 1, 3, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(4, 'usuarioAsesor', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'Asesor0', 4, 1, 2, 0, '2025-09-10 03:37:10', '2025-09-14 00:21:25'),
(5, 'asesor1', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'asesor1', 5, 1, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(6, 'asesor2', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'asesor2 Tulcan', 6, 2, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(7, 'asesor3', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'asesor3 Esmeraldas', 7, 3, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(8, 'asesor4', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'asesor4 El Coca', 8, 4, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(9, 'asesor5', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'asesor5 Lago Agrio', 9, 4, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(10, 'jefe1', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'Jefe1 Ibarra', 10, 1, 3, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(11, 'jefe2', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'Jefe2 Tulcan', 11, 2, 3, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(12, 'jefe3', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'Jefe3 Esmeraldas', 12, 3, 3, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(13, 'jefe4', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'Jefe4 El Coca', 13, 4, 3, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(14, 'jefe5', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'Jefe5 Lago Agrio', 14, 5, 3, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(15, 'cordinador1', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'Cordinador1 Ibarra', 15, 1, 2, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(16, 'mariana.ger', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'mariana.ger tulcan', 16, 2, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(17, 'angel.hernandez', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'angel.hernandez - tulcan', 17, 2, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(18, 'miguel.mendez', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'miguel.mendez - Ibarra', 18, 3, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(19, 'marco.jativa', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'marco.jativa - Ibarra', 19, 4, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(20, 'matias.villarreal', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'matias.villarreal - Ibarra', 20, 4, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(29, 'anderson.campos', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'anderson.campos - ibarra', 22, 1, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(30, 'jaime.vite', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'jaime.vite - Esmeraldas', 23, 3, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(31, 'jhonny.bone', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'jhonny.bone - Esmeraldas', 24, 3, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(32, 'michael.saltos', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'michael.saltos - El Coca', 25, 4, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(33, 'evelyn.lara', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'evelyn.lara - El Coca', 27, 4, 4, 1, '2025-09-10 03:37:10', '2025-09-10 03:37:10'),
(34, 'yoel.jumbo', '$2y$10$LT8GqMrlopkbQQLpfzC6RurM7/hNbFIFjzWj8WfxBfseWBGlnbw4K', 'yoel.jumbo - Lago Agrio', 28, 5, 4, 1, '2025-09-10 03:37:10', '2025-09-25 16:34:16');

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_csat_mensual_canal_agencia`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_csat_mensual_canal_agencia` (
`periodo` varchar(7)
,`idEncuesta` bigint(20)
,`encuesta` varchar(120)
,`canal` varchar(80)
,`agencia` varchar(120)
,`csat` decimal(13,2)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_encuesta_tasa_respuesta`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_encuesta_tasa_respuesta` (
`idEncuesta` bigint(20)
,`encuesta` varchar(120)
,`canal` varchar(80)
,`total_programadas` bigint(21)
,`total_respondidas` decimal(22,0)
,`tasa_respuesta_pct` decimal(28,2)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_nps_mensual_canal`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_nps_mensual_canal` (
`periodo` varchar(7)
,`canal` varchar(80)
,`nps` decimal(31,5)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_pqrs_estado_canal`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_pqrs_estado_canal` (
`canal` varchar(80)
,`estado` enum('ABIERTO','EN_PROCESO','ESCALADO','CERRADO')
,`total` bigint(21)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_pqrs_tiempo_primer_seg`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_pqrs_tiempo_primer_seg` (
`agencia` varchar(120)
,`horas_promedio_primer_seg` decimal(24,4)
);

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_csat_mensual_canal_agencia`
--
DROP TABLE IF EXISTS `vw_csat_mensual_canal_agencia`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_csat_mensual_canal_agencia`  AS SELECT date_format(`rc`.`fechaRespuesta`,'%Y-%m') AS `periodo`, `e`.`idEncuesta` AS `idEncuesta`, `e`.`nombre` AS `encuesta`, `can`.`nombre` AS `canal`, `ag`.`nombre` AS `agencia`, round(avg(`rc`.`valorNumerico`),2) AS `csat` FROM ((((((`respuestascliente` `rc` join `preguntas` `p` on(`p`.`idPregunta` = `rc`.`idPregunta` and `p`.`tipo` = 'ESCALA_1_10' and `p`.`esNps` = 0)) join `encuestasprogramadas` `ep` on(`ep`.`idProgEncuesta` = `rc`.`idProgEncuesta`)) join `encuestas` `e` on(`e`.`idEncuesta` = `ep`.`idEncuesta`)) join `canales` `can` on(`can`.`idCanal` = `e`.`idCanal`)) left join `atenciones` `a` on(`a`.`idAtencion` = `ep`.`idAtencion`)) left join `agencias` `ag` on(`ag`.`idAgencia` = `a`.`idAgencia`)) GROUP BY date_format(`rc`.`fechaRespuesta`,'%Y-%m'), `e`.`idEncuesta`, `e`.`nombre`, `can`.`nombre`, `ag`.`nombre` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_encuesta_tasa_respuesta`
--
DROP TABLE IF EXISTS `vw_encuesta_tasa_respuesta`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_encuesta_tasa_respuesta`  AS SELECT `e`.`idEncuesta` AS `idEncuesta`, `e`.`nombre` AS `encuesta`, `c`.`nombre` AS `canal`, count(0) AS `total_programadas`, sum(case when `ep`.`estadoEnvio` = 'RESPONDIDA' then 1 else 0 end) AS `total_respondidas`, round(100 * sum(case when `ep`.`estadoEnvio` = 'RESPONDIDA' then 1 else 0 end) / nullif(count(0),0),2) AS `tasa_respuesta_pct` FROM ((`encuestasprogramadas` `ep` join `encuestas` `e` on(`e`.`idEncuesta` = `ep`.`idEncuesta`)) join `canales` `c` on(`c`.`idCanal` = `e`.`idCanal`)) GROUP BY `e`.`idEncuesta`, `e`.`nombre`, `c`.`nombre` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_nps_mensual_canal`
--
DROP TABLE IF EXISTS `vw_nps_mensual_canal`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_nps_mensual_canal`  AS SELECT date_format(`rc`.`fechaRespuesta`,'%Y-%m') AS `periodo`, `can`.`nombre` AS `canal`, 100.0 * sum(case when `rc`.`valorNumerico` between 9 and 10 then 1 else 0 end) / count(0) - 100.0 * sum(case when `rc`.`valorNumerico` between 0 and 6 then 1 else 0 end) / count(0) AS `nps` FROM ((((`respuestascliente` `rc` join `preguntas` `p` on(`p`.`idPregunta` = `rc`.`idPregunta` and `p`.`esNps` = 1)) join `encuestasprogramadas` `ep` on(`ep`.`idProgEncuesta` = `rc`.`idProgEncuesta`)) join `encuestas` `e` on(`e`.`idEncuesta` = `ep`.`idEncuesta`)) join `canales` `can` on(`can`.`idCanal` = `e`.`idCanal`)) GROUP BY date_format(`rc`.`fechaRespuesta`,'%Y-%m'), `can`.`nombre` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_pqrs_estado_canal`
--
DROP TABLE IF EXISTS `vw_pqrs_estado_canal`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_pqrs_estado_canal`  AS SELECT `can`.`nombre` AS `canal`, `est`.`nombre` AS `estado`, count(0) AS `total` FROM ((`pqrs` `p` join `canales` `can` on(`can`.`idCanal` = `p`.`idCanal`)) join `estadospqrs` `est` on(`est`.`idEstado` = `p`.`idEstado`)) GROUP BY `can`.`nombre`, `est`.`nombre` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_pqrs_tiempo_primer_seg`
--
DROP TABLE IF EXISTS `vw_pqrs_tiempo_primer_seg`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_pqrs_tiempo_primer_seg`  AS SELECT `ag`.`nombre` AS `agencia`, avg(timestampdiff(HOUR,`p`.`fechaCreacion`,(select min(`s`.`fechaCreacion`) from `seguimientospqrs` `s` where `s`.`idPqrs` = `p`.`idPqrs`))) AS `horas_promedio_primer_seg` FROM (`pqrs` `p` left join `agencias` `ag` on(`ag`.`idAgencia` = `p`.`idAgencia`)) GROUP BY `ag`.`nombre` ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `agencias`
--
ALTER TABLE `agencias`
  ADD PRIMARY KEY (`idAgencia`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `atenciones`
--
ALTER TABLE `atenciones`
  ADD PRIMARY KEY (`idAtencion`),
  ADD KEY `fk_atn_agencia` (`idAgencia`),
  ADD KEY `idx_atn_cliente_fecha` (`idCliente`,`fechaAtencion`),
  ADD KEY `idx_atenciones_idCanal` (`idCanal`),
  ADD KEY `idx_atenciones_idAsesor` (`idAsesor`);

--
-- Indices de la tabla `canales`
--
ALTER TABLE `canales`
  ADD PRIMARY KEY (`idCanal`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `categoriaspadre`
--
ALTER TABLE `categoriaspadre`
  ADD PRIMARY KEY (`idCategoriaPadre`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `categoriaspqrs`
--
ALTER TABLE `categoriaspqrs`
  ADD PRIMARY KEY (`idCategoria`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `fk_cat_padre` (`idCategoriaPadre`),
  ADD KEY `fk_cat_canal` (`idCanal`),
  ADD KEY `fk_cat_tipo` (`idTipo`);

--
-- Indices de la tabla `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`idCliente`),
  ADD UNIQUE KEY `cedula` (`cedula`),
  ADD UNIQUE KEY `uk_clientes_idClienteErp` (`idClienteErp`),
  ADD KEY `idx_cli_email` (`email`),
  ADD KEY `idx_clientes_idClienteErp` (`idClienteErp`);

--
-- Indices de la tabla `clientes_consentimientos`
--
ALTER TABLE `clientes_consentimientos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idCliente` (`idCliente`);

--
-- Indices de la tabla `config_escalamiento`
--
ALTER TABLE `config_escalamiento`
  ADD PRIMARY KEY (`idConfig`),
  ADD UNIQUE KEY `uq_cfg_new` (`idAgencia`,`idEncuesta`,`nivel`),
  ADD KEY `fk_cfg_resp` (`idResponsable`),
  ADD KEY `idx_cfg_idEncuesta` (`idEncuesta`);

--
-- Indices de la tabla `encuestas`
--
ALTER TABLE `encuestas`
  ADD PRIMARY KEY (`idEncuesta`),
  ADD KEY `fk_enc_canal` (`idCanal`);

--
-- Indices de la tabla `encuestasprogramadas`
--
ALTER TABLE `encuestasprogramadas`
  ADD PRIMARY KEY (`idProgEncuesta`),
  ADD UNIQUE KEY `uk_ep_enc_atn` (`idEncuesta`,`idAtencion`),
  ADD UNIQUE KEY `tokenEncuesta` (`tokenEncuesta`),
  ADD KEY `fk_ep_atn` (`idAtencion`),
  ADD KEY `idx_ep_prox` (`proximoEnvio`,`estadoEnvio`,`intentosEnviados`),
  ADD KEY `idx_ep_cliente_estado` (`idCliente`,`estadoEnvio`),
  ADD KEY `idx_ep_estado` (`estadoEnvio`),
  ADD KEY `idx_ep_estado_intentos` (`estadoEnvio`,`intentosEnviados`);

--
-- Indices de la tabla `estadospqrs`
--
ALTER TABLE `estadospqrs`
  ADD PRIMARY KEY (`idEstado`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `personas`
--
ALTER TABLE `personas`
  ADD PRIMARY KEY (`idPersona`),
  ADD KEY `idx_personas_cedula` (`cedula`);

--
-- Indices de la tabla `pqrs`
--
ALTER TABLE `pqrs`
  ADD PRIMARY KEY (`idPqrs`),
  ADD UNIQUE KEY `codigo` (`codigo`),
  ADD UNIQUE KEY `uq_pqrs_idprogen` (`idProgEncuesta`),
  ADD KEY `fk_pqrs_tipo` (`idTipo`),
  ADD KEY `fk_pqrs_cat` (`idCategoria`),
  ADD KEY `fk_pqrs_canal` (`idCanal`),
  ADD KEY `fk_pqrs_agencia` (`idAgencia`),
  ADD KEY `fk_pqrs_cli` (`idCliente`),
  ADD KEY `fk_pqrs_enc` (`idEncuesta`),
  ADD KEY `idx_pqrs_estado` (`idEstado`),
  ADD KEY `idx_pqrs_nivel` (`nivelActual`,`fechaLimiteNivel`);

--
-- Indices de la tabla `pqrs_preguntas`
--
ALTER TABLE `pqrs_preguntas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pp` (`idPqrs`,`idPregunta`),
  ADD KEY `fk_pp_preg` (`idPregunta`),
  ADD KEY `fk_pp_cat` (`idCategoria`);

--
-- Indices de la tabla `pqrs_responsables`
--
ALTER TABLE `pqrs_responsables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pr` (`idPqrs`,`nivel`),
  ADD KEY `fk_pr_resp` (`idResponsable`);

--
-- Indices de la tabla `preguntas`
--
ALTER TABLE `preguntas`
  ADD PRIMARY KEY (`idPregunta`),
  ADD UNIQUE KEY `uq_preg_orden` (`idEncuesta`,`orden`),
  ADD KEY `idx_preguntas_esCes` (`esCes`);

--
-- Indices de la tabla `respuestascliente`
--
ALTER TABLE `respuestascliente`
  ADD PRIMARY KEY (`idRespCliente`),
  ADD UNIQUE KEY `uq_rc` (`idProgEncuesta`,`idPregunta`),
  ADD KEY `fk_rc_preg` (`idPregunta`),
  ADD KEY `fk_rc_opt` (`idOpcion`),
  ADD KEY `fk_rc_categoria` (`idcategoria`);

--
-- Indices de la tabla `respuestasopciones`
--
ALTER TABLE `respuestasopciones`
  ADD PRIMARY KEY (`idOpcion`),
  ADD KEY `fk_opt_preg` (`idPregunta`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`idRol`),
  ADD UNIQUE KEY `nombreRol` (`nombreRol`);

--
-- Indices de la tabla `secuencial_diario`
--
ALTER TABLE `secuencial_diario`
  ADD PRIMARY KEY (`fecha`);

--
-- Indices de la tabla `seguimientospqrs`
--
ALTER TABLE `seguimientospqrs`
  ADD PRIMARY KEY (`idSeguimiento`),
  ADD KEY `fk_seg_usuario` (`idUsuario`),
  ADD KEY `fk_seg_estado` (`cambioEstado`),
  ADD KEY `idx_seg_pqrs` (`idPqrs`,`fechaCreacion`);

--
-- Indices de la tabla `tipospqrs`
--
ALTER TABLE `tipospqrs`
  ADD PRIMARY KEY (`idTipo`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`idUsuario`),
  ADD UNIQUE KEY `usuario` (`usuario`),
  ADD KEY `fk_u_persona` (`idPersona`),
  ADD KEY `fk_u_rol` (`idRol`),
  ADD KEY `fk_u_agencia` (`idAgencia`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `agencias`
--
ALTER TABLE `agencias`
  MODIFY `idAgencia` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `atenciones`
--
ALTER TABLE `atenciones`
  MODIFY `idAtencion` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- AUTO_INCREMENT de la tabla `canales`
--
ALTER TABLE `canales`
  MODIFY `idCanal` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `categoriaspadre`
--
ALTER TABLE `categoriaspadre`
  MODIFY `idCategoriaPadre` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT de la tabla `categoriaspqrs`
--
ALTER TABLE `categoriaspqrs`
  MODIFY `idCategoria` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT de la tabla `clientes`
--
ALTER TABLE `clientes`
  MODIFY `idCliente` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT de la tabla `clientes_consentimientos`
--
ALTER TABLE `clientes_consentimientos`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT de la tabla `config_escalamiento`
--
ALTER TABLE `config_escalamiento`
  MODIFY `idConfig` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT de la tabla `encuestas`
--
ALTER TABLE `encuestas`
  MODIFY `idEncuesta` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `encuestasprogramadas`
--
ALTER TABLE `encuestasprogramadas`
  MODIFY `idProgEncuesta` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT de la tabla `estadospqrs`
--
ALTER TABLE `estadospqrs`
  MODIFY `idEstado` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `personas`
--
ALTER TABLE `personas`
  MODIFY `idPersona` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT de la tabla `pqrs`
--
ALTER TABLE `pqrs`
  MODIFY `idPqrs` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT de la tabla `pqrs_preguntas`
--
ALTER TABLE `pqrs_preguntas`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT de la tabla `pqrs_responsables`
--
ALTER TABLE `pqrs_responsables`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT de la tabla `preguntas`
--
ALTER TABLE `preguntas`
  MODIFY `idPregunta` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT de la tabla `respuestascliente`
--
ALTER TABLE `respuestascliente`
  MODIFY `idRespCliente` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=210;

--
-- AUTO_INCREMENT de la tabla `respuestasopciones`
--
ALTER TABLE `respuestasopciones`
  MODIFY `idOpcion` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `idRol` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `seguimientospqrs`
--
ALTER TABLE `seguimientospqrs`
  MODIFY `idSeguimiento` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT de la tabla `tipospqrs`
--
ALTER TABLE `tipospqrs`
  MODIFY `idTipo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `idUsuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `atenciones`
--
ALTER TABLE `atenciones`
  ADD CONSTRAINT `fk_atenciones_canal` FOREIGN KEY (`idCanal`) REFERENCES `canales` (`idCanal`),
  ADD CONSTRAINT `fk_atenciones_idAsesor` FOREIGN KEY (`idAsesor`) REFERENCES `usuarios` (`idUsuario`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_atn_agencia` FOREIGN KEY (`idAgencia`) REFERENCES `agencias` (`idAgencia`),
  ADD CONSTRAINT `fk_atn_cliente` FOREIGN KEY (`idCliente`) REFERENCES `clientes` (`idCliente`);

--
-- Filtros para la tabla `categoriaspqrs`
--
ALTER TABLE `categoriaspqrs`
  ADD CONSTRAINT `fk_cat_canal` FOREIGN KEY (`idCanal`) REFERENCES `canales` (`idCanal`),
  ADD CONSTRAINT `fk_cat_padre` FOREIGN KEY (`idCategoriaPadre`) REFERENCES `categoriaspadre` (`idCategoriaPadre`),
  ADD CONSTRAINT `fk_cat_tipo` FOREIGN KEY (`idTipo`) REFERENCES `tipospqrs` (`idTipo`);

--
-- Filtros para la tabla `clientes_consentimientos`
--
ALTER TABLE `clientes_consentimientos`
  ADD CONSTRAINT `clientes_consentimientos_ibfk_1` FOREIGN KEY (`idCliente`) REFERENCES `clientes` (`idCliente`);

--
-- Filtros para la tabla `config_escalamiento`
--
ALTER TABLE `config_escalamiento`
  ADD CONSTRAINT `fk_cfg_agencia` FOREIGN KEY (`idAgencia`) REFERENCES `agencias` (`idAgencia`),
  ADD CONSTRAINT `fk_cfg_encuesta` FOREIGN KEY (`idEncuesta`) REFERENCES `encuestas` (`idEncuesta`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cfg_resp` FOREIGN KEY (`idResponsable`) REFERENCES `usuarios` (`idUsuario`);

--
-- Filtros para la tabla `encuestas`
--
ALTER TABLE `encuestas`
  ADD CONSTRAINT `fk_enc_canal` FOREIGN KEY (`idCanal`) REFERENCES `canales` (`idCanal`);

--
-- Filtros para la tabla `encuestasprogramadas`
--
ALTER TABLE `encuestasprogramadas`
  ADD CONSTRAINT `fk_ep_atn` FOREIGN KEY (`idAtencion`) REFERENCES `atenciones` (`idAtencion`),
  ADD CONSTRAINT `fk_ep_cli` FOREIGN KEY (`idCliente`) REFERENCES `clientes` (`idCliente`),
  ADD CONSTRAINT `fk_ep_enc` FOREIGN KEY (`idEncuesta`) REFERENCES `encuestas` (`idEncuesta`);

--
-- Filtros para la tabla `pqrs`
--
ALTER TABLE `pqrs`
  ADD CONSTRAINT `fk_pqrs_agencia` FOREIGN KEY (`idAgencia`) REFERENCES `agencias` (`idAgencia`),
  ADD CONSTRAINT `fk_pqrs_canal` FOREIGN KEY (`idCanal`) REFERENCES `canales` (`idCanal`),
  ADD CONSTRAINT `fk_pqrs_cat` FOREIGN KEY (`idCategoria`) REFERENCES `categoriaspqrs` (`idCategoria`),
  ADD CONSTRAINT `fk_pqrs_cli` FOREIGN KEY (`idCliente`) REFERENCES `clientes` (`idCliente`),
  ADD CONSTRAINT `fk_pqrs_enc` FOREIGN KEY (`idEncuesta`) REFERENCES `encuestas` (`idEncuesta`),
  ADD CONSTRAINT `fk_pqrs_ep` FOREIGN KEY (`idProgEncuesta`) REFERENCES `encuestasprogramadas` (`idProgEncuesta`),
  ADD CONSTRAINT `fk_pqrs_estado` FOREIGN KEY (`idEstado`) REFERENCES `estadospqrs` (`idEstado`),
  ADD CONSTRAINT `fk_pqrs_tipo` FOREIGN KEY (`idTipo`) REFERENCES `tipospqrs` (`idTipo`);

--
-- Filtros para la tabla `pqrs_preguntas`
--
ALTER TABLE `pqrs_preguntas`
  ADD CONSTRAINT `fk_pp_cat` FOREIGN KEY (`idCategoria`) REFERENCES `categoriaspqrs` (`idCategoria`),
  ADD CONSTRAINT `fk_pp_pqrs` FOREIGN KEY (`idPqrs`) REFERENCES `pqrs` (`idPqrs`),
  ADD CONSTRAINT `fk_pp_preg` FOREIGN KEY (`idPregunta`) REFERENCES `preguntas` (`idPregunta`);

--
-- Filtros para la tabla `pqrs_responsables`
--
ALTER TABLE `pqrs_responsables`
  ADD CONSTRAINT `fk_pr_pqrs` FOREIGN KEY (`idPqrs`) REFERENCES `pqrs` (`idPqrs`),
  ADD CONSTRAINT `fk_pr_resp` FOREIGN KEY (`idResponsable`) REFERENCES `usuarios` (`idUsuario`);

--
-- Filtros para la tabla `preguntas`
--
ALTER TABLE `preguntas`
  ADD CONSTRAINT `fk_preg_enc` FOREIGN KEY (`idEncuesta`) REFERENCES `encuestas` (`idEncuesta`);

--
-- Filtros para la tabla `respuestascliente`
--
ALTER TABLE `respuestascliente`
  ADD CONSTRAINT `fk_rc_categoria` FOREIGN KEY (`idcategoria`) REFERENCES `categoriaspqrs` (`idCategoria`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_rc_ep` FOREIGN KEY (`idProgEncuesta`) REFERENCES `encuestasprogramadas` (`idProgEncuesta`),
  ADD CONSTRAINT `fk_rc_opt` FOREIGN KEY (`idOpcion`) REFERENCES `respuestasopciones` (`idOpcion`),
  ADD CONSTRAINT `fk_rc_preg` FOREIGN KEY (`idPregunta`) REFERENCES `preguntas` (`idPregunta`);

--
-- Filtros para la tabla `respuestasopciones`
--
ALTER TABLE `respuestasopciones`
  ADD CONSTRAINT `fk_opt_preg` FOREIGN KEY (`idPregunta`) REFERENCES `preguntas` (`idPregunta`);

--
-- Filtros para la tabla `seguimientospqrs`
--
ALTER TABLE `seguimientospqrs`
  ADD CONSTRAINT `fk_seg_estado` FOREIGN KEY (`cambioEstado`) REFERENCES `estadospqrs` (`idEstado`),
  ADD CONSTRAINT `fk_seg_pqrs` FOREIGN KEY (`idPqrs`) REFERENCES `pqrs` (`idPqrs`),
  ADD CONSTRAINT `fk_seg_usuario` FOREIGN KEY (`idUsuario`) REFERENCES `usuarios` (`idUsuario`);

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_u_agencia` FOREIGN KEY (`idAgencia`) REFERENCES `agencias` (`idAgencia`),
  ADD CONSTRAINT `fk_u_persona` FOREIGN KEY (`idPersona`) REFERENCES `personas` (`idPersona`),
  ADD CONSTRAINT `fk_u_rol` FOREIGN KEY (`idRol`) REFERENCES `roles` (`idRol`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
