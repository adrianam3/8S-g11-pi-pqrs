<?php
// TODO: Clase de Categorías PQRS (tabla `categoriaspqrs`)
require_once('../config/config.php');

class CategoriasPQRS
{
    // === LISTAR (con filtros opcionales) ===
    public function todos($limit = 100, $offset = 0, $soloActivos = null, $idCategoriaPadre = null, $idCanal = null, $idTipo = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivos === 1 || $soloActivos === true || $soloActivos === '1') {
            $conds[] = 'cpq.estado = 1';
        } elseif ($soloActivos === 0 || $soloActivos === false || $soloActivos === '0') {
            $conds[] = 'cpq.estado = 0';
        }

        if (!is_null($idCategoriaPadre) && (int)$idCategoriaPadre > 0) {
            $conds[] = 'cpq.idCategoriaPadre = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCategoriaPadre;
        }

        if (!is_null($idCanal) && (int)$idCanal > 0) {
            $conds[] = 'cpq.idCanal = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCanal;
        }

        if (!is_null($idTipo) && (int)$idTipo > 0) {
            $conds[] = 'cpq.idTipo = ?';
            $types  .= 'i';
            $vals[]  = (int)$idTipo;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(cpq.nombre LIKE ? OR cpq.descripcion LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where  = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT
                    cpq.idCategoria, cpq.nombre, cpq.descripcion,
                    cpq.idCategoriaPadre, cpq.idCanal, cpq.idTipo, cpq.estado,
                    cpq.fechaCreacion, cpq.fechaActualizacion,
                    c.nombre  AS nombreCanal,
                    p.nombre  AS nombreCategoriaPadre,
                    t.nombre  AS nombreTipo
                FROM categoriaspqrs cpq
           LEFT JOIN canales         c ON c.idCanal = cpq.idCanal
           LEFT JOIN categoriaspadre p ON p.idCategoriaPadre = cpq.idCategoriaPadre
           LEFT JOIN tipospqrs       t ON t.idTipo = cpq.idTipo
                $where
            ORDER BY cpq.fechaActualizacion DESC, cpq.idCategoria DESC
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
    public function uno($idCategoria)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();

        $sql  = 'SELECT cpq.*, c.nombre AS nombreCanal, p.nombre AS nombreCategoriaPadre, t.nombre AS nombreTipo
                 FROM categoriaspqrs cpq
                 LEFT JOIN canales c ON c.idCanal = cpq.idCanal
                 LEFT JOIN categoriaspadre p ON p.idCategoriaPadre = cpq.idCategoriaPadre
                 LEFT JOIN tipospqrs t ON t.idTipo = cpq.idTipo
                 WHERE cpq.idCategoria = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idCategoria);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // === INSERTAR ===
    public function insertar($nombre, $descripcion, $idCategoriaPadre, $idCanal, $idTipo, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'INSERT INTO categoriaspqrs (nombre, descripcion, idCategoriaPadre, idCanal, idTipo, estado) VALUES (?, ?, ?, ?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idCategoriaPadre = (int)$idCategoriaPadre; $idCanal = (int)$idCanal; $idTipo = (int)$idTipo;
            $stmt->bind_param('ssiiii', $nombre, $descripcion, $idCategoriaPadre, $idCanal, $idTipo, $estado);

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
    public function actualizar($idCategoria, $nombre, $descripcion, $idCategoriaPadre, $idCanal, $idTipo, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE categoriaspqrs SET nombre = ?, descripcion = ?, idCategoriaPadre = ?, idCanal = ?, idTipo = ?, estado = ? WHERE idCategoria = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idCategoria = (int)$idCategoria; $idCategoriaPadre = (int)$idCategoriaPadre; $idCanal = (int)$idCanal; $idTipo = (int)$idTipo;
            $stmt->bind_param('ssiiiii', $nombre, $descripcion, $idCategoriaPadre, $idCanal, $idTipo, $estado, $idCategoria);

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
    public function eliminar($idCategoria)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'DELETE FROM categoriaspqrs WHERE idCategoria = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idCategoria);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0; // 1 = eliminado, 0 = no existía
                $stmt->close();
                $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // restricción FK
                    return 'La categoría PQRS tiene dependencias (pqrs, pqrs_preguntas o respuestascliente).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // === Activar / Desactivar ===
    public function activar($idCategoria)    { return $this->cambiarEstado($idCategoria, 1); }
    public function desactivar($idCategoria) { return $this->cambiarEstado($idCategoria, 0); }

    private function cambiarEstado($idCategoria, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con    = $conObj->ProcedimientoParaConectar();

            $sql  = 'UPDATE categoriaspqrs SET estado = ? WHERE idCategoria = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idCategoria = (int)$idCategoria;
            $stmt->bind_param('ii', $estado, $idCategoria);

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
    public function contar($soloActivos = null, $idCategoriaPadre = null, $idCanal = null, $idTipo = null, $q = null)
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

        if (!is_null($idCategoriaPadre) && (int)$idCategoriaPadre > 0) {
            $conds[] = 'idCategoriaPadre = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCategoriaPadre;
        }
        if (!is_null($idCanal) && (int)$idCanal > 0) {
            $conds[] = 'idCanal = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCanal;
        }
        if (!is_null($idTipo) && (int)$idTipo > 0) {
            $conds[] = 'idTipo = ?';
            $types  .= 'i';
            $vals[]  = (int)$idTipo;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(nombre LIKE ? OR descripcion LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM categoriaspqrs $where";

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

    // === Dependencias (pqrs, pqrs_preguntas, respuestascliente) ===
    public function dependencias($idCategoria)
    {
        $conObj = new ClaseConectar();
        $con    = $conObj->ProcedimientoParaConectar();
        $out = [
            'pqrs'             => 0,
            'pqrs_preguntas'   => 0,
            'respuestascliente'=> 0
        ];

        $queries = [
            'pqrs'               => 'SELECT COUNT(*) c FROM pqrs WHERE idCategoria = ?',
            'pqrs_preguntas'     => 'SELECT COUNT(*) c FROM pqrs_preguntas WHERE idCategoria = ?',
            'respuestascliente'  => 'SELECT COUNT(*) c FROM respuestascliente WHERE idcategoria = ?'
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $con->prepare($sql);
            if (!$stmt) { $out[$key] = -1; continue; }
            $stmt->bind_param('i', $idCategoria);
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
