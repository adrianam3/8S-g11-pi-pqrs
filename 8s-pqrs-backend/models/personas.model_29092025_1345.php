<?php
// TODO: Clase de Personas (tabla: personas)
require_once('../config/config.php');

class Personas
{
    // === Cifrado (mantenemos tu esquema actual) ===
    public $encryption_key = "clave_segura_256bits"; // Clave secreta
    private $cipher_method = "AES-256-CBC";          // Método de cifrado
    private $iv = "1234567890123456";                // IV de 16 chars

    public function encrypt($data) {
        if ($data === null) return null;
        return openssl_encrypt((string)$data, $this->cipher_method, $this->encryption_key, 0, $this->iv);
    }
    private function decrypt($data) {
        if ($data === null) return null;
        return openssl_decrypt((string)$data, $this->cipher_method, $this->encryption_key, 0, $this->iv);
    }

    /**
     * Listado con filtros opcionales + búsqueda textual
     * @param int $limit
     * @param int $offset
     * @param null|int|bool $soloActivas 1|0|null
     * @param null|string $q texto a buscar en nombres, apellidos, email, cedula
     */
    public function todos($limit = 100, $offset = 0, $soloActivas = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $limit  = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivas === 1 || $soloActivas === true || $soloActivas === '1') {
            $conds[] = 'p.estado = 1';
        } elseif ($soloActivas === 0 || $soloActivas === false || $soloActivas === '0') {
            $conds[] = 'p.estado = 0';
        }

        if ($q !== null && $q !== '') {
            // Buscamos por nombres, apellidos, email, cedula (cifrada → no filtrable por LIKE real)
            // Nota: al estar la cédula cifrada, el LIKE sobre cedula no será efectivo.
            $conds[] = '(p.nombres LIKE ? OR p.apellidos LIKE ? OR p.email LIKE ?)';
            $types  .= 'sss';
            $like    = '%' . $q . '%';
            array_push($vals, $like, $like, $like);
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $sql = "SELECT
                    p.idPersona, p.cedula, p.nombres, p.apellidos, p.direccion,
                    p.telefono, p.extension, p.celular, p.email, p.estado,
                    p.fechaCreacion, p.fechaActualizacion,
                    CONCAT(p.nombres,' ',p.apellidos) AS personaNombreCompleto
                FROM personas p
                $where
                ORDER BY p.idPersona DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return "Error preparar: $err"; }

