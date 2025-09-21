<?php
// TODO: Clase de Clientes (tabla `clientes`)
require_once('../config/config.php');

class Clientes
{
    // === LISTAR (con filtros opcionales) ===
    public function todos($limit = 100, $offset = 0, $soloActivos = null, $q = null, $bloqueado = null, $consentimiento = null)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        // Estado (activo / inactivo)
        if ($soloActivos === 1 || $soloActivos === true || $soloActivos === '1') {
            $conds[] = 'c.estado = 1';
        } elseif ($soloActivos === 0 || $soloActivos === false || $soloActivos === '0') {
            $conds[] = 'c.estado = 0';
        }

        // Bloqueado para encuestas
        if ($bloqueado === 1 || $bloqueado === true || $bloqueado === '1') {
            $conds[] = 'c.bloqueadoEncuestas = 1';
        } elseif ($bloqueado === 0 || $bloqueado === false || $bloqueado === '0') {
            $conds[] = 'c.bloqueadoEncuestas = 0';
        }

        // Consentimiento de datos
        if ($consentimiento === 1 || $consentimiento === true || $consentimiento === '1') {
            $conds[] = 'c.consentimientoDatos = 1';
        } elseif ($consentimiento === 0 || $consentimiento === false || $consentimiento === '0') {
            $conds[] = 'c.consentimientoDatos = 0';
        }

        // Búsqueda libre
        if (!is_null($q) && $q !== '') {
            $conds[] = '(c.cedula LIKE ? OR c.nombres LIKE ? OR c.apellidos LIKE ? OR c.email LIKE ? OR c.telefono LIKE ? OR c.celular LIKE ? OR c.idClienteErp LIKE ?)';
            $types  .= 'sssssss';
            $like    = '%' . $q . '%';
            array_push($vals, $like, $like, $like, $like, $like, $like, $like);
        }

