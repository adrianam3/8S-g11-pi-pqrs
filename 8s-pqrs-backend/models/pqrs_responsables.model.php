<?php
// TODO: Modelo de pqrs_responsables
require_once('../config/config.php');

class PqrsResponsables
{
    // LISTAR con filtros
    public function todos($limit = 100, $offset = 0, $idPqrs = null, $nivel = null, $idResponsable = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idPqrs) && (int)$idPqrs > 0) {
            $conds[] = 'pr.idPqrs = ?'; $types .= 'i'; $vals[] = (int)$idPqrs;
        }
        if (!is_null($nivel) && (int)$nivel > 0) {
            $conds[] = 'pr.nivel = ?'; $types .= 'i'; $vals[] = (int)$nivel;
        }
        if (!is_null($idResponsable) && (int)$idResponsable > 0) {
            $conds[] = 'pr.idResponsable = ?'; $types .= 'i'; $vals[] = (int)$idResponsable;
        }
        if (!is_null($q) && $q !== '') {
            $conds[] = '(p.codigo LIKE ? OR p.asunto LIKE ? OR pe.nombres LIKE ? OR pe.apellidos LIKE ? OR u.usuario LIKE ?)';
            $types  .= 'sssss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT
                    pr.id,
                    pr.idPqrs,
                    p.codigo AS codigoPqrs,
                    p.asunto AS asuntoPqrs,
                    pr.nivel,
                    pr.idResponsable,
                    u.usuario AS usuarioResponsable,
                    pe.nombres,
                    pe.apellidos,
                    CONCAT(pe.nombres, ' ', pe.apellidos) AS nombreCompletoResponsable,
                    pr.horasSLA
                FROM pqrs_responsables pr
                INNER JOIN pqrs p       ON p.idPqrs = pr.idPqrs
                INNER JOIN usuarios u   ON u.idUsuario = pr.idResponsable
                INNER JOIN personas pe  ON pe.idPersona = u.idPersona
                $where
                ORDER BY pr.idPqrs DESC, pr.nivel ASC, pr.id DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('Error al preparar: ' . $con->error); $con->close(); return false; }
        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('Error al ejecutar: ' . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows.append($row); }

        $stmt->close();
        $con->close();
        return $rows;
    }

    // OBTENER UNO por id
    public function uno($id)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT
                    pr.id,
                    pr.idPqrs,
                    p.codigo AS codigoPqrs,
                    p.asunto AS asuntoPqrs,
                    pr.nivel,
                    pr.idResponsable,
                    u.usuario AS usuarioResponsable,
                    pe.nombres,
                    pe.apellidos,
                    CONCAT(pe.nombres, ' ', pe.apellidos) AS nombreCompletoResponsable,
                    pr.horasSLA
                FROM pqrs_responsables pr
                INNER JOIN pqrs p       ON p.idPqrs = pr.idPqrs
                INNER JOIN usuarios u   ON u.idUsuario = pr.idResponsable
                INNER JOIN personas pe  ON pe.idPersona = u.idPersona
                WHERE pr.id = ?";

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

    // OBTENER por clave compuesta (idPqrs, nivel)
    public function obtenerPorCompuesto($idPqrs, $nivel)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT * FROM pqrs_responsables WHERE idPqrs = ? AND nivel = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('ii', $idPqrs, $nivel);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close(); $con->close();
        return $row ?: null;
    }

    // INSERTAR
    public function insertar($idPqrs, $nivel, $idResponsable, $horasSLA)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO pqrs_responsables (idPqrs, nivel, idResponsable, horasSLA) VALUES (?, ?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('iiii', $idPqrs, $nivel, $idResponsable, $horasSLA);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
            } else {
                $error = $stmt->error;
                $stmt->close();
                $con->close();
                // Si es duplicado por uq_pr (idPqrs, nivel)
                return $error;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // UPSERT por (idPqrs, nivel)
    public function upsert($idPqrs, $nivel, $idResponsable, $horasSLA)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO pqrs_responsables (idPqrs, nivel, idResponsable, horasSLA)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE idResponsable = VALUES(idResponsable), horasSLA = VALUES(horasSLA)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('iiii', $idPqrs, $nivel, $idResponsable, $horasSLA);

            if ($stmt->execute()) {
                // Recuperar id mediante select por clave compuesta
                $stmt->close();
                $sql2 = 'SELECT id FROM pqrs_responsables WHERE idPqrs = ? AND nivel = ? LIMIT 1';
                $stmt2 = $con->prepare($sql2);
                if ($stmt2) {
                    $stmt2->bind_param('ii', $idPqrs, $nivel);
                    $stmt2->execute();
                    $res = $stmt2->get_result();
                    $row = $res->fetch_assoc();
                    $stmt2->close();
                    $con->close();
                    return $row ? (int)$row['id'] : 0;
                } else {
                    $err = $con->error;
                    $con->close();
                    return $err;
                }
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

    // ACTUALIZAR por id
    public function actualizar($id, $idPqrs, $nivel, $idResponsable, $horasSLA)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE pqrs_responsables SET idPqrs = ?, nivel = ?, idResponsable = ?, horasSLA = ? WHERE id = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('iiiii', $idPqrs, $nivel, $idResponsable, $horasSLA, $id);

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

    // ELIMINAR por id
    public function eliminar($id)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM pqrs_responsables WHERE id = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close();
                $con->close();
                return $ok; // 1 = eliminado, 0 = no existía
            } else {
                $err = $stmt->error; $stmt->close(); $con->close();
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // ELIMINAR por clave compuesta
    public function eliminarPorCompuesto($idPqrs, $nivel)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM pqrs_responsables WHERE idPqrs = ? AND nivel = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('ii', $idPqrs, $nivel);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close();
                $con->close();
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

    // CONTAR (para paginación)
    public function contar($idPqrs = null, $nivel = null, $idResponsable = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idPqrs) && (int)$idPqrs > 0) {
            $conds[] = 'pr.idPqrs = ?'; $types .= 'i'; $vals[] = (int)$idPqrs;
        }
        if (!is_null($nivel) && (int)$nivel > 0) {
            $conds[] = 'pr.nivel = ?'; $types .= 'i'; $vals[] = (int)$nivel;
        }
        if (!is_null($idResponsable) && (int)$idResponsable > 0) {
            $conds[] = 'pr.idResponsable = ?'; $types .= 'i'; $vals[] = (int)$idResponsable;
        }
        if (!is_null($q) && $q !== '') {
            $conds[] = '(p.codigo LIKE ? OR p.asunto LIKE ? OR pe.nombres LIKE ? OR pe.apellidos LIKE ? OR u.usuario LIKE ?)';
            $types  .= 'sssss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $sql = "SELECT COUNT(*) AS total
                  FROM pqrs_responsables pr
                  INNER JOIN pqrs p       ON p.idPqrs = pr.idPqrs
                  INNER JOIN usuarios u   ON u.idUsuario = pr.idResponsable
                  INNER JOIN personas pe  ON pe.idPersona = u.idPersona
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

    public function activoPorPqrs(int $idPqrs) {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();
        $sql = "SELECT
                    pr.id,
                    pr.idPqrs,
                    p.codigo AS codigoPqrs,
                    p.asunto AS asuntoPqrs,
                    pr.nivel,
                    pr.idResponsable,
                    u.usuario AS usuarioResponsable,
                    pe.nombres,
                    pe.apellidos,
                    CONCAT(pe.nombres, ' ', pe.apellidos) AS nombreCompletoResponsable,
                    pr.horasSLA
                FROM pqrs_responsables pr
                INNER JOIN pqrs p      ON p.idPqrs = pr.idPqrs
                INNER JOIN usuarios u  ON u.idUsuario = pr.idResponsable
                INNER JOIN personas pe ON pe.idPersona = u.idPersona
                WHERE pr.idPqrs = ?
                AND (pr.activo = 1)
                ORDER BY pr.nivel DESC, pr.id DESC
                LIMIT 1";

        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return ["__error__" => $err]; }

        $stmt->bind_param('i', $idPqrs);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close(); $con->close();
            return ["__error__" => $err];
        }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close(); $con->close();
        return $row ?: null;
    }


    public function inactivosPorPqrs(int $idPqrs) {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT
                    pr.id,
                    pr.idPqrs,
                    p.codigo AS codigoPqrs,
                    p.asunto AS asuntoPqrs,
                    pr.nivel,
                    pr.idResponsable,
                    u.usuario AS usuarioResponsable,
                    pe.nombres,
                    pe.apellidos,
                    CONCAT(pe.nombres, ' ', pe.apellidos) AS nombreCompletoResponsable,
                    pr.horasSLA
                FROM pqrs_responsables pr
                INNER JOIN pqrs p      ON p.idPqrs = pr.idPqrs
                INNER JOIN usuarios u  ON u.idUsuario = pr.idResponsable
                INNER JOIN personas pe ON pe.idPersona = u.idPersona
                WHERE pr.idPqrs = ?
                AND pr.activo = 0
                ORDER BY pr.nivel DESC, pr.id DESC";

        $stmt = $con->prepare($sql);
        if (!$stmt) {
            $err = $con->error;
            $con->close();
            return ["__error__" => $err];
        }

        $stmt->bind_param('i', $idPqrs);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            $con->close();
            return ["__error__" => $err];
        }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        $con->close();

        // Devuelve array (vacío si no hay)
        return $rows;
    }

    public function responsablesPorPqrs(int $idPqrs, int $soloInActivos) {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        if ($soloInActivos === 1) {
            $sql = "SELECT
                        pr.id,
                        pr.idPqrs,
                        p.codigo AS codigoPqrs,
                        p.asunto AS asuntoPqrs,
                        pr.nivel,
                        pr.idResponsable,
                        u.usuario AS usuarioResponsable,
                        pe.nombres,
                        pe.apellidos,
                        CONCAT(pe.nombres, ' ', pe.apellidos) AS nombreCompletoResponsable,
                        pr.horasSLA
                    FROM pqrs_responsables pr
                    INNER JOIN pqrs p      ON p.idPqrs = pr.idPqrs
                    INNER JOIN usuarios u  ON u.idUsuario = pr.idResponsable
                    INNER JOIN personas pe ON pe.idPersona = u.idPersona
                    WHERE pr.idPqrs = ?
                    AND pr.activo = 0
                    ORDER BY pr.nivel DESC, pr.id DESC";
        } else {
            $sql = "SELECT
                        pr.id,
                        pr.idPqrs,
                        p.codigo AS codigoPqrs,
                        p.asunto AS asuntoPqrs,
                        pr.nivel,
                        pr.idResponsable,
                        u.usuario AS usuarioResponsable,
                        pe.nombres,
                        pe.apellidos,
                        CONCAT(pe.nombres, ' ', pe.apellidos) AS nombreCompletoResponsable,
                        pr.horasSLA
                    FROM pqrs_responsables pr
                    INNER JOIN pqrs p      ON p.idPqrs = pr.idPqrs
                    INNER JOIN usuarios u  ON u.idUsuario = pr.idResponsable
                    INNER JOIN personas pe ON pe.idPersona = u.idPersona
                    WHERE pr.idPqrs = ?
                    ORDER BY pr.nivel DESC, pr.id DESC";
        }

        $stmt = $con->prepare($sql);
        if (!$stmt) {
            $err = $con->error;
            $con->close();
            return ["__error__" => $err];
        }

        $stmt->bind_param('i', $idPqrs);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            $con->close();
            return ["__error__" => $err];
        }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        $con->close();

        // Devuelve array (vacío si no hay)
        return $rows;
    }

    public function desactivarOtrosResponsables(int $idPqrs, int $idResponsableMantener) {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

                $sql = "UPDATE pqrs_responsables
                SET activo = 0
                WHERE idPqrs = ?
                AND idResponsable <> ?
                AND activo = 1";
            $stmt = $con->prepare($sql);
            if (!$stmt) {
                $err = 'Error al preparar la consulta: ' . $con->error;
                $con->close();
                return $err;
            }

            $stmt->bind_param('ii', $idPqrs, $idResponsableMantener);

            if (!$stmt->execute()) {
                $err = $stmt->error ?: 'No se pudo ejecutar la actualización';
                $stmt->close();
                $con->close();
                return $err;
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();
            $con->close();
            return $afectadas; // número de filas desactivadas
        } catch (Throwable $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

/**
 * Asigna responsable de forma atómica para un PQR:
 * - Activa (activo=1) al responsable indicado
 * - Desactiva (activo=0) a todos los demás responsables del mismo PQR
 * - Todo en una misma transacción
 *
 * @param int $idPqrs
 * @param int $idResponsable
 * @return array|string  Array con detalles si OK, o string con error si falla
 */
    public function asignarResponsableAtomic(int $idPqrs, int $idResponsable) {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            // Inicia transacción
            $con->begin_transaction();

            // 1) Activar responsable indicado
            $sqlAct = "UPDATE pqrs_responsables
                        SET activo = 1
                        WHERE idPqrs = ?
                        AND idResponsable = ?";
            $stAct = $con->prepare($sqlAct);
            if (!$stAct) {
                $err = 'Error al preparar activación: ' . $con->error;
                $con->rollback(); $con->close();
                return $err;
            }
            $stAct->bind_param('ii', $idPqrs, $idResponsable);
            if (!$stAct->execute()) {
                $err = $stAct->error ?: 'No se pudo activar el responsable';
                $stAct->close(); $con->rollback(); $con->close();
                return $err;
            }
            $activatedRows = $stAct->affected_rows; // puede ser 0 si ya estaba activo o no existe el registro
            $stAct->close();

            // 2) Desactivar a los demás responsables
            $sqlDes = "UPDATE pqrs_responsables
                        SET activo = 0
                        WHERE idPqrs = ?
                        AND idResponsable <> ?
                        AND activo = 1";
            $stDes = $con->prepare($sqlDes);
            if (!$stDes) {
                $err = 'Error al preparar desactivación: ' . $con->error;
                $con->rollback(); $con->close();
                return $err;
            }
            $stDes->bind_param('ii', $idPqrs, $idResponsable);
            if (!$stDes->execute()) {
                $err = $stDes->error ?: 'No se pudo desactivar a los otros responsables';
                $stDes->close(); $con->rollback(); $con->close();
                return $err;
            }
            $deactivatedRows = $stDes->affected_rows;
            $stDes->close();

            // Commit
            $con->commit();
            $con->close();

            // Respuesta unificada
            return [
                "activated_rows"   => (int)$activatedRows,
                "deactivated_rows" => (int)$deactivatedRows
            ];
        } catch (Throwable $e) {
            if (isset($con) && $con instanceof mysqli) {
                $con->rollback();
                $con->close();
            }
            http_response_code(500);
            return $e->getMessage();
        }
    }


}