        if ($types !== '') { $stmt->bind_param($types, ...$vals); }

        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return "Error ejecutar: $err"; }

        $res  = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            // Desencriptar campos sensibles
            $row['cedula']  = $this->decrypt($row['cedula']);
            $row['telefono']= $this->decrypt($row['telefono']);
            $row['celular'] = $this->decrypt($row['celular']);
            $rows[] = $row;
        }

        $stmt->close(); $con->close();
        return $rows;
    }

    /**
     * Personas que NO tienen usuario asociado
     */
    public function todossinusuario()
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT p.*
                  FROM personas p
             LEFT JOIN usuarios u ON u.idPersona = p.idPersona
                 WHERE u.idPersona IS NULL";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return "Error preparar: $err"; }
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return "Error ejecutar: $err"; }

        $res  = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $row['cedula']   = $this->decrypt($row['cedula']);
            $row['telefono'] = $this->decrypt($row['telefono']);
            $row['celular']  = $this->decrypt($row['celular']);
            $row['personaNombreCompleto'] = $row['nombres'] . ' ' . $row['apellidos'];
            $rows[] = $row;
        }

        $stmt->close(); $con->close();
        return $rows;
    }

    /**
     * Personas por rol (retorna email y nombre completo)
     */
    public function todosByRol($idRol)
    {
        $idRol = (int)$idRol;
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT p.email,
                       CONCAT(p.nombres,' ',p.apellidos) AS nombreCompleto
                  FROM personas p
             LEFT JOIN usuarios u ON u.idPersona = p.idPersona
             LEFT JOIN roles r    ON r.idRol     = u.idRol
                 WHERE u.idRol = ?";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return "Error preparar: $err"; }
        $stmt->bind_param('i', $idRol);

        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return "Error ejecutar: $err"; }

        $res  = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close(); $con->close();
        return $rows;
    }

    /**
     * Retorna idPersona por email (o null si no existe)
     */
    public function personaByEmail($email)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT p.idPersona FROM personas p WHERE p.email = ? LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return "Error preparar: $err"; }
        $stmt->bind_param('s', $email);

        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return "Error ejecutar: $err"; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close(); $con->close();
        return $row ? (int)$row['idPersona'] : null;
    }

    /**
     * Una persona por id
     */
    public function uno($idPersona)
    {
        $idPersona = (int)$idPersona;
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT p.*,
                       CONCAT(p.nombres,' ',p.apellidos) AS personaNombreCompleto
                  FROM personas p
                 WHERE p.idPersona = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return "Error preparar: $err"; }
        $stmt->bind_param('i', $idPersona);

        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return "Error ejecutar: $err"; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close(); $con->close();

        if ($row) {
            $row['cedula']   = $this->decrypt($row['cedula']);
            $row['telefono'] = $this->decrypt($row['telefono']);
            $row['celular']  = $this->decrypt($row['celular']);
            return $row;
        }
        return null;
    }

    /**
     * Insertar persona
     */
    public function insertar($cedula, $nombres, $apellidos, $direccion, $telefono, $extension, $celular, $email, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $cedula_enc  = $this->encrypt($cedula);
            $telefono_enc= $this->encrypt($telefono);
            $celular_enc = $this->encrypt($celular);

            $sql = "INSERT INTO personas
                        (cedula, nombres, apellidos, direccion, telefono, extension, celular, email, estado)
                    VALUES (?,?,?,?,?,?,?,?,?)";
            $stmt = $con->prepare($sql);
            if (!$stmt) { throw new Exception("Error preparar: " . $con->error); }

            // estado puede ser int o string; lo enviamos como s para mantener consistencia de tipos
            $stmt->bind_param(
                'sssssssss',
                $cedula_enc,
                $nombres,
                $apellidos,
                $direccion,
                $telefono_enc,
                $extension,
                $celular_enc,
                $email,
                $estado
            );

            if (!$stmt->execute()) { throw new Exception("Error ejecutar: " . $stmt->error); }

            $id = $stmt->insert_id;
            $stmt->close(); $con->close();
            return (int)$id;

        } catch (Exception $e) {
            http_response_code(500);
            return "Error: " . $e->getMessage();
        }
    }

    /**
     * Actualizar persona
     */
    public function actualizar($idPersona, $cedula, $nombres, $apellidos, $direccion, $telefono, $extension, $celular, $email, $estado)
    {
        try {
            $idPersona = (int)$idPersona;

            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $cedula_enc  = $this->encrypt($cedula);
            $telefono_enc= $this->encrypt($telefono);
            $celular_enc = $this->encrypt($celular);

            $sql = "UPDATE personas
                       SET cedula = ?,
                           nombres = ?,
                           apellidos = ?,
                           direccion = ?,
                           telefono = ?,
                           extension = ?,
                           celular = ?,
                           email = ?,
                           estado = ?,
                           fechaActualizacion = CURRENT_TIMESTAMP
                     WHERE idPersona = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) { throw new Exception("Error preparar: " . $con->error); }

            $stmt->bind_param(
                'sssssssssi',
                $cedula_enc,
                $nombres,
                $apellidos,
                $direccion,
                $telefono_enc,
                $extension,
                $celular_enc,
                $email,
                $estado,
                $idPersona
            );

            if (!$stmt->execute()) { throw new Exception("Error ejecutar: " . $stmt->error); }

            $affected = $stmt->affected_rows;
            $stmt->close(); $con->close();
            return (int)$affected;

        } catch (Exception $e) {
            http_response_code(500);
            return "Error: " . $e->getMessage();
        }
    }

    /**
     * Eliminar persona (valida vínculo con usuarios)
     * Retorna 1 si elimina, array(status,error) si no puede, o string de error técnico.
     */
    public function eliminar($idPersona)
    {
        try {
            $idPersona = (int)$idPersona;

            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            // ¿Tiene usuarios vinculados?
            $sqlChk = "SELECT COUNT(*) AS total FROM usuarios WHERE idPersona = ?";
            $stChk  = $con->prepare($sqlChk);
            if (!$stChk) { $err = $con->error; $con->close(); return "Error preparar: $err"; }
            $stChk->bind_param('i', $idPersona);
            if (!$stChk->execute()) { $err = $stChk->error; $stChk->close(); $con->close(); return "Error ejecutar: $err"; }
            $res = $stChk->get_result();
            $row = $res->fetch_assoc();
            $stChk->close();

            if ($row && (int)$row['total'] > 0) {
                $con->close();
                return [
                    'status'  => 'error',
                    'message' => 'No se puede eliminar la persona: está vinculada con usuarios.'
                ];
            }

            // Eliminar
            $sqlDel = "DELETE FROM personas WHERE idPersona = ?";
            $stDel  = $con->prepare($sqlDel);
            if (!$stDel) { $err = $con->error; $con->close(); return "Error preparar: $err"; }
            $stDel->bind_param('i', $idPersona);

            if (!$stDel->execute()) {
                $err = $stDel->error;
                $stDel->close(); $con->close();
                return [
                    'status'  => 'error',
                    'message' => 'Error al intentar eliminar la Persona: ' . $err
                ];
            }

            $ok = $stDel->affected_rows > 0 ? 1 : 0;
            $stDel->close(); $con->close();
            return $ok;

        } catch (Exception $e) {
            return [
                'status'  => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Actualizar perfil (solo nombres, apellidos, teléfono)
     */
    public function actualizarPerfil($idPersona, $nombres, $apellidos, $telefono)
    {
        try {
            $idPersona = (int)$idPersona;

            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $telefono_enc = $this->encrypt($telefono);

            $sql = "UPDATE personas
                       SET nombres = ?,
                           apellidos = ?,
                           telefono = ?,
                           fechaActualizacion = CURRENT_TIMESTAMP
                     WHERE idPersona = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) { throw new Exception("Error preparar: " . $con->error); }

            $stmt->bind_param('sssi', $nombres, $apellidos, $telefono_enc, $idPersona);

            if (!$stmt->execute()) { throw new Exception("Error ejecutar: " . $stmt->error); }

            $stmt->close(); $con->close();
            return [
                "status"  => "success",
                "message" => "Perfil actualizado correctamente"
            ];

        } catch (Exception $e) {
            http_response_code(500);
            return [
                "status"  => "error",
                "message" => $e->getMessage()
            ];
        }
    }
}
