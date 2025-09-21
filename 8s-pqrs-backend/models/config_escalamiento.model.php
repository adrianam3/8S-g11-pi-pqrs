<?php
// TODO: Modelo de Configuración de Escalamiento
require_once('../config/config.php');

class ConfigEscalamiento
{
    // Listar con filtros opcionales y paginación
    public function todos($limit = 100, $offset = 0, $idAgencia = null, $idEncuesta = null, $nivel = null, $estado = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idAgencia)) { $conds[] = 'c.idAgencia = ?';   $types .= 'i'; $vals[] = (int)$idAgencia; }
        if (!is_null($idEncuesta)) { $conds[] = 'c.idEncuesta = ?'; $types .= 'i'; $vals[] = (int)$idEncuesta; }
        if (!is_null($nivel))     { $conds[] = 'c.nivel = ?';       $types .= 'i'; $vals[] = (int)$nivel; }
        if ($estado === 1 || $estado === '1' || $estado === true)  { $conds[] = 'c.estado = 1'; }
        if ($estado === 0 || $estado === '0' || $estado === false) { $conds[] = 'c.estado = 0'; }

        // Búsqueda libre por nombres (agencia/encuesta) o usuario responsable
        if (!is_null($q) && $q !== '') {
            $conds[] = '(a.nombre LIKE ? OR e.nombre LIKE ? OR u.usuario LIKE ? OR CONCAT(p.nombres," ",p.apellidos) LIKE ?)';
            $types  .= 'ssss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "
            SELECT
                c.idConfig, c.idAgencia, c.idEncuesta, c.nivel, c.idResponsable, c.horasSLA, c.estado,
                c.fechaCreacion, c.fechaActualizacion,
                a.nombre     AS nombreAgencia,
                e.nombre     AS nombreEncuesta,
                u.usuario    AS responsableUsuario,
                CONCAT(COALESCE(p.nombres,''),' ',COALESCE(p.apellidos,'')) AS responsableNombre
            FROM config_escalamiento c
            LEFT JOIN agencias  a ON a.idAgencia   = c.idAgencia
            LEFT JOIN encuestas e ON e.idEncuesta  = c.idEncuesta
            LEFT JOIN usuarios  u ON u.idUsuario   = c.idResponsable
            LEFT JOIN personas  p ON p.idPersona   = u.idPersona
            $where
            ORDER BY c.idAgencia, c.idEncuesta, c.nivel
            LIMIT $limit OFFSET $offset
        ";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('Error preparar: '.$con->error); $con->close(); return false; }

        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('Error ejecutar: '.$stmt->error); $stmt->close(); $con->close(); return false; }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close();
        $con->close();
        return $rows;
    }

    // Obtener uno por ID
    public function uno($idConfig)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "
            SELECT
                c.idConfig, c.idAgencia, c.idEncuesta, c.nivel, c.idResponsable, c.horasSLA, c.estado,
                c.fechaCreacion, c.fechaActualizacion,
                a.nombre     AS nombreAgencia,
                e.nombre     AS nombreEncuesta,
                u.usuario    AS responsableUsuario,
                CONCAT(COALESCE(p.nombres,''),' ',COALESCE(p.apellidos,'')) AS responsableNombre
            FROM config_escalamiento c
            LEFT JOIN agencias  a ON a.idAgencia   = c.idAgencia
            LEFT JOIN encuestas e ON e.idEncuesta  = c.idEncuesta
            LEFT JOIN usuarios  u ON u.idUsuario   = c.idResponsable
            LEFT JOIN personas  p ON p.idPersona   = u.idPersona
            WHERE c.idConfig = ?
            LIMIT 1
        ";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idConfig);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // Insertar
    public function insertar($idAgencia, $idEncuesta, $nivel, $idResponsable, $horasSLA, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO config_escalamiento (idAgencia, idEncuesta, nivel, idResponsable, horasSLA, estado)
                    VALUES (?, ?, ?, ?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: '.$con->error; }

            $idAgencia      = (int)$idAgencia;
            $idEncuesta     = (int)$idEncuesta; // bigint en DB, int en PHP está OK en 64-bit
            $nivel          = (int)$nivel;
            $idResponsable  = (int)$idResponsable;
            $horasSLA       = (int)$horasSLA;
            $estado         = (int)$estado;

            $stmt->bind_param('iiiiii', $idAgencia, $idEncuesta, $nivel, $idResponsable, $horasSLA, $estado);

            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $id;
            } else {
                $err = $stmt->error;
                $stmt->close(); $con->close();
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualizar
    public function actualizar($idConfig, $idAgencia, $idEncuesta, $nivel, $idResponsable, $horasSLA, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE config_escalamiento
                       SET idAgencia = ?, idEncuesta = ?, nivel = ?, idResponsable = ?, horasSLA = ?, estado = ?
                     WHERE idConfig = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: '.$con->error; }

            $idAgencia      = (int)$idAgencia;
            $idEncuesta     = (int)$idEncuesta;
            $nivel          = (int)$nivel;
            $idResponsable  = (int)$idResponsable;
            $horasSLA       = (int)$horasSLA;
            $estado         = (int)$estado;
            $idConfig       = (int)$idConfig;

            $stmt->bind_param('iiiiiii', $idAgencia, $idEncuesta, $nivel, $idResponsable, $horasSLA, $estado, $idConfig);

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $affected;
            } else {
                $err = $stmt->error;
                $stmt->close(); $con->close();
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Eliminar
    public function eliminar($idConfig)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM config_escalamiento WHERE idConfig = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: '.$con->error; }

            $idConfig = (int)$idConfig;
            $stmt->bind_param('i', $idConfig);

            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) {
                    return 'No se puede eliminar: existen dependencias (FK).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Activar/Desactivar
    public function activar($idConfig)    { return $this->cambiarEstado($idConfig, 1); }
    public function desactivar($idConfig) { return $this->cambiarEstado($idConfig, 0); }

    private function cambiarEstado($idConfig, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE config_escalamiento SET estado = ? WHERE idConfig = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: '.$con->error; }

            $estado = (int)$estado; $idConfig = (int)$idConfig;
            $stmt->bind_param('ii', $estado, $idConfig);

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

    // Contar (para paginación)
    public function contar($idAgencia = null, $idEncuesta = null, $nivel = null, $estado = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idAgencia)) { $conds[] = 'c.idAgencia = ?';   $types .= 'i'; $vals[] = (int)$idAgencia; }
        if (!is_null($idEncuesta)) { $conds[] = 'c.idEncuesta = ?'; $types .= 'i'; $vals[] = (int)$idEncuesta; }
        if (!is_null($nivel))     { $conds[] = 'c.nivel = ?';       $types .= 'i'; $vals[] = (int)$nivel; }
        if ($estado === 1 || $estado === '1' || $estado === true)  { $conds[] = 'c.estado = 1'; }
        if ($estado === 0 || $estado === '0' || $estado === false) { $conds[] = 'c.estado = 0'; }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(a.nombre LIKE ? OR e.nombre LIKE ? OR u.usuario LIKE ? OR CONCAT(p.nombres," ",p.apellidos) LIKE ?)';
            $types  .= 'ssss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "
            SELECT COUNT(*) AS total
              FROM config_escalamiento c
         LEFT JOIN agencias  a ON a.idAgencia   = c.idAgencia
         LEFT JOIN encuestas e ON e.idEncuesta  = c.idEncuesta
         LEFT JOIN usuarios  u ON u.idUsuario   = c.idResponsable
         LEFT JOIN personas  p ON p.idPersona   = u.idPersona
            $where
        ";

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

    // Listar niveles por agencia+encuesta (útil para PQRS)
    public function nivelesPorEncuestaAgencia($idAgencia, $idEncuesta)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "
            SELECT idConfig, idAgencia, idEncuesta, nivel, idResponsable, horasSLA, estado,
                   fechaCreacion, fechaActualizacion
              FROM config_escalamiento
             WHERE idAgencia = ? AND idEncuesta = ?
             ORDER BY nivel ASC
        ";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }

        $idAgencia  = (int)$idAgencia;
        $idEncuesta = (int)$idEncuesta;

        $stmt->bind_param('ii', $idAgencia, $idEncuesta);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close();
        $con->close();
        return $rows;
    }
}
