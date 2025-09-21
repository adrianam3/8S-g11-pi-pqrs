<?php
// TODO: Clase de Atenciones
require_once('../config/config.php');

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
                    a.idAtencion, a.idCliente, a.idAgencia, a.fechaAtencion,
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
}
