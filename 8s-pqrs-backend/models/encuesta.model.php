<?php
// TODO: Clase de Encuestas (plantillas)
require_once('../config/config.php');

class Encuestas
{
    // Listar encuestas (con filtros opcionales)
    public function todos($limit = 100, $offset = 0, $soloActivas = null, $idCanal = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivas === 1 || $soloActivas === true || $soloActivas === '1') {
            $conds[] = 'e.activa = 1';
        } elseif ($soloActivas === 0 || $soloActivas === false || $soloActivas === '0') {
            $conds[] = 'e.activa = 0';
        }

        if (!is_null($idCanal) && (int)$idCanal > 0) {
            $conds[] = 'e.idCanal = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCanal;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(e.nombre LIKE ? OR e.asuntoCorreo LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        // Evitar bind_param en LIMIT/OFFSET (compatibilidad MariaDB)
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT e.idEncuesta, e.nombre, e.asuntoCorreo, e.remitenteNombre, e.idCanal, e.activa,
                       e.fechaCreacion, e.fechaActualizacion, c.nombre AS nombreCanal
                  FROM encuestas e
             LEFT JOIN canales c ON c.idCanal = e.idCanal
                  $where
              ORDER BY e.fechaActualizacion DESC, e.idEncuesta DESC
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

    // Select * from encuestas where id = $idEncuesta
    public function uno($idEncuesta)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = 'SELECT e.*, c.nombre AS nombreCanal FROM encuestas e LEFT JOIN canales c ON c.idCanal = e.idCanal WHERE e.idEncuesta = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idEncuesta);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // INSERT encuestas (plantilla)
    public function insertar($nombre, $asuntoCorreo, $remitenteNombre, $scriptInicio, $scriptFinal, $idCanal, $activa = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO encuestas (nombre, asuntoCorreo, remitenteNombre, scriptInicio, scriptFinal, idCanal, activa) VALUES (?, ?, ?, ?, ?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            // Nota: $scriptFinal puede ser NULL
            $stmt->bind_param('ssssssi', $nombre, $asuntoCorreo, $remitenteNombre, $scriptInicio, $scriptFinal, $idCanal, $activa);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
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

    // UPDATE encuestas set ... where id = $idEncuesta
    public function actualizar($idEncuesta, $nombre, $asuntoCorreo, $remitenteNombre, $scriptInicio, $scriptFinal, $idCanal, $activa = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE encuestas SET nombre = ?, asuntoCorreo = ?, remitenteNombre = ?, scriptInicio = ?, scriptFinal = ?, idCanal = ?, activa = ? WHERE idEncuesta = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('sssssiii', $nombre, $asuntoCorreo, $remitenteNombre, $scriptInicio, $scriptFinal, $idCanal, $activa, $idEncuesta);

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close();
                $con->close();
                return $affected; // filas afectadas
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

    // DELETE FROM encuestas where id = $idEncuesta
    public function eliminar($idEncuesta)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM encuestas WHERE idEncuesta = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idEncuesta);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close();
                $con->close();
                return $ok; // 1 = eliminado, 0 = no existía
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // FK constraint
                    return 'La encuesta tiene dependencias (preguntas, encuestas programadas o PQRS).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Activar / Desactivar encuesta
    public function activar($idEncuesta)
    {
        return $this->cambiarEstado($idEncuesta, 1);
    }

    public function desactivar($idEncuesta)
    {
        return $this->cambiarEstado($idEncuesta, 0);
    }

    private function cambiarEstado($idEncuesta, $activa)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE encuestas SET activa = ? WHERE idEncuesta = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('ii', $activa, $idEncuesta);
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

    // Contar encuestas (para paginación)
    public function contar($soloActivas = null, $idCanal = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivas === 1 || $soloActivas === true || $soloActivas === '1') {
            $conds[] = 'activa = 1';
        } elseif ($soloActivas === 0 || $soloActivas === false || $soloActivas === '0') {
            $conds[] = 'activa = 0';
        }

        if (!is_null($idCanal) && (int)$idCanal > 0) {
            $conds[] = 'idCanal = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCanal;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(nombre LIKE ? OR asuntoCorreo LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM encuestas $where";

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

    // Chequear dependencias (preguntas, encuestasprogramadas, pqrs)
    public function dependencias($idEncuesta)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();
        $out = [
            'preguntas' => 0,
            'encuestasprogramadas' => 0,
            'pqrs' => 0
        ];

        $queries = [
            'preguntas' => 'SELECT COUNT(*) c FROM preguntas WHERE idEncuesta = ?',
            'encuestasprogramadas' => 'SELECT COUNT(*) c FROM encuestasprogramadas WHERE idEncuesta = ?',
            'pqrs' => 'SELECT COUNT(*) c FROM pqrs WHERE idEncuesta = ?'
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $con->prepare($sql);
            if (!$stmt) { $out[$key] = -1; continue; }
            $stmt->bind_param('i', $idEncuesta);
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
