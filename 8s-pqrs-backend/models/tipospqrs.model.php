<?php
// TODO: Clase de Tipos de PQRS (tabla `tipospqrs`)
require_once('../config/config.php');

class TiposPQRS
{
    // === LISTAR (con filtros opcionales) ===
    public function todos($limit = 100, $offset = 0, $soloActivos = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivos === 1 || $soloActivos === true || $soloActivos === '1') {
            $conds[] = 't.estado = 1';
        } elseif ($soloActivos === 0 || $soloActivos === false || $soloActivos === '0') {
            $conds[] = 't.estado = 0';
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(t.nombre LIKE ? OR t.descripcion LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where  = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT t.idTipo, t.nombre, t.descripcion, t.estado, t.fechaCreacion, t.fechaActualizacion
                  FROM tipospqrs t
                  $where
              ORDER BY t.fechaActualizacion DESC, t.idTipo DESC
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
    public function uno($idTipo)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();

        $sql  = 'SELECT * FROM tipospqrs WHERE idTipo = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idTipo);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // === INSERTAR ===
    public function insertar($nombre, $descripcion = null, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'INSERT INTO tipospqrs (nombre, descripcion, estado) VALUES (?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado;
            $stmt->bind_param('ssi', $nombre, $descripcion, $estado);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
            } else {
                $error = $stmt->error;
                $stmt->close();
                $con->close();
                return $error; // p.ej. Duplicate entry si nombre es UNIQUE
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // === ACTUALIZAR ===
    public function actualizar($idTipo, $nombre, $descripcion = null, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE tipospqrs SET nombre = ?, descripcion = ?, estado = ? WHERE idTipo = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idTipo = (int)$idTipo;
            $stmt->bind_param('ssii', $nombre, $descripcion, $estado, $idTipo);

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows; // filas afectadas
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

    // === ELIMINAR ===
    public function eliminar($idTipo)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'DELETE FROM tipospqrs WHERE idTipo = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idTipo);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0; // 1 = eliminado, 0 = no existía
                $stmt->close();
                $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // restricción FK
                    return 'El tipo tiene dependencias (categoriaspqrs y/o pqrs).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // === Activar / Desactivar ===
    public function activar($idTipo)    { return $this->cambiarEstado($idTipo, 1); }
    public function desactivar($idTipo) { return $this->cambiarEstado($idTipo, 0); }

    private function cambiarEstado($idTipo, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE tipospqrs SET estado = ? WHERE idTipo = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idTipo = (int)$idTipo;
            $stmt->bind_param('ii', $estado, $idTipo);

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
    public function contar($soloActivos = null, $q = null)
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

        if (!is_null($q) && $q !== '') {
            $conds[] = '(nombre LIKE ? OR descripcion LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM tipospqrs $where";

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

    // === Dependencias (categoriaspqrs, pqrs) ===
    public function dependencias($idTipo)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();
        $out = [
            'categoriaspqrs' => 0,
            'pqrs'           => 0
        ];

        $queries = [
            'categoriaspqrs' => 'SELECT COUNT(*) c FROM categoriaspqrs WHERE idTipo = ?',
            'pqrs'           => 'SELECT COUNT(*) c FROM pqrs WHERE idTipo = ?'
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $con->prepare($sql);
            if (!$stmt) { $out[$key] = -1; continue; }
            $stmt->bind_param('i', $idTipo);
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
