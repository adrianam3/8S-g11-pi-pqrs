<?php
// TODO: Modelo de Encuestas Programadas
require_once('../config/config.php');

class EncuestasProgramadas
{
    // ===== LISTAR con filtros =====
    // Filtros disponibles (opcionales):
    // - soloActivas: 1|0|null (columna estado)
    // - estadoEnvio: 'PENDIENTE'|'ENVIADA'|'RESPONDIDA'|'NO_CONTESTADA'|'EXCLUIDA'|null
    // - canalEnvio:  'EMAIL'|'WHATSAPP'|'SMS'|'OTRO'|null
    // - idEncuesta, idAtencion, idCliente
    // - desde, hasta: rangos de fecha sobre fechaProgramadaInicial
    // - q: búsqueda por nombre encuesta o nombre cliente
    public function todos($limit = 100, $offset = 0, $soloActivas = null, $estadoEnvio = null, $canalEnvio = null,
                          $idEncuesta = null, $idAtencion = null, $idCliente = null, $desde = null, $hasta = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        // Activo/inactivo
        if ($soloActivas === 1 || $soloActivas === true || $soloActivas === '1') {
            $conds[] = 'ep.estado = 1';
        } elseif ($soloActivas === 0 || $soloActivas === false || $soloActivas === '0') {
            $conds[] = 'ep.estado = 0';
        }

        // estadoEnvio (enum whitelist)
        $validEstados = ['PENDIENTE','ENVIADA','RESPONDIDA','NO_CONTESTADA','EXCLUIDA'];
        if (!is_null($estadoEnvio) && in_array($estadoEnvio, $validEstados, true)) {
            $conds[] = 'ep.estadoEnvio = ?';
            $types  .= 's';
            $vals[]  = $estadoEnvio;
        }

        // canalEnvio (enum whitelist)
        $validCanales = ['EMAIL','WHATSAPP','SMS','OTRO'];
        if (!is_null($canalEnvio) && in_array($canalEnvio, $validCanales, true)) {
            $conds[] = 'ep.canalEnvio = ?';
            $types  .= 's';
            $vals[]  = $canalEnvio;
        }

        // IDs
        if (!is_null($idEncuesta) && (int)$idEncuesta > 0) {
            $conds[] = 'ep.idEncuesta = ?';
            $types  .= 'i';
            $vals[]  = (int)$idEncuesta;
        }
        if (!is_null($idAtencion) && (int)$idAtencion > 0) {
            $conds[] = 'ep.idAtencion = ?';
            $types  .= 'i';
            $vals[]  = (int)$idAtencion;
        }
        if (!is_null($idCliente) && (int)$idCliente > 0) {
            $conds[] = 'ep.idCliente = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCliente;
        }

        // Rango de fechas por fechaProgramadaInicial
        if (!is_null($desde) && $desde !== '') {
            $conds[] = 'ep.fechaProgramadaInicial >= ?';
            $types  .= 's';
            $vals[]  = $desde;
        }
        if (!is_null($hasta) && $hasta !== '') {
            $conds[] = 'ep.fechaProgramadaInicial <= ?';
            $types  .= 's';
            $vals[]  = $hasta;
        }

        // Búsqueda
        if (!is_null($q) && $q !== '') {
            $conds[] = '(LOWER(e.nombre) LIKE LOWER(?) OR LOWER(CONCAT(cl.nombres," ",cl.apellidos)) LIKE LOWER(?))';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        // Evitar bind en LIMIT/OFFSET
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT
                    ep.idProgEncuesta, ep.idEncuesta, ep.idAtencion, ep.idCliente,
                    ep.fechaProgramadaInicial, ep.intentosEnviados, ep.maxIntentos,
                    ep.proximoEnvio, ep.estadoEnvio, ep.canalEnvio, ep.enviadoPor,
                    ep.observacionEnvio, ep.ultimoEnvio, ep.asuntoCache, ep.cuerpoHtmlCache,
                    ep.tokenEncuesta, ep.consentimientoAceptado, ep.fechaConsentimiento,
                    ep.observaciones, ep.estado, ep.fechaCreacion, ep.fechaActualizacion,
                    e.nombre            AS nombreEncuesta,
                    cl.nombres          AS nombresCliente,
                    cl.apellidos        AS apellidosCliente,
                    CONCAT(cl.nombres,' ',cl.apellidos) AS nombreCompletoCliente,
                    a.fechaAtencion     AS fechaAtencion
                FROM encuestasprogramadas ep
           LEFT JOIN encuestas e  ON e.idEncuesta  = ep.idEncuesta
           LEFT JOIN clientes  cl ON cl.idCliente  = ep.idCliente
           LEFT JOIN atenciones a  ON a.idAtencion = ep.idAtencion
                $where
            ORDER BY ep.fechaProgramadaInicial DESC, ep.idProgEncuesta DESC
               LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('Error al preparar: ' . $con->error); $con->close(); return false; }

        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('Error al ejecutar: ' . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close();
        $con->close();
        return $rows;
    }

    // ===== Obtener uno =====
    public function uno($idProgEncuesta)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $sql = "SELECT ep.*, e.nombre AS nombreEncuesta,
                       CONCAT(cl.nombres,' ',cl.apellidos) AS nombreCompletoCliente,
                       a.fechaAtencion
                  FROM encuestasprogramadas ep
             LEFT JOIN encuestas e  ON e.idEncuesta  = ep.idEncuesta
             LEFT JOIN clientes  cl ON cl.idCliente  = ep.idCliente
             LEFT JOIN atenciones a  ON a.idAtencion = ep.idAtencion
                 WHERE ep.idProgEncuesta = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idProgEncuesta);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close(); $con->close();
        return $row ?: null;
    }

    // ===== Insertar (opcional, normalmente se usa SP de programar) =====
    public function insertar($idEncuesta, $idAtencion, $idCliente, $fechaProgramadaInicial,
                             $maxIntentos = 3, $proximoEnvio = null, $estadoEnvio = 'PENDIENTE',
                             $canalEnvio = null, $enviadoPor = null, $observacionEnvio = null,
                             $asuntoCache = null, $cuerpoHtmlCache = null, $tokenEncuesta = null,
                             $observaciones = null, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
            $sql = 'INSERT INTO encuestasprogramadas (
                        idEncuesta, idAtencion, idCliente, fechaProgramadaInicial,
                        intentosEnviados, maxIntentos, proximoEnvio, estadoEnvio, canalEnvio,
                        enviadoPor, observacionEnvio, ultimoEnvio, asuntoCache, cuerpoHtmlCache,
                        tokenEncuesta, consentimientoAceptado, fechaConsentimiento, observaciones, estado
                    ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('iiisssssissssisi',
                $idEncuesta, $idAtencion, $idCliente, $fechaProgramadaInicial,
                $maxIntentos, $proximoEnvio, $estadoEnvio, $canalEnvio,
                $enviadoPor, $observacionEnvio, $asuntoCache, $cuerpoHtmlCache,
                $tokenEncuesta, $observaciones, $estado
            );

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $insertId;
            } else {
                $error = $stmt->error; $stmt->close(); $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // ===== Actualizar =====
    public function actualizar($idProgEncuesta, $campos = [])
    {
        // $campos: arreglo asociativo columna=>valor (solo columnas válidas)
        $permitidos = [
            'idEncuesta','idAtencion','idCliente','fechaProgramadaInicial','intentosEnviados',
            'maxIntentos','proximoEnvio','estadoEnvio','canalEnvio','enviadoPor',
            'observacionEnvio','ultimoEnvio','asuntoCache','cuerpoHtmlCache','tokenEncuesta',
            'consentimientoAceptado','fechaConsentimiento','observaciones','estado'
        ];

        $sets  = []; $types = ''; $vals = [];
        foreach ($campos as $k => $v) {
            if (!in_array($k, $permitidos, true)) continue;
            $sets[]  = " $k = ? ";
            // Tipado simple: ints vs strings
            if (in_array($k, ['idEncuesta','idAtencion','idCliente','intentosEnviados','maxIntentos','enviadoPor','consentimientoAceptado','estado'], true)) {
                $types .= 'i';
                $vals[]  = is_null($v) ? 0 : (int)$v;
            } else {
                $types .= 's';
                $vals[]  = $v;
            }
        }
        if (!count($sets)) return 0;

        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $sql = 'UPDATE encuestasprogramadas SET ' . implode(', ', $sets) . ' WHERE idProgEncuesta = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

        $types .= 'i'; $vals[] = (int)$idProgEncuesta;
        $stmt->bind_param($types, ...$vals);

        if ($stmt->execute()) {
            $aff = $stmt->affected_rows;
            $stmt->close(); $con->close();
            return $aff;
        } else {
            $err = $stmt->error; $stmt->close(); $con->close();
            return $err;
        }
    }

    // ===== Eliminar =====
    public function eliminar($idProgEncuesta)
    {
        try {
            $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
            $sql = 'DELETE FROM encuestasprogramadas WHERE idProgEncuesta = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
            $stmt->bind_param('i', $idProgEncuesta);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // FK
                    return 'La programación tiene respuestas asociadas.';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // ===== Activar/Desactivar (columna estado) =====
    public function activar($idProgEncuesta)    { return $this->cambiarEstado($idProgEncuesta, 1); }
    public function desactivar($idProgEncuesta) { return $this->cambiarEstado($idProgEncuesta, 0); }
    private function cambiarEstado($idProgEncuesta, $estado)
    {
        try {
            $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
            $sql = 'UPDATE encuestasprogramadas SET estado = ? WHERE idProgEncuesta = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
            $stmt->bind_param('ii', $estado, $idProgEncuesta);
            if ($stmt->execute()) {
                $af = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $af;
            } else {
                $err = $stmt->error; $stmt->close(); $con->close();
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // ===== Contar (para paginación) =====
    public function contar($soloActivas = null, $estadoEnvio = null, $canalEnvio = null,
                           $idEncuesta = null, $idAtencion = null, $idCliente = null, $desde = null, $hasta = null, $q = null)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivas === 1 || $soloActivas === true || $soloActivas === '1') {
            $conds[] = 'ep.estado = 1';
        } elseif ($soloActivas === 0 || $soloActivas === false || $soloActivas === '0') {
            $conds[] = 'ep.estado = 0';
        }

        $validEstados = ['PENDIENTE','ENVIADA','RESPONDIDA','NO_CONTESTADA','EXCLUIDA'];
        if (!is_null($estadoEnvio) && in_array($estadoEnvio, $validEstados, true)) {
            $conds[] = 'ep.estadoEnvio = ?';
            $types  .= 's'; $vals[] = $estadoEnvio;
        }

        $validCanales = ['EMAIL','WHATSAPP','SMS','OTRO'];
        if (!is_null($canalEnvio) && in_array($canalEnvio, $validCanales, true)) {
            $conds[] = 'ep.canalEnvio = ?';
            $types  .= 's'; $vals[] = $canalEnvio;
        }

        if (!is_null($idEncuesta) && (int)$idEncuesta > 0) { $conds[] = 'ep.idEncuesta = ?'; $types.='i'; $vals[]=(int)$idEncuesta; }
        if (!is_null($idAtencion) && (int)$idAtencion > 0) { $conds[] = 'ep.idAtencion = ?'; $types.='i'; $vals[]=(int)$idAtencion; }
        if (!is_null($idCliente) && (int)$idCliente > 0) { $conds[] = 'ep.idCliente = ?'; $types.='i'; $vals[]=(int)$idCliente; }

        if (!is_null($desde) && $desde !== '') { $conds[] = 'ep.fechaProgramadaInicial >= ?'; $types.='s'; $vals[]=$desde; }
        if (!is_null($hasta) && $hasta !== '') { $conds[] = 'ep.fechaProgramadaInicial <= ?'; $types.='s'; $vals[]=$hasta; }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(LOWER(e.nombre) LIKE LOWER(?) OR LOWER(CONCAT(cl.nombres," ",cl.apellidos)) LIKE LOWER(?))';
            $types  .= 'ss'; $like = '%' . $q . '%'; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total
                    FROM encuestasprogramadas ep
               LEFT JOIN encuestas e  ON e.idEncuesta  = ep.idEncuesta
               LEFT JOIN clientes  cl ON cl.idCliente  = ep.idCliente
                    $where";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close(); $con->close();
        return isset($row['total']) ? (int)$row['total'] : 0;
    }

    // ===== Dependencias (respuestascliente) =====
    public function dependencias($idProgEncuesta)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $out = [ 'respuestascliente' => 0 ];
        $sql = 'SELECT COUNT(*) c FROM respuestascliente WHERE idProgEncuesta = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { $out['respuestascliente'] = -1; $con->close(); return $out; }
        $stmt->bind_param('i', $idProgEncuesta);
        if ($stmt->execute()) {
            $res = $stmt->get_result(); $row = $res->fetch_assoc();
            $out['respuestascliente'] = isset($row['c']) ? (int)$row['c'] : 0;
        } else {
            $out['respuestascliente'] = -1;
        }
        $stmt->close(); $con->close();
        return $out;
    }

    // ======== WRAPPERS de Stored Procedures (si existen en la BD) ========
    public function sp_programar_por_atencion($idAtencion, $idEncuesta)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $stmt = $con->prepare('CALL sp_programar_por_atencion(?, ?)');
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('ii', $idAtencion, $idEncuesta);
        $ok = $stmt->execute();
        $err = $ok ? null : $stmt->error;
        $stmt->close(); $con->close();
        return $ok ? 1 : $err;
    }

    public function sp_programar_encuestas_por_encuesta($idEncuesta)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $stmt = $con->prepare('CALL sp_programar_encuestas_por_encuesta(?)');
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idEncuesta);
        $ok = $stmt->execute();
        $err = $ok ? null : $stmt->error;
        $stmt->close(); $con->close();
        return $ok ? 1 : $err;
    }

    public function sp_marcar_envio_manual($idProgEncuesta, $idUsuario, $canal, $observacion = '', $asunto = null, $cuerpo = null)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $stmt = $con->prepare('CALL sp_marcar_envio_manual(?, ?, ?, ?, ?, ?)');
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('iissss', $idProgEncuesta, $idUsuario, $canal, $asunto, $cuerpo, $observacion);
        $ok = $stmt->execute();
        $err = $ok ? null : $stmt->error;
        $stmt->close(); $con->close();
        return $ok ? 1 : $err;
    }

    public function sp_marcar_no_contestada($idProgEncuesta)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $stmt = $con->prepare('CALL sp_marcar_no_contestada(?)');
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idProgEncuesta);
        $ok = $stmt->execute();
        $err = $ok ? null : $stmt->error;
        $stmt->close(); $con->close();
        return $ok ? 1 : $err;
    }

    public function sp_reabrir_encuesta($idProgEncuesta, $idUsuario, $observacion = '', $reset_intentos = 0)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $stmt = $con->prepare('CALL sp_reabrir_encuesta(?, ?, ?, ?)');
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('iisi', $idProgEncuesta, $idUsuario, $observacion, $reset_intentos);
        $ok = $stmt->execute();
        $err = $ok ? null : $stmt->error;
        $stmt->close(); $con->close();
        return $ok ? 1 : $err;
    }

    public function sp_registrar_consentimiento($idProgEncuesta, $acepta, $ip, $userAgent)
    {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $stmt = $con->prepare('CALL sp_registrar_consentimiento(?, ?, ?, ?)');
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('iiss', $idProgEncuesta, $acepta, $ip, $userAgent);
        $ok = $stmt->execute();
        $err = $ok ? null : $stmt->error;
        $stmt->close(); $con->close();
        return $ok ? 1 : $err;
    }
    /**
     * SP: Generar PQRs desde respuestas de una programación
     * CALL sp_generar_pqrs_desde_respuestas(p_idprogencuesta)
     *
     * Comportamiento:
     *  - El SP puede lanzar SIGNAL (45000) con mensajes de negocio; en ese caso retornamos el texto de error.
     *  - Si todo OK, intentamos recuperar el idPqrs generado para esa programación.
     *
     * Retorna:
     *   - array("idPqrs" => int) cuando podemos identificarlo;
     *   - 1 si se ejecutó pero no hallamos el idPqrs (poco probable, pero posible);
     *   - string (mensaje de error) si falla.
     */
    public function sp_generar_pqrs_desde_respuestas($idProgEncuesta)
    {
        $idProgEncuesta = (int)$idProgEncuesta;

        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        // 1) Ejecutar el CALL
        $stmt = $con->prepare('CALL sp_generar_pqrs_desde_respuestas(?)');
        if (!$stmt) {
            $err = $con->error;
            $con->close();
            return $err;
        }

        $stmt->bind_param('i', $idProgEncuesta);

        if (!$stmt->execute()) {
            // Errores de negocio (SIGNAL 45000) también llegan aquí como texto
            $err = $stmt->error;
            $stmt->close();
            $con->close();
            return $err ?: 'Error al ejecutar sp_generar_pqrs_desde_respuestas.';
        }

        // 2) Drenar posibles resultsets intermedios del SP
        while ($con->more_results() && $con->next_result()) {
            if ($extra = $con->use_result()) { $extra->free(); }
        }
        $stmt->close();

        // 3) Intentar traer el idPqrs creado para esta programación
        $q = "SELECT idPqrs 
                FROM pqrs
               WHERE idProgEncuesta = ?
            ORDER BY idPqrs DESC
               LIMIT 1";
        $st2 = $con->prepare($q);
        if ($st2) {
            $st2->bind_param('i', $idProgEncuesta);
            if ($st2->execute()) {
                $res = $st2->get_result();
                $row = $res->fetch_assoc();
                $st2->close();
                $con->close();
                if ($row && isset($row['idPqrs'])) {
                    return ['idPqrs' => (int)$row['idPqrs']];
                }
                return 1; // SP ok, pero no hallamos id (fallback)
            } else {
                $err = $st2->error;
                $st2->close();
                $con->close();
                return $err ?: 1;
            }
        } else {
            $err = $con->error;
            $con->close();
            // No bloqueamos el éxito del SP solo por el SELECT de verificación
            return $err ?: 1;
        }
    }


}
