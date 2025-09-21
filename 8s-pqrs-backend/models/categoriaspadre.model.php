<?php
// TODO: Clase de Categorías Padre (tabla `categoriaspadre`)
require_once('../config/config.php');

class CategoriasPadre
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
            $conds[] = 'cp.estado = 1';
        } elseif ($soloActivos === 0 || $soloActivos === false || $soloActivos === '0') {
            $conds[] = 'cp.estado = 0';
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(cp.nombre LIKE ?)';
            $types  .= 's';
            $like    = '%' . $q . '%';
            $vals[]  = $like;
        }

        $where  = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT cp.idCategoriaPadre, cp.nombre, cp.estado, cp.fechaCreacion, cp.fechaActualizacion
                  FROM categoriaspadre cp
                  $where
              ORDER BY cp.fechaActualizacion DESC, cp.idCategoriaPadre DESC
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
    public function uno($idCategoriaPadre)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();

        $sql  = 'SELECT * FROM categoriaspadre WHERE idCategoriaPadre = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idCategoriaPadre);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // === INSERTAR ===
    public function insertar($nombre, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'INSERT INTO categoriaspadre (nombre, estado) VALUES (?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado;
            $stmt->bind_param('si', $nombre, $estado);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
            } else {
                $error = $stmt->error;
                $stmt->close();
                $con->close();
                return $error; // Duplicate entry si nombre es UNIQUE
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // === ACTUALIZAR ===
    public function actualizar($idCategoriaPadre, $nombre, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE categoriaspadre SET nombre = ?, estado = ? WHERE idCategoriaPadre = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idCategoriaPadre = (int)$idCategoriaPadre;
            $stmt->bind_param('sii', $nombre, $estado, $idCategoriaPadre);

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
    public function eliminar($idCategoriaPadre)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'DELETE FROM categoriaspadre WHERE idCategoriaPadre = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idCategoriaPadre);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0; // 1 = eliminado, 0 = no existía
                $stmt->close();
                $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // restricción FK
                    return 'La categoría padre tiene dependencias (categorías PQRS u otros registros).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // === Activar / Desactivar ===
    public function activar($idCategoriaPadre)    { return $this->cambiarEstado($idCategoriaPadre, 1); }
    public function desactivar($idCategoriaPadre) { return $this->cambiarEstado($idCategoriaPadre, 0); }

    private function cambiarEstado($idCategoriaPadre, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE categoriaspadre SET estado = ? WHERE idCategoriaPadre = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idCategoriaPadre = (int)$idCategoriaPadre;
            $stmt->bind_param('ii', $estado, $idCategoriaPadre);

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
            $conds[] = '(nombre LIKE ?)';
            $types  .= 's';
            $like    = '%' . $q . '%';
            $vals[]  = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM categoriaspadre $where";

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

    // === Dependencias (p.ej., categorías hijas) ===
    public function dependencias($idCategoriaPadre)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();
        $out = [
            'categoriaspqrs' => 0
        ];

        // Ajusta el nombre de la columna FK si tu esquema usa otro (p.ej.: idPadre)
        $queries = [
            'categoriaspqrs' => 'SELECT COUNT(*) c FROM categoriaspqrs WHERE idCategoriaPadre = ?'
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $con->prepare($sql);
            if (!$stmt) { $out[$key] = -1; continue; }
            $stmt->bind_param('i', $idCategoriaPadre);
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
