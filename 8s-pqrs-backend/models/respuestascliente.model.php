<?php
// TODO: Modelo de Respuestas del Cliente
require_once('../config/config.php');

class RespuestasCliente
{
    // Listar respuestas con filtros opcionales y paginación
    public function todos($limit = 100, $offset = 0, $soloActivos = null, $idProgEncuesta = null, $idPregunta = null, $generaPqr = null, $idCategoria = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if ($soloActivos === 1 || $soloActivos === true || $soloActivos === '1') {
            $conds[] = 'r.estado = 1';
        } elseif ($soloActivos === 0 || $soloActivos === false || $soloActivos === '0') {
            $conds[] = 'r.estado = 0';
        }

        if (!is_null($idProgEncuesta) && (int)$idProgEncuesta > 0) {
            $conds[] = 'r.idProgEncuesta = ?';
            $types  .= 'i';
            $vals[]  = (int)$idProgEncuesta;
        }

        if (!is_null($idPregunta) && (int)$idPregunta > 0) {
            $conds[] = 'r.idPregunta = ?';
            $types  .= 'i';
            $vals[]  = (int)$idPregunta;
        }

        if (!is_null($generaPqr) && $generaPqr !== '') {
            $conds[] = 'r.generaPqr = ?';
            $types  .= 'i';
            $vals[]  = (int)$generaPqr;
        }

        if (!is_null($idCategoria) && (int)$idCategoria > 0) {
            $conds[] = 'r.idcategoria = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCategoria;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(r.valorTexto LIKE ? OR r.comentario LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT 
                    r.idRespCliente, r.idProgEncuesta, r.idPregunta, r.idOpcion,
                    r.valorNumerico, r.valorTexto, r.comentario, r.generaPqr, r.idcategoria,
                    r.fechaRespuesta, r.estado,
                    p.texto AS textoPregunta, p.tipo AS tipoPregunta, p.generaPqr AS preguntaGeneraPqr,
                    ro.etiqueta AS opcionEtiqueta, ro.valorNumerico AS opcionValor,
                    ep.idEncuesta, ep.idAtencion, ep.idCliente, ep.estadoEnvio
                FROM respuestascliente r
                INNER JOIN preguntas p       ON p.idPregunta = r.idPregunta
                LEFT  JOIN respuestasopciones ro ON ro.idOpcion = r.idOpcion
                INNER JOIN encuestasprogramadas ep ON ep.idProgEncuesta = r.idProgEncuesta
                $where
                ORDER BY r.fechaRespuesta DESC, r.idRespCliente DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('RespuestasCliente::todos prepare: ' . $con->error); $con->close(); return false; }

        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('RespuestasCliente::todos exec: ' . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close();
        $con->close();
        return $rows;
    }

    // Obtener una respuesta por ID
    public function uno($idRespCliente)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT 
                    r.*, p.texto AS textoPregunta, p.tipo AS tipoPregunta,
                    ro.etiqueta AS opcionEtiqueta, ro.valorNumerico AS opcionValor
                FROM respuestascliente r
                INNER JOIN preguntas p ON p.idPregunta = r.idPregunta
                LEFT  JOIN respuestasopciones ro ON ro.idOpcion = r.idOpcion
                WHERE r.idRespCliente = ?";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idRespCliente);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // Insertar respuesta (puede fallar si existe ya el par unico idProgEncuesta+idPregunta)
    public function insertar($idProgEncuesta, $idPregunta, $idOpcion = null, $valorNumerico = null, $valorTexto = null, $comentario = null, $generaPqr = null, $idcategoria = null, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "INSERT INTO respuestascliente
                    (idProgEncuesta, idPregunta, idOpcion, valorNumerico, valorTexto, comentario, generaPqr, idcategoria, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar: ' . $con->error; }

            $stmt->bind_param('iiiiissii',
                $idProgEncuesta,
                $idPregunta,
                $idOpcion,
                $valorNumerico,
                $valorTexto,
                $comentario,
                $generaPqr,
                $idcategoria,
                $estado
            );

            if ($stmt->execute()) {
                $id = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $id;
            } else {
                $err = $stmt->error;
                $stmt->close(); $con->close();
                return $err; // p.ej. Duplicate entry por UNIQUE (idProgEncuesta,idPregunta)
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Insertar o actualizar (respetando la UNIQUE (idProgEncuesta, idPregunta))
    public function upsert($idProgEncuesta, $idPregunta, $idOpcion = null, $valorNumerico = null, $valorTexto = null, $comentario = null, $generaPqr = null, $idcategoria = null, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "INSERT INTO respuestascliente
                        (idProgEncuesta, idPregunta, idOpcion, valorNumerico, valorTexto, comentario, generaPqr, idcategoria, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        idOpcion = VALUES(idOpcion),
                        valorNumerico = VALUES(valorNumerico),
                        valorTexto = VALUES(valorTexto),
                        comentario = VALUES(comentario),
                        generaPqr = VALUES(generaPqr),
                        idcategoria = VALUES(idcategoria),
                        estado = VALUES(estado),
                        fechaRespuesta = CURRENT_TIMESTAMP";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar: ' . $con->error; }

            $stmt->bind_param('iiiiissii',
                $idProgEncuesta,
                $idPregunta,
                $idOpcion,
                $valorNumerico,
                $valorTexto,
                $comentario,
                $generaPqr,
                $idcategoria,
                $estado
            );

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();

                // Si fue UPDATE por duplicate, insert_id será 0. Buscar el ID existente.
                if ($insertId > 0) {
                    $con->close();
                    return $insertId;
                } else {
                    $idExistente = $this->getIdByProgPregunta($idProgEncuesta, $idPregunta);
                    $con->close();
                    return $idExistente ?: 0;
                }
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

    // Actualizar respuesta
    public function actualizar($idRespCliente, $idOpcion = null, $valorNumerico = null, $valorTexto = null, $comentario = null, $generaPqr = null, $idcategoria = null, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "UPDATE respuestascliente SET
                        idOpcion = ?,
                        valorNumerico = ?,
                        valorTexto = ?,
                        comentario = ?,
                        generaPqr = ?,
                        idcategoria = ?,
                        estado = ?
                    WHERE idRespCliente = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar: ' . $con->error; }

            $stmt->bind_param('iissiiii',
                $idOpcion,
                $valorNumerico,
                $valorTexto,
                $comentario,
                $generaPqr,
                $idcategoria,
                $estado,
                $idRespCliente
            );

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

    // Eliminar respuesta
    public function eliminar($idRespCliente)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM respuestascliente WHERE idRespCliente = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idRespCliente);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // Restricción FK
                    return 'La respuesta tiene dependencias y no puede eliminarse.';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Activar / Desactivar
    public function activar($idRespCliente)    { return $this->cambiarEstado($idRespCliente, 1); }
    public function desactivar($idRespCliente) { return $this->cambiarEstado($idRespCliente, 0); }

    private function cambiarEstado($idRespCliente, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE respuestascliente SET estado = ? WHERE idRespCliente = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idRespCliente = (int)$idRespCliente;
            $stmt->bind_param('ii', $estado, $idRespCliente);

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

    // Contar para paginación (mismos filtros que todos)
    public function contar($soloActivos = null, $idProgEncuesta = null, $idPregunta = null, $generaPqr = null, $idCategoria = null, $q = null)
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

        if (!is_null($idProgEncuesta) && (int)$idProgEncuesta > 0) {
            $conds[] = 'idProgEncuesta = ?';
            $types  .= 'i';
            $vals[]  = (int)$idProgEncuesta;
        }

        if (!is_null($idPregunta) && (int)$idPregunta > 0) {
            $conds[] = 'idPregunta = ?';
            $types  .= 'i';
            $vals[]  = (int)$idPregunta;
        }

        if (!is_null($generaPqr) && $generaPqr !== '') {
            $conds[] = 'generaPqr = ?';
            $types  .= 'i';
            $vals[]  = (int)$generaPqr;
        }

        if (!is_null($idCategoria) && (int)$idCategoria > 0) {
            $conds[] = 'idcategoria = ?';
            $types  .= 'i';
            $vals[]  = (int)$idCategoria;
        }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(valorTexto LIKE ? OR comentario LIKE ?)';
            $types  .= 'ss';
            $like    = '%' . $q . '%';
            $vals[]  = $like; $vals[] = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM respuestascliente $where";

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

    // Listar por programación de encuesta (idProgEncuesta)
    public function porProgramacion($idProgEncuesta)
    {
        return $this->todos(1000, 0, null, $idProgEncuesta, null, null, null, null);
    }

    // === Helpers internos ===
    private function getIdByProgPregunta($idProgEncuesta, $idPregunta)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();
        $sql = "SELECT idRespCliente FROM respuestascliente WHERE idProgEncuesta = ? AND idPregunta = ? LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return null; }
        $stmt->bind_param('ii', $idProgEncuesta, $idPregunta);
        if (!$stmt->execute()) { $stmt->close(); $con->close(); return null; }
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close(); $con->close();
        return $row ? (int)$row['idRespCliente'] : null;
    }
}
