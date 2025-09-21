<?php
// TODO: Modelo de Usuarios (tabla `usuarios`)
require_once('../config/config.php');

class Usuarios
{
    // ===== LOGIN por email de personas =====
    public function login($email, $password)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT u.idUsuario, u.usuario, u.password, u.descripcion, u.idPersona, u.idAgencia, u.idRol,
                       u.estado AS estadoUsuario, u.fechaCreacion, u.fechaActualizacion,
                       p.cedula, p.nombres, p.apellidos, p.email, p.celular, p.estado AS estadoPersona,
                       a.nombre AS agenciaNombre,
                       r.nombreRol AS rolNombre
                  FROM usuarios u
                  JOIN personas p ON p.idPersona = u.idPersona
             LEFT JOIN agencias a ON a.idAgencia = u.idAgencia
             LEFT JOIN roles    r ON r.idRol     = u.idRol
                 WHERE p.email = ?
                 LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log("login prepare: " . $con->error); $con->close(); return false; }

        $stmt->bind_param('s', $email);
        if (!$stmt->execute()) { error_log("login exec: " . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        $con->close();

        if (!$row) { return false; }
        if (!password_verify($password, $row['password'])) { return false; }

        unset($row['password']); // por seguridad
        return $row;
    }

    // ===== LISTAR =====
    public function todos($limit = 100, $offset = 0, $estado = null, $idRol = null, $idAgencia = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($estado === 1 || $estado === '1' || $estado === true) {
            $conds[] = 'u.estado = 1';
        } elseif ($estado === 0 || $estado === '0' || $estado === false) {
            $conds[] = 'u.estado = 0';
        }

        if (!is_null($idRol) && (int)$idRol > 0) {
            $conds[] = 'u.idRol = ?';
            $types  .= 'i';
            $vals[]  = (int)$idRol;
        }

        if (!is_null($idAgencia) && (int)$idAgencia > 0) {
            $conds[] = 'u.idAgencia = ?';
            $types  .= 'i';
            $vals[]  = (int)$idAgencia;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(u.usuario LIKE ? OR p.nombres LIKE ? OR p.apellidos LIKE ? OR p.email LIKE ? OR p.cedula LIKE ?)';
            $types  .= 'sssss';
            $like    = '%' . $q . '%';
            array_push($vals, $like, $like, $like, $like, $like);
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT u.idUsuario, u.usuario, u.descripcion, u.idPersona, u.idAgencia, u.idRol,
                       u.estado AS estadoUsuario, u.fechaCreacion, u.fechaActualizacion,
                       p.cedula, p.nombres, p.apellidos, CONCAT(p.nombres,' ',p.apellidos) AS nombreCompletoPersona,
                       p.email, p.celular,
                       a.nombre AS agenciaNombre,
                       r.nombreRol AS rolNombre
                  FROM usuarios u
                  JOIN personas p ON p.idPersona = u.idPersona
             LEFT JOIN agencias a ON a.idAgencia = u.idAgencia
             LEFT JOIN roles    r ON r.idRol     = u.idRol
                  $where
              ORDER BY u.fechaActualizacion DESC, u.idUsuario DESC
                 LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('usuarios.todos prepare: ' . $con->error); $con->close(); return false; }
        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('usuarios.todos exec: ' . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }
        $stmt->close();
        $con->close();
        return $rows;
    }

    // ===== UNO =====
    public function uno($idUsuario)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT u.idUsuario, u.usuario, u.descripcion, u.idPersona, u.idAgencia, u.idRol,
                       u.estado AS estadoUsuario, u.fechaCreacion, u.fechaActualizacion,
                       p.cedula, p.nombres, p.apellidos, p.email, p.celular, p.estado AS estadoPersona,
                       a.nombre AS agenciaNombre,
                       r.nombreRol AS rolNombre
                  FROM usuarios u
                  JOIN personas p ON p.idPersona = u.idPersona
             LEFT JOIN agencias a ON a.idAgencia = u.idAgencia
             LEFT JOIN roles    r ON r.idRol     = u.idRol
                 WHERE u.idUsuario = ?
                 LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idUsuario);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // ===== INSERTAR =====
    public function insertar($usuario, $password, $descripcion, $idPersona, $idAgencia, $idRol, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $hash = password_hash($password, PASSWORD_BCRYPT);

            $sql = "INSERT INTO usuarios (usuario, password, descripcion, idPersona, idAgencia, idRol, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado;
            $stmt->bind_param('sssiiii', $usuario, $hash, $descripcion, $idPersona, $idAgencia, $idRol, $estado);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $insertId;
            } else {
                $err = $stmt->error; $stmt->close(); $con->close();
                return $err; // Duplicate entry si usuario UNIQUE
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // ===== ACTUALIZAR (sin contraseña) =====
    public function actualizar($idUsuario, $usuario, $descripcion, $idPersona, $idAgencia, $idRol, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "UPDATE usuarios SET
                        usuario = ?,
                        descripcion = ?,
                        idPersona = ?,
                        idAgencia = ?,
                        idRol = ?,
                        estado = ?,
                        fechaActualizacion = CURRENT_TIMESTAMP
                    WHERE idUsuario = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado;
            $stmt->bind_param('ssiiiii', $usuario, $descripcion, $idPersona, $idAgencia, $idRol, $estado, $idUsuario);

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

    // ===== Cambiar contraseña por EMAIL de personas =====
    public function actualizarContrasenaPorEmail($email, $nueva)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $hash = password_hash($nueva, PASSWORD_BCRYPT);

        $sql = "UPDATE usuarios u
                   JOIN personas p ON p.idPersona = u.idPersona
                   SET u.password = ?, u.fechaActualizacion = CURRENT_TIMESTAMP
                 WHERE p.email = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('ss', $hash, $email);
        $ok = $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close(); $con->close();
        return $ok ? $af : 0;
    }

    // ===== Cambiar contraseña por ID de usuario =====
    public function actualizarContrasenaPorId($idUsuario, $nueva)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $hash = password_hash($nueva, PASSWORD_BCRYPT);

        $sql = "UPDATE usuarios SET password = ?, fechaActualizacion = CURRENT_TIMESTAMP WHERE idUsuario = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('si', $hash, $idUsuario);
        $ok = $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close(); $con->close();
        return $ok ? $af : 0;
    }

    // ===== Eliminar =====
    public function eliminar($idUsuario)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            // Verificar dependencias (seguimientospqrs)
            $q1 = "SELECT COUNT(*) c FROM seguimientospqrs WHERE idUsuario = ?";
            $s1 = $con->prepare($q1);
            if ($s1) {
                $s1->bind_param('i', $idUsuario);
                $s1->execute();
                $r = $s1->get_result()->fetch_assoc();
                $s1->close();
                if (($r['c'] ?? 0) > 0) {
                    $con->close();
                    return ['status'=>'error','message'=>'No se puede eliminar: el usuario tiene seguimientos de PQRS asociados.'];
                }
            }

            $sql = "DELETE FROM usuarios WHERE idUsuario = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
            $stmt->bind_param('i', $idUsuario);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) {
                    return ['status'=>'error','message'=>'No se puede eliminar: claves foráneas.'];
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return ['status'=>'error','message'=>$e->getMessage()];
        }
    }

    // ===== Activar / Desactivar =====
    public function activar($idUsuario)    { return $this->cambiarEstado($idUsuario, 1); }
    public function desactivar($idUsuario) { return $this->cambiarEstado($idUsuario, 0); }

    private function cambiarEstado($idUsuario, $estado)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "UPDATE usuarios SET estado = ?, fechaActualizacion = CURRENT_TIMESTAMP WHERE idUsuario = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $estado = (int)$estado;
        $stmt->bind_param('ii', $estado, $idUsuario);
        $ok = $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close(); $con->close();
        return $ok ? $af : 0;
    }

    // ===== Contar (para paginación) =====
    public function contar($estado = null, $idRol = null, $idAgencia = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($estado === 1 || $estado === '1' || $estado === true) {
            $conds[] = 'estado = 1';
        } elseif ($estado === 0 || $estado === '0' || $estado === false) {
            $conds[] = 'estado = 0';
        }

        if (!is_null($idRol) && (int)$idRol > 0) {
            $conds[] = 'idRol = ?';
            $types  .= 'i';
            $vals[]  = (int)$idRol;
        }

        if (!is_null($idAgencia) && (int)$idAgencia > 0) {
            $conds[] = 'idAgencia = ?';
            $types  .= 'i';
            $vals[]  = (int)$idAgencia;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(usuario LIKE ? OR idPersona IN (SELECT idPersona FROM personas WHERE nombres LIKE ? OR apellidos LIKE ? OR email LIKE ? OR cedula LIKE ?))';
            $types  .= 'sssss';
            $like    = '%' . $q . '%';
            array_push($vals, $like, $like, $like, $like, $like);
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM usuarios $where";

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
