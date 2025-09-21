<?php
// TODO: Modelo de pqrs_preguntas
require_once('../config/config.php');

class PqrsPreguntas
{
    // Listado con filtros
    public function todos($limit = 100, $offset = 0, $idPqrs = null, $idPregunta = null, $idCategoria = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idPqrs) && (int)$idPqrs > 0) {
            $conds[] = 'pp.idPqrs = ?'; $types .= 'i'; $vals[] = (int)$idPqrs;
        }
        if (!is_null($idPregunta) && (int)$idPregunta > 0) {
            $conds[] = 'pp.idPregunta = ?'; $types .= 'i'; $vals[] = (int)$idPregunta;
        }
        if (!is_null($idCategoria) && (int)$idCategoria > 0) {
            $conds[] = 'pp.idCategoria = ?'; $types .= 'i'; $vals[] = (int)$idCategoria;
        }
        if (!is_null($q) && $q !== '') {
            $conds[] = '(pr.texto LIKE ? OR pq.codigo LIKE ? OR pq.asunto LIKE ?)';
            $types  .= 'sss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT
                    pp.id,
                    pp.idPqrs,
                    pq.codigo     AS codigoPqrs,
                    pq.asunto     AS asuntoPqrs,
                    pp.idPregunta,
                    pr.texto      AS textoPregunta,
                    pp.idCategoria,
                    cp.nombre     AS nombreCategoria
                FROM pqrs_preguntas pp
                INNER JOIN pqrs pq           ON pq.idPqrs      = pp.idPqrs
                INNER JOIN preguntas pr      ON pr.idPregunta  = pp.idPregunta
                LEFT  JOIN categoriaspqrs cp ON cp.idCategoria = pp.idCategoria
                $where
                ORDER BY pp.id DESC
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

    // Obtener uno por id autoincremental
    public function uno($id)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT
                    pp.id,
                    pp.idPqrs,
                    pq.codigo     AS codigoPqrs,
                    pq.asunto     AS asuntoPqrs,
                    pp.idPregunta,
                    pr.texto      AS textoPregunta,
                    pp.idCategoria,
                    cp.nombre     AS nombreCategoria
                FROM pqrs_preguntas pp
                INNER JOIN pqrs pq           ON pq.idPqrs      = pp.idPqrs
                INNER JOIN preguntas pr      ON pr.idPregunta  = pp.idPregunta
                LEFT  JOIN categoriaspqrs cp ON cp.idCategoria = pp.idCategoria
                WHERE pp.id = ?";

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

    // Obtener por clave compuesta
    public function obtenerPorCompuesto($idPqrs, $idPregunta)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT * FROM pqrs_preguntas WHERE idPqrs = ? AND idPregunta = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('ii', $idPqrs, $idPregunta);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close(); $con->close();
        return $row ?: null;
    }

    // Insertar
    public function insertar($idPqrs, $idPregunta, $idCategoria = null)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO pqrs_preguntas (idPqrs, idPregunta, idCategoria) VALUES (?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            if (is_null($idCategoria) || $idCategoria === '') { $idCategoria = null; }
            $stmt->bind_param('iii', $idPqrs, $idPregunta, $idCategoria);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $insertId;
            } else {
                // Manejo de duplicado por unique key (idPqrs, idPregunta)
                if ($stmt->errno == 1062) {
                    $stmt->close();
                    // Obtener el id existente
                    $row = $this->obtenerPorCompuesto($idPqrs, $idPregunta);
                    if ($row && isset($row['id'])) return (int)$row['id'];
                    return 'Registro ya existe';
                }
                $error = $stmt->error;
                $stmt->close(); $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Upsert (insertar o actualizar idCategoria)
    public function upsert($idPqrs, $idPregunta, $idCategoria = null)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO pqrs_preguntas (idPqrs, idPregunta, idCategoria)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE idCategoria = VALUES(idCategoria)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            if (is_null($idCategoria) || $idCategoria === '') { $idCategoria = null; }
            $stmt->bind_param('iii', $idPqrs, $idPregunta, $idCategoria);

            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                if ($id == 0) {
                    // Ya existía: recuperar id
                    $row = $this->obtenerPorCompuesto($idPqrs, $idPregunta);
                    $id = $row ? (int)$row['id'] : 0;
                }
                $stmt->close(); $con->close();
                return $id;
            } else {
                $error = $stmt->error;
                $stmt->close(); $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualizar por id
    public function actualizar($id, $idPqrs, $idPregunta, $idCategoria = null)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE pqrs_preguntas SET idPqrs = ?, idPregunta = ?, idCategoria = ? WHERE id = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            if (is_null($idCategoria) || $idCategoria === '') { $idCategoria = null; }
            $stmt->bind_param('iiii', $idPqrs, $idPregunta, $idCategoria, $id);

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $affected;
            } else {
                // Si rompe por unique (idPqrs, idPregunta) informamos claramente
                if ($stmt->errno == 1062) {
                    $stmt->close(); $con->close();
                    return 'Ya existe una relación con ese (idPqrs, idPregunta).';
                }
                $error = $stmt->error;
                $stmt->close(); $con->close();
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Eliminar por id
    public function eliminar($id)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM pqrs_preguntas WHERE id = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
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

    // Eliminar por clave compuesta
    public function eliminarPorCompuesto($idPqrs, $idPregunta)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM pqrs_preguntas WHERE idPqrs = ? AND idPregunta = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('ii', $idPqrs, $idPregunta);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
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

    // Contar (para paginación)
    public function contar($idPqrs = null, $idPregunta = null, $idCategoria = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idPqrs) && (int)$idPqrs > 0) {
            $conds[] = 'pp.idPqrs = ?'; $types .= 'i'; $vals[] = (int)$idPqrs;
        }
        if (!is_null($idPregunta) && (int)$idPregunta > 0) {
            $conds[] = 'pp.idPregunta = ?'; $types .= 'i'; $vals[] = (int)$idPregunta;
        }
        if (!is_null($idCategoria) && (int)$idCategoria > 0) {
            $conds[] = 'pp.idCategoria = ?'; $types .= 'i'; $vals[] = (int)$idCategoria;
        }
        if (!is_null($q) && $q !== '') {
            $conds[] = '(pr.texto LIKE ? OR pq.codigo LIKE ? OR pq.asunto LIKE ?)';
            $types  .= 'sss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total
                    FROM pqrs_preguntas pp
                    INNER JOIN pqrs pq      ON pq.idPqrs     = pp.idPqrs
                    INNER JOIN preguntas pr ON pr.idPregunta = pp.idPregunta
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
