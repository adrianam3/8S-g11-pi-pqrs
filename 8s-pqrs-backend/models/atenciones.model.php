<?php
// TODO: Clase de Atenciones
require_once('../config/config.php');
// Para reutilizar el cifrado de Personas (cedulaAsesor):
require_once('../models/personas.model.php');

class Atenciones
{
    // Listar atenciones con filtros opcionales
    public function todos($limit = 100, $offset = 0, $soloActivas = null, $idCliente = null, $idAgencia = null, $fechaDesde = null, $fechaHasta = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        // estado: 1 activo, 0 inactivo
        if ($soloActivas === 1 || $soloActivas === true || $soloActivas === '1') {
            $conds[] = 'a.estado = 1';
        } elseif ($soloActivas === 0 || $soloActivas === false || $soloActivas === '0') {
            $conds[] = 'a.estado = 0';
        }

        if (!is_null($idCliente) && (int)$idCliente > 0) {
            $conds[] = 'a.idCliente = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCliente;
        }

        if (!is_null($idAgencia) && (int)$idAgencia > 0) {
            $conds[] = 'a.idAgencia = ?';
            $types  .= 'i';
            $vals[]  = (int)$idAgencia;
        }

        if (!empty($fechaDesde)) {
            $conds[] = 'a.fechaAtencion >= ?';
            $types  .= 's';
            $vals[]  = $fechaDesde;
        }

        if (!empty($fechaHasta)) {
            $conds[] = 'a.fechaAtencion <= ?';
            $types  .= 's';
            $vals[]  = $fechaHasta;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(a.numeroDocumento LIKE ? OR a.numeroFactura LIKE ? OR a.tipoDocumento LIKE ? OR c.cedula LIKE ? OR c.nombres LIKE ? OR c.apellidos LIKE ? OR ag.nombre LIKE ?)';
            $types  .= 'sssssss';
            $like    = '%' . $q . '%';
            array_push($vals, $like, $like, $like, $like, $like, $like, $like);
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        // Evitar bind_param en LIMIT/OFFSET por compatibilidad
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT 
                    a.idAtencion, a.idCliente, a.idAgencia, a.idAsesor, a.idCanal, a.fechaAtencion,
                    a.numeroDocumento, a.tipoDocumento, a.numeroFactura,
                    a.estado, a.fechaCreacion, a.fechaActualizacion,
                    c.cedula, c.nombres, c.apellidos, c.email,
                    CONCAT(c.nombres,' ',c.apellidos) AS nombreCliente,
                    ag.nombre AS nombreAgencia
                FROM atenciones a
                INNER JOIN clientes c ON c.idCliente = a.idCliente
                LEFT JOIN agencias ag ON ag.idAgencia = a.idAgencia
                $where
                ORDER BY a.fechaAtencion DESC, a.idAtencion DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('Error al preparar: ' . $con->error); $con->close(); return false; }

        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('Error al ejecutar: ' . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res  = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close(); $con->close();
        return $rows;
    }

    // Obtener una atención por ID
    public function uno($idAtencion)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT 
                    a.*, 
                    c.cedula, c.nombres, c.apellidos, c.email,
                    CONCAT(c.nombres,' ',c.apellidos) AS nombreCliente,
                    ag.nombre AS nombreAgencia
                FROM atenciones a
                INNER JOIN clientes c ON c.idCliente = a.idCliente
                LEFT JOIN agencias ag ON ag.idAgencia = a.idAgencia
                WHERE a.idAtencion = ?";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }

        $stmt->bind_param('i', $idAtencion);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close(); $con->close();
        return $row ?: null;
    }