        $where  = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT c.idCliente, c.idClienteErp, c.cedula, c.nombres, c.apellidos,
                       c.telefono, c.celular, c.email,
                       c.consentimientoDatos, c.fechaConsentimiento,
                       c.bloqueadoEncuestas, c.estado,
                       c.fechaCreacion, c.fechaActualizacion
                  FROM clientes c
                  $where
              ORDER BY c.fechaActualizacion DESC, c.idCliente DESC
                 LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('Error al preparar: ' . $con->error); $con->close(); return false; }

        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('Error al ejecutar: ' . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res  = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }
        $stmt->close();
        $con->close();
        return $rows;
    }

    // === OBTENER UNO ===
    public function uno($idCliente)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();

        $sql  = 'SELECT * FROM clientes WHERE idCliente = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idCliente);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // === INSERTAR ===
    public function insertar($idClienteErp, $cedula, $nombres, $apellidos, $telefono = null, $celular = null, $email, $consentimientoDatos = 1, $fechaConsentimiento = null, $bloqueadoEncuestas = 0, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'INSERT INTO clientes (idClienteErp, cedula, nombres, apellidos, telefono, celular, email, consentimientoDatos, fechaConsentimiento, bloqueadoEncuestas, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $consentimientoDatos = (int)$consentimientoDatos;
            $bloqueadoEncuestas  = (int)$bloqueadoEncuestas;
            $estado              = (int)$estado;

            // fechaConsentimiento puede ser NULL
            $stmt->bind_param('ssssssssiii', $idClienteErp, $cedula, $nombres, $apellidos, $telefono, $celular, $email, $fechaConsentimiento, $consentimientoDatos, $bloqueadoEncuestas, $estado);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
            } else {
                $error = $stmt->error; // p.ej. Duplicate entry (cedula UNIQUE)
                $stmt->close();
                $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // === ACTUALIZAR ===
    public function actualizar($idCliente, $idClienteErp, $cedula, $nombres, $apellidos, $telefono = null, $celular = null, $email, $consentimientoDatos = 1, $fechaConsentimiento = null, $bloqueadoEncuestas = 0, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE clientes SET idClienteErp = ?, cedula = ?, nombres = ?, apellidos = ?, telefono = ?, celular = ?, email = ?, consentimientoDatos = ?, fechaConsentimiento = ?, bloqueadoEncuestas = ?, estado = ? WHERE idCliente = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $consentimientoDatos = (int)$consentimientoDatos;
            $bloqueadoEncuestas  = (int)$bloqueadoEncuestas;
            $estado              = (int)$estado; $idCliente = (int)$idCliente;

            $stmt->bind_param('ssssssssiiii', $idClienteErp, $cedula, $nombres, $apellidos, $telefono, $celular, $email, $consentimientoDatos, $fechaConsentimiento, $bloqueadoEncuestas, $estado, $idCliente);

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close();
                $con->close();
                return $affected; // filas afectadas
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

    // === ELIMINAR ===
    public function eliminar($idCliente)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'DELETE FROM clientes WHERE idCliente = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idCliente);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0; // 1 = eliminado, 0 = no existía
                $stmt->close();
                $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // restricción FK
                    return 'El cliente tiene dependencias (atenciones, encuestas programadas, PQRS o consentimientos).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // === Activar / Desactivar ===
    public function activar($idCliente)    { return $this->cambiarEstado($idCliente, 1); }
    public function desactivar($idCliente) { return $this->cambiarEstado($idCliente, 0); }

    private function cambiarEstado($idCliente, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE clientes SET estado = ? WHERE idCliente = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idCliente = (int)$idCliente;
            $stmt->bind_param('ii', $estado, $idCliente);

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

    // === Bloquear / Desbloquear encuestas ===
    public function bloquear($idCliente)    { return $this->cambiarBloqueo($idCliente, 1); }
    public function desbloquear($idCliente)  { return $this->cambiarBloqueo($idCliente, 0); }

    private function cambiarBloqueo($idCliente, $bloqueado)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE clientes SET bloqueadoEncuestas = ? WHERE idCliente = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $bloqueado = (int)$bloqueado; $idCliente = (int)$idCliente;
            $stmt->bind_param('ii', $bloqueado, $idCliente);

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

    // === Consentir / Revocar tratamiento de datos ===
    public function consentir($idCliente)
    {
        return $this->cambiarConsentimiento($idCliente, 1, date('Y-m-d H:i:s'));
    }

    public function revocar($idCliente)
    {
        return $this->cambiarConsentimiento($idCliente, 0, null);
    }

    private function cambiarConsentimiento($idCliente, $consentimiento, $fecha)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE clientes SET consentimientoDatos = ?, fechaConsentimiento = ? WHERE idCliente = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $consentimiento = (int)$consentimiento; $idCliente = (int)$idCliente;
            $stmt->bind_param('isi', $consentimiento, $fecha, $idCliente);

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

    // === Contar (para paginación) ===
    public function contar($soloActivos = null, $q = null, $bloqueado = null, $consentimiento = null)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivos === 1 || $soloActivos === true || $soloActivos === '1') {
            $conds[] = 'estado = 1';
        } elseif ($soloActivos === 0 || $soloActivos === false || $soloActivos === '0') {
            $conds[] = 'estado = 0';
        }

        if ($bloqueado === 1 || $bloqueado === true || $bloqueado === '1') {
            $conds[] = 'bloqueadoEncuestas = 1';
        } elseif ($bloqueado === 0 || $bloqueado === false || $bloqueado === '0') {
            $conds[] = 'bloqueadoEncuestas = 0';
        }

        if ($consentimiento === 1 || $consentimiento === true || $consentimiento === '1') {
            $conds[] = 'consentimientoDatos = 1';
        } elseif ($consentimiento === 0 || $consentimiento === false || $consentimiento === '0') {
            $conds[] = 'consentimientoDatos = 0';
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(cedula LIKE ? OR nombres LIKE ? OR apellidos LIKE ? OR email LIKE ? OR telefono LIKE ? OR celular LIKE ? OR idClienteErp LIKE ?)';
            $types  .= 'sssssss';
            $like    = '%' . $q . '%';
            array_push($vals, $like, $like, $like, $like, $like, $like, $like);
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM clientes $where";

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

    // === Dependencias (FK: atenciones, encuestasprogramadas, pqrs, clientes_consentimientos) ===
    public function dependencias($idCliente)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();
        $out = [
            'atenciones' => 0,
            'encuestasprogramadas' => 0,
            'pqrs' => 0,
            'clientes_consentimientos' => 0,
        ];

        $queries = [
            'atenciones' => 'SELECT COUNT(*) c FROM atenciones WHERE idCliente = ?',
            'encuestasprogramadas' => 'SELECT COUNT(*) c FROM encuestasprogramadas WHERE idCliente = ?',
            'pqrs' => 'SELECT COUNT(*) c FROM pqrs WHERE idCliente = ?',
            'clientes_consentimientos' => 'SELECT COUNT(*) c FROM clientes_consentimientos WHERE idCliente = ?',
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $con->prepare($sql);
            if (!$stmt) { $out[$key] = -1; continue; }
            $stmt->bind_param('i', $idCliente);
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
