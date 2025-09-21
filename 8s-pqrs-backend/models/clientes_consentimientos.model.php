<?php
// TODO: Modelo de clientes_consentimientos
require_once('../config/config.php');

class ClientesConsentimientos
{
    // Listar consentimientos con filtros
    public function todos($limit = 100, $offset = 0, $idCliente = null, $aceptado = null, $origen = null, $desde = null, $hasta = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idCliente) && (int)$idCliente > 0) {
            $conds[] = 'cc.idCliente = ?'; $types .= 'i'; $vals[] = (int)$idCliente;
        }
        if ($aceptado === 0 || $aceptado === '0' || $aceptado === false || $aceptado === 1 || $aceptado === '1' || $aceptado === true) {
            $conds[] = 'cc.aceptado = ?'; $types .= 'i'; $vals[] = (int)$aceptado;
        }
        if (!is_null($origen) && $origen !== '') {
            $conds[] = 'cc.origen = ?'; $types .= 's'; $vals[] = (string)$origen;
        }
        if (!is_null($desde) && $desde !== '') {
            $conds[] = 'cc.fecha >= ?'; $types .= 's'; $vals[] = (string)$desde;
        }
        if (!is_null($hasta) && $hasta !== '') {
            $conds[] = 'cc.fecha <= ?'; $types .= 's'; $vals[] = (string)$hasta;
        }
        if (!is_null($q) && $q !== '') {
            $conds[] = '(cl.nombres LIKE ? OR cl.apellidos LIKE ? OR cl.email LIKE ? OR cc.ip LIKE ? OR cc.userAgent LIKE ?)';
            $types  .= 'sssss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT
                    cc.id,
                    cc.idCliente,
                    cl.cedula,
                    cl.nombres,
                    cl.apellidos,
                    cl.email,
                    cc.aceptado,
                    cc.origen,
                    cc.ip,
                    cc.userAgent,
                    cc.fecha
                FROM clientes_consentimientos cc
                INNER JOIN clientes cl ON cl.idCliente = cc.idCliente
                $where
                ORDER BY cc.fecha DESC, cc.id DESC
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

    // Obtener uno por id
    public function uno($id)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT
                    cc.id,
                    cc.idCliente,
                    cl.cedula,
                    cl.nombres,
                    cl.apellidos,
                    cl.email,
                    cc.aceptado,
                    cc.origen,
                    cc.ip,
                    cc.userAgent,
                    cc.fecha
                FROM clientes_consentimientos cc
                INNER JOIN clientes cl ON cl.idCliente = cc.idCliente
                WHERE cc.id = ?";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // Último consentimiento por cliente
    public function ultimoPorCliente($idCliente)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT cc.*
                  FROM clientes_consentimientos cc
                 WHERE cc.idCliente = ?
              ORDER BY cc.fecha DESC, cc.id DESC
                 LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idCliente);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close(); $con->close();
        return $row ?: null;
    }

    // Insertar
    public function insertar($idCliente, $aceptado, $origen, $ip = null, $userAgent = null, $fecha = null)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            if (is_null($fecha) || $fecha === '') {
                $sql = 'INSERT INTO clientes_consentimientos (idCliente, aceptado, origen, ip, userAgent) VALUES (?, ?, ?, ?, ?)';
                $stmt = $con->prepare($sql);
                if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
                $aceptado = (int)$aceptado;
                $stmt->bind_param('iisss', $idCliente, $aceptado, $origen, $ip, $userAgent);
            } else {
                $sql = 'INSERT INTO clientes_consentimientos (idCliente, aceptado, origen, ip, userAgent, fecha) VALUES (?, ?, ?, ?, ?, ?)';
                $stmt = $con->prepare($sql);
                if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
                $aceptado = (int)$aceptado;
                $stmt->bind_param('iissss', $idCliente, $aceptado, $origen, $ip, $userAgent, $fecha);
            }

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
            } else {
                $error = $stmt->error;
                $stmt->close();
                $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualizar (incluye fecha opcional)
    public function actualizar($id, $idCliente, $aceptado, $origen, $ip = null, $userAgent = null, $fecha = null)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            if (is_null($fecha) || $fecha === '') {
                $sql = 'UPDATE clientes_consentimientos SET idCliente = ?, aceptado = ?, origen = ?, ip = ?, userAgent = ? WHERE id = ?';
                $stmt = $con->prepare($sql);
                if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
                $aceptado = (int)$aceptado;
                $stmt->bind_param('iisssi', $idCliente, $aceptado, $origen, $ip, $userAgent, $id);
            } else {
                $sql = 'UPDATE clientes_consentimientos SET idCliente = ?, aceptado = ?, origen = ?, ip = ?, userAgent = ?, fecha = ? WHERE id = ?';
                $stmt = $con->prepare($sql);
                if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
                $aceptado = (int)$aceptado;
                $stmt->bind_param('iissssi', $idCliente, $aceptado, $origen, $ip, $userAgent, $fecha, $id);
            }

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close();
                $con->close();
                return $affected;
            } else {
                $error = $stmt->error;
                $stmt->close();
                $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Eliminar
    public function eliminar($id)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM clientes_consentimientos WHERE id = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close();
                $con->close();
                return $ok;
            } else {
                $err = $stmt->error; $stmt->close(); $con->close();
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Contar para paginación
    public function contar($idCliente = null, $aceptado = null, $origen = null, $desde = null, $hasta = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idCliente) && (int)$idCliente > 0) {
            $conds[] = 'cc.idCliente = ?'; $types .= 'i'; $vals[] = (int)$idCliente;
        }
        if ($aceptado === 0 || $aceptado === '0' || $aceptado === false || $aceptado === 1 || $aceptado === '1' || $aceptado === true) {
            $conds[] = 'cc.aceptado = ?'; $types .= 'i'; $vals[] = (int)$aceptado;
        }
        if (!is_null($origen) && $origen !== '') {
            $conds[] = 'cc.origen = ?'; $types .= 's'; $vals[] = (string)$origen;
        }
        if (!is_null($desde) && $desde !== '') {
            $conds[] = 'cc.fecha >= ?'; $types .= 's'; $vals[] = (string)$desde;
        }
        if (!is_null($hasta) && $hasta !== '') {
            $conds[] = 'cc.fecha <= ?'; $types .= 's'; $vals[] = (string)$hasta;
        }
        if (!is_null($q) && $q !== '') {
            $conds[] = '(cl.nombres LIKE ? OR cl.apellidos LIKE ? OR cl.email LIKE ? OR cc.ip LIKE ? OR cc.userAgent LIKE ?)';
            $types  .= 'sssss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total
                    FROM clientes_consentimientos cc
                    INNER JOIN clientes cl ON cl.idCliente = cc.idCliente
                    $where";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return isset($row['total']) ? (int)$row['total'] : 0;
    }
}