    // Insertar
    public function insertar($idCliente, $idAgencia, $fechaAtencion, $numeroDocumento, $tipoDocumento, $numeroFactura = null, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO atenciones (idCliente, idAgencia, fechaAtencion, numeroDocumento, tipoDocumento, numeroFactura, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $idCliente = (int)$idCliente;
            $idAgencia = is_null($idAgencia) ? null : (int)$idAgencia;
            $estado    = (int)$estado;

            $stmt->bind_param('iissssi', $idCliente, $idAgencia, $fechaAtencion, $numeroDocumento, $tipoDocumento, $numeroFactura, $estado);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $insertId;
            } else {
                $error = $stmt->error;
                $stmt->close(); $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualizar
    public function actualizar($idAtencion, $idCliente, $idAgencia, $fechaAtencion, $numeroDocumento, $tipoDocumento, $numeroFactura = null, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE atenciones
                       SET idCliente = ?, idAgencia = ?, fechaAtencion = ?, numeroDocumento = ?, tipoDocumento = ?, numeroFactura = ?, estado = ?
                     WHERE idAtencion = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $idAtencion = (int)$idAtencion;
            $idCliente  = (int)$idCliente;
            $idAgencia  = is_null($idAgencia) ? null : (int)$idAgencia;
            $estado     = (int)$estado;

            $stmt->bind_param('iissssii', $idCliente, $idAgencia, $fechaAtencion, $numeroDocumento, $tipoDocumento, $numeroFactura, $estado, $idAtencion);

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $affected;
            } else {
                $error = $stmt->error;
                $stmt->close(); $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Eliminar
    public function eliminar($idAtencion)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM atenciones WHERE idAtencion = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idAtencion);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
                return $ok; // 1 = eliminado, 0 = no existía
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // FK constraint
                    return 'La atención tiene dependencias (encuestas programadas u otros registros).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Activar / Desactivar (campo estado)
    public function activar($idAtencion)    { return $this->cambiarEstado($idAtencion, 1); }
    public function desactivar($idAtencion) { return $this->cambiarEstado($idAtencion, 0); }

    private function cambiarEstado($idAtencion, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE atenciones SET estado = ? WHERE idAtencion = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idAtencion = (int)$idAtencion;
            $stmt->bind_param('ii', $estado, $idAtencion);

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

    // Contar (para paginación, mismos filtros que todos)
    public function contar($soloActivas = null, $idCliente = null, $idAgencia = null, $fechaDesde = null, $fechaHasta = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivas === 1 || $soloActivas === true || $soloActivas === '1') {
            $conds[] = 'a.estado = 1';
        } elseif ($soloActivas === 0 || $soloActivas === false || $soloActivas === '0') {
            $conds[] = 'a.estado = 0';
        }

        if (!is_null($idCliente) && (int)$idCliente > 0) {
            $conds[] = 'a.idCliente = ?'; $types .= 'i'; $vals[] = (int)$idCliente;
        }
        if (!is_null($idAgencia) && (int)$idAgencia > 0) {
            $conds[] = 'a.idAgencia = ?'; $types .= 'i'; $vals[] = (int)$idAgencia;
        }
        if (!empty($fechaDesde)) {
            $conds[] = 'a.fechaAtencion >= ?'; $types .= 's'; $vals[] = $fechaDesde;
        }
        if (!empty($fechaHasta)) {
            $conds[] = 'a.fechaAtencion <= ?'; $types .= 's'; $vals[] = $fechaHasta;
        }
        if (!is_null($q) && $q !== '') {
            $conds[] = '(a.numeroDocumento LIKE ? OR a.numeroFactura LIKE ? OR a.tipoDocumento LIKE ? OR c.cedula LIKE ? OR c.nombres LIKE ? OR c.apellidos LIKE ? OR ag.nombre LIKE ?)';
            $types  .= 'sssssss';
            $like    = '%' . $q . '%';
            array_push($vals, $like, $like, $like, $like, $like, $like, $like);
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $sql = "SELECT COUNT(*) AS total
                FROM atenciones a
                INNER JOIN clientes c ON c.idCliente = a.idCliente
                LEFT JOIN agencias ag ON ag.idAgencia = a.idAgencia
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

    // Chequear dependencias (encuestasprogramadas)
    public function dependencias($idAtencion)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();
        $out = [
            'encuestasprogramadas' => 0
        ];

        $queries = [
            'encuestasprogramadas' => 'SELECT COUNT(*) c FROM encuestasprogramadas WHERE idAtencion = ?'
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $con->prepare($sql);
            if (!$stmt) { $out[$key] = -1; continue; }
            $stmt->bind_param('i', $idAtencion);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $out[$key] = isset($row['c']) ? (int)$row['c'] : 0;
            } else {
                $out[$key] = -1;
            }
            $stmt->close();
        }

        $con->close();
        return $out;
    }

    /**
     * Llamada al procedimiento almacenado sp_upsert_cliente_y_atencion
     * Retorna ['idCliente' => int, 'idAtencion' => int, (opcional) 'idAsesor'=>int] o string con mensaje de error.
     * IMPORTANTE: p_cedula (cliente) va SIN encriptar; p_cedulaAsesor DEBE ir ENCRIPTADA (tabla personas).
     */
    public function upsertClienteYAtencion(
        $idClienteErp,
        $cedula,
        $nombres,
        $apellidos,
        $email,
        $telefono,
        $celular,
        $idAgencia,
        $fechaAtencion,
        $numeroDocumento,
        $tipoDocumento,
        $numeroFactura = null,
        $idCanal = null,
        $detalle = null,
        $cedulaAsesor = null // NUEVO
    ) {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "CALL sp_upsert_cliente_y_atencion(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $con->prepare($sql);
            if (!$stmt) {
                http_response_code(500);
                return "Error al preparar el CALL: " . $con->error;
            }

            // Normalizar nulos para ints opcionales
            $idAgencia = is_null($idAgencia) ? null : (int)$idAgencia;
            $idCanal   = is_null($idCanal)   ? null : (int)$idCanal;

            // Encriptar cedulaAsesor como en Personas (tabla personas.cedula está cifrada)
            $p = new Personas();
            $cedulaAsesorEnc = null;
            if (!is_null($cedulaAsesor) && $cedulaAsesor !== '') {
                $cedulaAsesorEnc = $p->encrypt($cedulaAsesor);
            }

            // Tipos (15 params): 7s, i, 4s, i, s, s  => 'sssssssissssiss'
            $stmt->bind_param(
                'sssssssissssiss',
                $idClienteErp,   // s
                $cedula,         // s (cliente, SIN encriptar)
                $nombres,        // s
                $apellidos,      // s
                $email,          // s
                $telefono,       // s
                $celular,        // s
                $idAgencia,      // i
                $fechaAtencion,  // s (DATE)
                $numeroDocumento,// s
                $tipoDocumento,  // s
                $numeroFactura,  // s (nullable)
                $idCanal,        // i (nullable)
                $detalle,        // s (nullable)
                $cedulaAsesorEnc // s (ENCRIPTADA o null)
            );

            // if (!$stmt->execute()) {
            //     $err = $stmt->error;
            //     $stmt->close(); $con->close();
            //     return $err ?: 'Error al ejecutar el procedimiento.';
            // }
            if (!$stmt->execute()) {
                // MySQL SIGNAL -> errno 1644; extrae mensaje legible
                $errNo  = $stmt->errno;
                $errMsg = $stmt->error;

                $stmt->close(); $con->close();

                // Normaliza mensaje cuando hay multiple resultsets y el driver no popula error
                if ($errNo === 0 && stripos($errMsg, 'No result set') !== false) {
                    $errNo = 1644;
                    $errMsg = 'Error de aplicación';
                }

    // Devuelve tal cual; el controller mapeará a 400
    return $errNo === 1644 ? $errMsg : ($errMsg ?: 'Error al ejecutar el procedimiento.');
}

            // SELECT final del SP ahora incluye idCliente, idAtencion, idAsesor
            $out = null;
            if ($res = $stmt->get_result()) {
                $out = $res->fetch_assoc();
                $res->free();
            }
            // Limpiar posibles resultsets extra
            while ($con->more_results() && $con->next_result()) {
                if ($extra = $con->use_result()) { $extra->free(); }
            }

            $stmt->close(); $con->close();

            if (is_array($out) && isset($out['idCliente']) && isset($out['idAtencion'])) {
                $payload = ['idCliente' => (int)$out['idCliente'], 'idAtencion' => (int)$out['idAtencion']];
                if (isset($out['idAsesor'])) { $payload['idAsesor'] = is_null($out['idAsesor']) ? null : (int)$out['idAsesor']; }
                return $payload;
            }
            return ['idCliente' => null, 'idAtencion' => null];

        } catch (Throwable $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    /**
     * Llamada al procedimiento sp_programar_despues_de_upsert_auto
     * IN p_idAtencion BIGINT, IN p_canalEnvio VARCHAR(16)
     * Retorna ['idProgEncuesta' => int] o string con error.
     */
    public function programarDespuesDeUpsertAuto($idAtencion, $canalEnvio)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "CALL sp_programar_despues_de_upsert_auto(?, ?)";
            $stmt = $con->prepare($sql);
            if (!$stmt) { return "Error al preparar el CALL: " . $con->error; }

            $idAtencion = (int)$idAtencion;
            $canalEnvio = strtoupper(trim((string)$canalEnvio));

            $stmt->bind_param('is', $idAtencion, $canalEnvio);

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close(); $con->close();
                return $err ?: 'Error al ejecutar sp_programar_despues_de_upsert_auto.';
            }

            $out = null;
            if ($res = $stmt->get_result()) {
                $out = $res->fetch_assoc();
                $res->free();
            }
            while ($con->more_results() && $con->next_result()) {
                if ($extra = $con->use_result()) { $extra->free(); }
            }

            $stmt->close(); $con->close();

            if (is_array($out) && isset($out['idProgEncuesta'])) {
                return ['idProgEncuesta' => (int)$out['idProgEncuesta']];
            }
            return ['idProgEncuesta' => null];

        } catch (Throwable $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }
    public function validarAsesorPorCedula($cedulaAsesor) {
        try {
            if (is_null($cedulaAsesor) || $cedulaAsesor === '') return null;

            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            // cifrar igual que en upsert
            $p = new Personas();
            $enc = $p->encrypt($cedulaAsesor);

            // misma lógica que el SP: pe.cedula = (encriptada), y resolver a usuarios
            $sql = "SELECT u.idUsuario AS idAsesor, pe.nombres, pe.apellidos, pe.email
                    FROM usuarios u
                    JOIN personas pe ON pe.idPersona = u.idPersona
                    WHERE pe.cedula = ?
                ORDER BY u.estado DESC, u.fechaActualizacion DESC, u.fechaCreacion DESC
                    LIMIT 1";

            $stmt = $con->prepare($sql);
            if (!$stmt) { $con->close(); return null; }

            $stmt->bind_param('s', $enc);
            if (!$stmt->execute()) { $stmt->close(); $con->close(); return null; }

            $res = $stmt->get_result();
            $row = $res->fetch_assoc();

            $stmt->close(); $con->close();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    
}
