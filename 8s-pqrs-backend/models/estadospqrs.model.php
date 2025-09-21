<?php
// TODO: Modelo de Estados de PQRS
require_once('../config/config.php');

class EstadosPqrs
{
    // Listar estados con filtros (activo y búsqueda) y paginación
    public function todos($limit = 100, $offset = 0, $soloActivos = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivos === 1 || $soloActivos === true || $soloActivos === '1') {
            $conds[] = 'estado = 1';
        } elseif ($soloActivos === 0 || $soloActivos === false || $soloActivos === '0') {
            $conds[] = 'estado = 0';
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(nombre LIKE ?)';
            $types  .= 's';
            $like    = '%' . $q . '%';
            $vals[]  = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        // Evitar bind en LIMIT/OFFSET por compatibilidad
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT idEstado, nombre, orden, estado, fechaCreacion, fechaActualizacion
                  FROM estadospqrs
                  $where
              ORDER BY orden ASC, idEstado ASC
                 LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('EstadosPqrs::todos prepare: ' . $con->error); $con->close(); return false; }

        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('EstadosPqrs::todos exec: ' . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close();
        $con->close();
        return $rows;
    }

    // Obtener un estado por ID
    public function uno($idEstado)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = 'SELECT * FROM estadospqrs WHERE idEstado = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idEstado);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // Insertar estado
    public function insertar($nombre, $orden = 0, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO estadospqrs (nombre, orden, estado) VALUES (?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $orden = (int)$orden; $estado = (int)$estado;
            $stmt->bind_param('sii', $nombre, $orden, $estado);

            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $id;
            } else {
                $err = $stmt->error;
                $stmt->close(); $con->close();
                return $err; // p.ej. Duplicate entry: nombre UNIQUE
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualizar estado
    public function actualizar($idEstado, $nombre, $orden, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE estadospqrs SET nombre = ?, orden = ?, estado = ? WHERE idEstado = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $orden = (int)$orden; $estado = (int)$estado; $idEstado = (int)$idEstado;
            $stmt->bind_param('siii', $nombre, $orden, $estado, $idEstado);

            if ($stmt->execute()) {
                $af = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $af;
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

    // Eliminar estado (maneja FKs)
    public function eliminar($idEstado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM estadospqrs WHERE idEstado = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idEstado);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // Restricción FK (pqrs o seguimientospqrs)
                    return 'El estado tiene dependencias (PQRS o Seguimientos).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Activar / Desactivar
    public function activar($idEstado)    { return $this->cambiarEstado($idEstado, 1); }
    public function desactivar($idEstado) { return $this->cambiarEstado($idEstado, 0); }

    private function cambiarEstado($idEstado, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE estadospqrs SET estado = ? WHERE idEstado = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idEstado = (int)$idEstado;
            $stmt->bind_param('ii', $estado, $idEstado);

            if ($stmt->execute()) {
                $af = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $af;
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

    // Contar para paginación
    public function contar($soloActivos = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivos === 1 || $soloActivos === true || $soloActivos === '1') {
            $conds[] = 'estado = 1';
        } elseif ($soloActivos === 0 || $soloActivos === false || $soloActivos === '0') {
            $conds[] = 'estado = 0';
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(nombre LIKE ?)';
            $types  .= 's';
            $like    = '%' . $q . '%';
            $vals[]  = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM estadospqrs $where";

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
