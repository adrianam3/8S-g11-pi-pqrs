<?php
// TODO: Modelo de Usuarios (ajustado al esquema actual)
require_once('../config/config.php');

class Usuario
{
    // Devuelve lista de usuarios con datos de persona, agencia y rol
    public function todos(): array
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT 
                    u.idUsuario,
                    u.usuario,
                    u.password,
                    u.descripcion,
                    u.idPersona,
                    u.idAgencia,
                    u.idRol,
                    u.estadoUsuario,
                    u.fechaCreacion,
                    u.fechaActualizacion,
                    p.cedula,
                    p.nombres,
                    p.apellidos,
                    CONCAT(p.nombres, ' ', p.apellidos) AS nombreCompletoPersona,
                    p.email,
                    p.celular,
                    a.nombre AS agenciaNombre,
                    r.nombre AS rolNombre
                FROM usuarios u
                INNER JOIN personas p ON p.idPersona = u.idPersona
                LEFT JOIN agencias a  ON a.idAgencia = u.idAgencia
                LEFT JOIN roles r     ON r.idRol     = u.idRol
                ORDER BY u.idUsuario DESC";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('Usuario::todos prepare: '.$con->error); $con->close(); return []; }
        if (!$stmt->execute()) { error_log('Usuario::todos exec: '.$stmt->error); $stmt->close(); $con->close(); return []; }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close();
        $con->close();
        return $rows;
    }

    // Obtiene un usuario por ID
    public function uno(int $idUsuario)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT 
                    u.idUsuario,
                    u.usuario,
                    u.password,
                    u.descripcion,
                    u.idPersona,
                    u.idAgencia,
                    u.idRol,
                    u.estadoUsuario,
                    u.fechaCreacion,
                    u.fechaActualizacion,
                    p.cedula,
                    p.nombres,
                    p.apellidos,
                    CONCAT(p.nombres, ' ', p.apellidos) AS nombreCompletoPersona,
                    p.email,
                    p.celular,
                    a.nombre AS agenciaNombre,
                    r.nombre AS rolNombre
                FROM usuarios u
                INNER JOIN personas p ON p.idPersona = u.idPersona
                LEFT JOIN agencias a  ON a.idAgencia = u.idAgencia
                LEFT JOIN roles r     ON r.idRol     = u.idRol
                WHERE u.idUsuario = ?
                LIMIT 1";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return ['error'=>$err]; }
        $stmt->bind_param('i', $idUsuario);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return ['error'=>$err]; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // Inserta un usuario
    public function insertar($usuario, $password, $descripcion, $idPersona, $idAgencia, $idRol, $estadoUsuario = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $hash = password_hash($password, PASSWORD_BCRYPT);

            $sql = "INSERT INTO usuarios (usuario, password, descripcion, idPersona, idAgencia, idRol, estadoUsuario) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar: '.$con->error; }

            $estadoUsuario = (int)$estadoUsuario;
            $stmt->bind_param('sssiiii', $usuario, $hash, $descripcion, $idPersona, $idAgencia, $idRol, $estadoUsuario);

            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $id;
            } else {
                $err = $stmt->error; $stmt->close(); $con->close();
                return $err;
            }
        } catch (Throwable $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualiza datos (no contraseña)
    public function actualizar($idUsuario, $usuario, $descripcion, $idPersona, $idAgencia, $idRol, $estadoUsuario)
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
                        estadoUsuario = ?, 
                        fechaActualizacion = CURRENT_TIMESTAMP
                    WHERE idUsuario = ?";

            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar: '.$con->error; }

            $stmt->bind_param('ssiiiii', $usuario, $descripcion, $idPersona, $idAgencia, $idRol, $estadoUsuario, $idUsuario);

            if ($stmt->execute()) {
                $af = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $af;
            } else {
                $err = $stmt->error; $stmt->close(); $con->close();
                return $err;
            }
        } catch (Throwable $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Cambiar contraseña por email de persona
    public function actualizarcontrasena($password, $email)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $sql = "UPDATE usuarios u
                    INNER JOIN personas p ON p.idPersona = u.idPersona
                    SET u.password = ?
                    WHERE p.email = ?";

            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar: '.$con->error; }
            $stmt->bind_param('ss', $hash, $email);

            if ($stmt->execute()) {
                $af = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $af;
            } else {
                $err = $stmt->error; $stmt->close(); $con->close();
                return $err;
            }
        } catch (Throwable $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Eliminar usuario (manejo de FK)
    public function eliminar($idUsuario)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "DELETE FROM usuarios WHERE idUsuario = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar: '.$con->error; }
            $stmt->bind_param('i', $idUsuario);

            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) {
                    return 'No se puede eliminar: el usuario tiene dependencias.';
                }
                return $err;
            }
        } catch (Throwable $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Login por email (tabla personas) + verificación de contraseña
    public function login($email, $password)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT u.*, p.* 
                FROM usuarios u
                INNER JOIN personas p ON p.idPersona = u.idPersona
                WHERE p.email = ?
                LIMIT 1";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('Usuario::login prepare: '.$con->error); return false; }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close(); $con->close();

        if ($row && isset($row['password']) && password_verify($password, $row['password'])) {
            unset($row['password']); // opcional
            return $row;
        }
        return false;
    }
}
