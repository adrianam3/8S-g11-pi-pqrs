<?php
// TODO: Clase de RespuestasOpciones (plantillas)
require_once('../config/config.php');

class RespuestasOpciones
{
    // Listar opciones de respuesta
    public function todos($limit = 100, $offset = 0, $idPregunta = null, $estado = null, $generaPqr = null, $requiereComentario = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idPregunta)) { $conds[] = 'ro.idPregunta = ?'; $types .= 'i'; $vals[] = (int)$idPregunta; }

        if ($estado === 1 || $estado === '1' || $estado === true)  { $conds[] = 'ro.estado = 1'; }
        elseif ($estado === 0 || $estado === '0' || $estado === false) { $conds[] = 'ro.estado = 0'; }

        if ($generaPqr === 1 || $generaPqr === '1' || $generaPqr === true) { $conds[] = 'ro.generaPqr = 1'; }
        elseif ($generaPqr === 0 || $generaPqr === '0' || $generaPqr === false) { $conds[] = 'ro.generaPqr = 0'; }

        if ($requiereComentario === 1 || $requiereComentario === '1' || $requiereComentario === true) { $conds[] = 'ro.requiereComentario = 1'; }
        elseif ($requiereComentario === 0 || $requiereComentario === '0' || $requiereComentario === false) { $conds[] = 'ro.requiereComentario = 0'; }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(ro.etiqueta LIKE ?)';
            $types  .= 's';
            $like    = '%' . $q . '%';
            $vals[]  = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT ro.idOpcion, ro.idPregunta, ro.etiqueta, ro.valorNumerico, ro.secuenciaSiguiente,
                       ro.generaPqr, ro.requiereComentario, ro.estado, ro.fechaCreacion, ro.fechaActualizacion,
                       p.texto AS textoPregunta
                  FROM respuestasopciones ro
             LEFT JOIN preguntas p ON p.idPregunta = ro.idPregunta
                  $where
              ORDER BY ro.idPregunta ASC, ro.valorNumerico ASC, ro.idOpcion ASC
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

    // Obtener una opción por ID
    public function uno($idOpcion)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = 'SELECT ro.*, p.texto AS textoPregunta
                  FROM respuestasopciones ro
             LEFT JOIN preguntas p ON p.idPregunta = ro.idPregunta
                 WHERE ro.idOpcion = ?';
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idOpcion);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // Insertar opción
    public function insertar($idPregunta, $etiqueta, $valorNumerico = null, $secuenciaSiguiente = null, $generaPqr = 0, $requiereComentario = 0, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'INSERT INTO respuestasopciones (idPregunta, etiqueta, valorNumerico, secuenciaSiguiente, generaPqr, requiereComentario, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $valorNumerico = is_null($valorNumerico) ? null : (int)$valorNumerico;
            $secuenciaSiguiente = is_null($secuenciaSiguiente) ? null : (int)$secuenciaSiguiente;
            $generaPqr = (int)$generaPqr;
            $requiereComentario = (int)$requiereComentario;
            $estado = (int)$estado;

            $stmt->bind_param('isiiiii', $idPregunta, $etiqueta, $valorNumerico, $secuenciaSiguiente, $generaPqr, $requiereComentario, $estado);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
            } else {
                $error = $stmt->error;
                $stmt->close();
                $con->close();
                return $error; // Duplicate u otros
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualizar opción
    public function actualizar($idOpcion, $idPregunta, $etiqueta, $valorNumerico = null, $secuenciaSiguiente = null, $generaPqr = 0, $requiereComentario = 0, $estado = 1)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE respuestasopciones
                       SET idPregunta = ?, etiqueta = ?, valorNumerico = ?, secuenciaSiguiente = ?, generaPqr = ?, requiereComentario = ?, estado = ?
                     WHERE idOpcion = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $valorNumerico = is_null($valorNumerico) ? null : (int)$valorNumerico;
            $secuenciaSiguiente = is_null($secuenciaSiguiente) ? null : (int)$secuenciaSiguiente;
            $generaPqr = (int)$generaPqr;
            $requiereComentario = (int)$requiereComentario;
            $estado = (int)$estado;
            $idOpcion = (int)$idOpcion;

            $stmt->bind_param('isiiiiii', $idPregunta, $etiqueta, $valorNumerico, $secuenciaSiguiente, $generaPqr, $requiereComentario, $estado, $idOpcion);

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

    // Eliminar opción
    public function eliminar($idOpcion)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'DELETE FROM respuestasopciones WHERE idOpcion = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param('i', $idOpcion);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close();
                $con->close();
                return $ok; // 1 = eliminado, 0 = no existía
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) { // FK constraint
                    return 'La opción tiene dependencias (respuestas de clientes, etc.).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Activar / Desactivar opción
    public function activar($idOpcion)    { return $this->cambiarEstado($idOpcion, 1); }
    public function desactivar($idOpcion) { return $this->cambiarEstado($idOpcion, 0); }

    private function cambiarEstado($idOpcion, $estado)
    {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = 'UPDATE respuestasopciones SET estado = ? WHERE idOpcion = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $estado = (int)$estado; $idOpcion = (int)$idOpcion;
            $stmt->bind_param('ii', $estado, $idOpcion);

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

    // Contar opciones (para paginación)
    public function contar($idPregunta = null, $estado = null, $generaPqr = null, $requiereComentario = null, $q = null)
    {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        if (!is_null($idPregunta)) { $conds[] = 'idPregunta = ?'; $types .= 'i'; $vals[] = (int)$idPregunta; }

        if ($estado === 1 || $estado === '1' || $estado === true)  { $conds[] = 'estado = 1'; }
        elseif ($estado === 0 || $estado === '0' || $estado === false) { $conds[] = 'estado = 0'; }

        if ($generaPqr === 1 || $generaPqr === '1' || $generaPqr === true) { $conds[] = 'generaPqr = 1'; }
        elseif ($generaPqr === 0 || $generaPqr === '0' || $generaPqr === false) { $conds[] = 'generaPqr = 0'; }

        if ($requiereComentario === 1 || $requiereComentario === '1' || $requiereComentario === true) { $conds[] = 'requiereComentario = 1'; }
        elseif ($requiereComentario === 0 || $requiereComentario === '0' || $requiereComentario === false) { $conds[] = 'requiereComentario = 0'; }

        if (!is_null($q) && $q !== '') {
            $conds[] = '(etiqueta LIKE ?)';
            $types  .= 's';
            $like    = '%' . $q . '%';
            $vals[]  = $like;
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM respuestasopciones $where";

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
