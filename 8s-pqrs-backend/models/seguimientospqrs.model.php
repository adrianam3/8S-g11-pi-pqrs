<?php
// TODO: Modelo de Seguimientos de PQRS
require_once('../config/config.php');

class SeguimientosPqrs
{
    /**
     * Listado con filtros opcionales
     * Filtros soportados: idPqrs, idUsuario, cambioEstado, desde, hasta, q
     * Paginación: limit, offset
     */
    public function todos(
        $limit = 100,
        $offset = 0,
        $idPqrs = null,
        $idUsuario = null,
        $cambioEstado = null,
        $desde = null,
        $hasta = null,
        $q = null
    ) {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idPqrs) && (int)$idPqrs > 0) {
            $conds[] = 's.idPqrs = ?';
            $types  .= 'i';
            $vals[]  = (int)$idPqrs;
        }

        if (!is_null($idUsuario) && (int)$idUsuario > 0) {
            $conds[] = 's.idUsuario = ?';
            $types  .= 'i';
            $vals[]  = (int)$idUsuario;
        }

        if (!is_null($cambioEstado) && (int)$cambioEstado > 0) {
            $conds[] = 's.cambioEstado = ?';
            $types  .= 'i';
            $vals[]  = (int)$cambioEstado;
        }

        if (!empty($desde)) {
            $conds[] = 's.fechaCreacion >= ?';
            $types  .= 's';
            $vals[]  = $desde;
        }
        if (!empty($hasta)) {
            $conds[] = 's.fechaCreacion <= ?';
            $types  .= 's';
            $vals[]  = $hasta;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(s.comentario LIKE ? OR s.adjuntosUrl LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT
                    s.idSeguimiento, s.idPqrs, s.idUsuario, s.comentario, s.cambioEstado, s.adjuntosUrl,
                    s.fechaCreacion, s.fechaActualizacion,
                    p.codigo AS codigoPqrs,
                    u.usuario AS usuarioLogin,
                    ep.nombre AS nombreEstado
                FROM seguimientospqrs s
                LEFT JOIN pqrs p        ON p.idPqrs = s.idPqrs
                LEFT JOIN usuarios u    ON u.idUsuario = s.idUsuario
                LEFT JOIN estadospqrs ep ON ep.idEstado = s.cambioEstado
                $where
                ORDER BY s.fechaCreacion DESC, s.idSeguimiento DESC
                LIMIT $limit OFFSET $offset";

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

    // Obtener un seguimiento por ID (incluye joins informativos)
    public function uno($idSeguimiento)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT
                    s.idSeguimiento, s.idPqrs, s.idUsuario, s.comentario, s.cambioEstado, s.adjuntosUrl,
                    s.fechaCreacion, s.fechaActualizacion,
                    p.codigo AS codigoPqrs,
                    u.usuario AS usuarioLogin,
                    ep.nombre AS nombreEstado
                FROM seguimientospqrs s
                LEFT JOIN pqrs p        ON p.idPqrs = s.idPqrs
                LEFT JOIN usuarios u    ON u.idUsuario = s.idUsuario
                LEFT JOIN estadospqrs ep ON ep.idEstado = s.cambioEstado
                WHERE s.idSeguimiento = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idSeguimiento);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // Listar por PQRS (conveniencia)
    public function porPqrs($idPqrs, $limit = 100, $offset = 0)
    {
        return $this->todos($limit, $offset, $idPqrs, null, null, null, null, null);
    }

    // Contar para paginación con los mismos filtros de todos()
    public function contar($idPqrs = null, $idUsuario = null, $cambioEstado = null, $desde = null, $hasta = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idPqrs) && (int)$idPqrs > 0) { $conds[] = 'idPqrs = ?'; $types .= 'i'; $vals[] = (int)$idPqrs; }
        if (!is_null($idUsuario) && (int)$idUsuario > 0) { $conds[] = 'idUsuario = ?'; $types .= 'i'; $vals[] = (int)$idUsuario; }
        if (!is_null($cambioEstado) && (int)$cambioEstado > 0) { $conds[] = 'cambioEstado = ?'; $types .= 'i'; $vals[] = (int)$cambioEstado; }
        if (!empty($desde)) { $conds[] = 'fechaCreacion >= ?'; $types .= 's'; $vals[] = $desde; }
        if (!empty($hasta)) { $conds[] = 'fechaCreacion <= ?'; $types .= 's'; $vals[] = $hasta; }
        if (!is_null($q) && $q !== '') { $conds[] = '(comentario LIKE ? OR adjuntosUrl LIKE ?)'; $types .= 'ss'; $like='%'.$q.'%'; $vals[]=$like; $vals[]=$like; }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM seguimientospqrs $where";

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

    // Inserción dinámica para respetar NULL en campos opcionales
    public function insertar($idPqrs, $idUsuario = null, $comentario = null, $cambioEstado = null, $adjuntosUrl = null)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $cols = ['idPqrs'];
            $vals = [$idPqrs];
            $types = 'i';

            $cols[] = 'comentario';   $types .= 's'; $vals[] = $comentario;

            if (!is_null($idUsuario))     { $cols[] = 'idUsuario';    $types .= 'i'; $vals[] = (int)$idUsuario; }
            if (!is_null($cambioEstado))  { $cols[] = 'cambioEstado'; $types .= 'i'; $vals[] = (int)$cambioEstado; }
            if (!is_null($adjuntosUrl))   { $cols[] = 'adjuntosUrl';  $types .= 's'; $vals[] = $adjuntosUrl; }

            $place = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO seguimientospqrs (" . implode(',', $cols) . ") VALUES ($place)";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param($types, ...$vals);

            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $id;
            } else {
                $err = $stmt->error;
                $stmt->close();
                $con->close();
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualizar campos (comentario, cambioEstado, adjuntosUrl, idUsuario)
    public function actualizar($idSeguimiento, $comentario = null, $cambioEstado = null, $adjuntosUrl = null, $idUsuario = null)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sets = [];
            $types = '';
            $vals  = [];

            if (!is_null($comentario))   { $sets[] = 'comentario = ?';   $types .= 's'; $vals[] = $comentario; }
            if (!is_null($cambioEstado)) { $sets[] = 'cambioEstado = ?'; $types .= 'i'; $vals[] = (int)$cambioEstado; }
            if (!is_null($adjuntosUrl))  { $sets[] = 'adjuntosUrl = ?';  $types .= 's'; $vals[] = $adjuntosUrl; }
            if (!is_null($idUsuario))    { $sets[] = 'idUsuario = ?';    $types .= 'i'; $vals[] = (int)$idUsuario; }

            if (empty($sets)) { return 0; } // nada que actualizar

            $sql = 'UPDATE seguimientospqrs SET ' . implode(', ', $sets) . ' WHERE idSeguimiento = ?';
            $types .= 'i';
            $vals[]  = (int)$idSeguimiento;

            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param($types, ...$vals);

            if ($stmt->execute()) {
                $af = $stmt->affected_rows;
                $stmt->close();
                $con->close();
                return $af;
            } else {
                $err = $stmt->error;
                $stmt->close();
                $con->close();
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Eliminar seguimiento
    public function eliminar($idSeguimiento)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM seguimientospqrs WHERE idSeguimiento = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idSeguimiento);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close();
                $con->close();
                return $ok;
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
}
