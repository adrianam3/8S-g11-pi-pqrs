<?php
// TODO: Clase de Pqrs (gestión de PQRS)
require_once('../config/config.php');

class Pqrs
{
    // =========================
    // LISTAR
    // =========================
    public function todos(
        int $limit = 100,
        int $offset = 0,
        ?int $idTipo = null,
        ?int $idCategoria = null,
        ?int $idCanal = null,
        ?int $idEstado = null,
        ?int $idAgencia = null,
        ?int $idCliente = null,
        ?int $idEncuesta = null,
        ?int $idProgEncuesta = null,
        ?int $nivelActual = null,
        $estadoRegistro = null, // 1 | 0 | null
        ?string $fechaDesde = null,
        ?string $fechaHasta = null,
        ?string $q = null
    ) {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        // Filtros exactos
        $map = [
            ['p.idTipo',         $idTipo,         'i'],
            ['p.idCategoria',    $idCategoria,    'i'],
            ['p.idCanal',        $idCanal,        'i'],
            ['p.idEstado',       $idEstado,       'i'],
            ['p.idAgencia',      $idAgencia,      'i'],
            ['p.idCliente',      $idCliente,      'i'],
            ['p.idEncuesta',     $idEncuesta,     'i'],
            ['p.idProgEncuesta', $idProgEncuesta, 'i'],
            ['p.nivelActual',    $nivelActual,    'i'],
        ];
        foreach ($map as [$field, $val, $t]) {
            if ($val !== null && $val !== '') { $conds[] = "$field = ?"; $types .= $t; $vals[] = $val; }
        }

        // estadoRegistro: 1/0
        if ($estadoRegistro === 1 || $estadoRegistro === '1' || $estadoRegistro === true) {
            $conds[] = 'p.estadoRegistro = 1';
        } elseif ($estadoRegistro === 0 || $estadoRegistro === '0' || $estadoRegistro === false) {
            $conds[] = 'p.estadoRegistro = 0';
        }

        // Rango de fechas (sobre fechaCreacion)
        if (!empty($fechaDesde)) { $conds[] = 'p.fechaCreacion >= ?'; $types .= 's'; $vals[] = $fechaDesde; }
        if (!empty($fechaHasta)) { $conds[] = 'p.fechaCreacion <= ?'; $types .= 's'; $vals[] = $fechaHasta; }

        // Búsqueda libre
        if (!is_null($q) && $q !== '') {
            $conds[] = '(p.codigo LIKE ? OR p.asunto LIKE ? OR p.detalle LIKE ? OR cl.cedula LIKE ? OR cl.email LIKE ? OR cl.nombres LIKE ? OR cl.apellidos LIKE ?)';
            $types  .= 'sssssss';
            $like    = '%' . $q . '%';
            array_push($vals, $like, $like, $like, $like, $like, $like, $like);
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

        // Paginación segura (no bind en LIMIT/OFFSET por compatibilidad)
        $limit  = max(0, (int)$limit);
        $offset = max(0, (int)$offset);

        $sql = "SELECT
                    p.idPqrs, p.codigo, p.asunto, p.detalle, p.nivelActual, p.fechaLimiteNivel, p.fechaCierre,
                    p.estadoRegistro, p.fechaCreacion, p.fechaActualizacion,
                    p.idTipo, tp.nombre AS nombreTipo,
                    p.idCategoria, cat.nombre AS nombreCategoria,
                    p.idCanal, ca.nombre AS nombreCanal,
                    p.idEstado, es.nombre AS nombreEstado,
                    p.idAgencia, ag.nombre AS nombreAgencia,
                    p.idCliente, cl.cedula, cl.nombres, cl.apellidos, CONCAT(cl.nombres, ' ', cl.apellidos) AS nombreCliente, cl.email AS emailCliente,
                    p.idEncuesta, en.nombre AS nombreEncuesta,
                    p.idProgEncuesta, ep.estadoEnvio AS estadoProgEncuesta, ep.idAtencion
                FROM pqrs p
                LEFT JOIN tipospqrs tp           ON tp.idTipo = p.idTipo
                LEFT JOIN categoriaspqrs cat     ON cat.idCategoria = p.idCategoria
                LEFT JOIN canales ca             ON ca.idCanal = p.idCanal
                LEFT JOIN estadospqrs es         ON es.idEstado = p.idEstado
                LEFT JOIN agencias ag            ON ag.idAgencia = p.idAgencia
                LEFT JOIN clientes cl            ON cl.idCliente = p.idCliente
                LEFT JOIN encuestas en           ON en.idEncuesta = p.idEncuesta
                LEFT JOIN encuestasprogramadas ep ON ep.idProgEncuesta = p.idProgEncuesta
                $where
                ORDER BY p.fechaActualizacion DESC, p.idPqrs DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $con->prepare($sql);
        if (!$stmt) { error_log('Pqrs.todos: ' . $con->error); $con->close(); return false; }

        if ($types !== '') { $stmt->bind_param($types, ...$vals); }
        if (!$stmt->execute()) { error_log('Pqrs.todos exec: ' . $stmt->error); $stmt->close(); $con->close(); return false; }

        $res  = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }

        $stmt->close();
        $con->close();
        return $rows;
    }

    // =========================
    // OBTENER UNO
    // =========================
    public function uno(int $idPqrs) {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $sql = "SELECT
                    p.*, tp.nombre AS nombreTipo, cat.nombre AS nombreCategoria, ca.nombre AS nombreCanal,
                    es.nombre AS nombreEstado, ag.nombre AS nombreAgencia,
                    cl.cedula, cl.nombres, cl.apellidos, CONCAT(cl.nombres,' ',cl.apellidos) AS nombreCliente, cl.email AS emailCliente,
                    en.nombre AS nombreEncuesta, ep.estadoEnvio AS estadoProgEncuesta, ep.idAtencion
                FROM pqrs p
                LEFT JOIN tipospqrs tp           ON tp.idTipo = p.idTipo
                LEFT JOIN categoriaspqrs cat     ON cat.idCategoria = p.idCategoria
                LEFT JOIN canales ca             ON ca.idCanal = p.idCanal
                LEFT JOIN estadospqrs es         ON es.idEstado = p.idEstado
                LEFT JOIN agencias ag            ON ag.idAgencia = p.idAgencia
                LEFT JOIN clientes cl            ON cl.idCliente = p.idCliente
                LEFT JOIN encuestas en           ON en.idEncuesta = p.idEncuesta
                LEFT JOIN encuestasprogramadas ep ON ep.idProgEncuesta = p.idProgEncuesta
                WHERE p.idPqrs = ?
                LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idPqrs);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        $con->close();
        return $row ?: null;
    }

    // =========================
    // INSERTAR
    // =========================
    public function insertar(
        string $codigo,
        int $idTipo,
        ?int $idCategoria,
        int $idCanal,
        int $idEstado,
        ?int $idAgencia,
        int $idCliente,
        int $idEncuesta,
        int $idProgEncuesta,
        string $asunto,
        ?string $detalle,
        int $nivelActual = 1,
        ?string $fechaLimiteNivel = null,
        ?string $fechaCierre = null,
        int $estadoRegistro = 1
    ) {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "INSERT INTO pqrs
                    (codigo, idTipo, idCategoria, idCanal, idEstado, idAgencia, idCliente, idEncuesta, idProgEncuesta, asunto, detalle, nivelActual, fechaLimiteNivel, fechaCierre, estadoRegistro)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param(
               'siiiiiiiississi',
                $codigo, $idTipo, $idCategoria, $idCanal, $idEstado, $idAgencia, $idCliente, $idEncuesta, $idProgEncuesta,
                $asunto, $detalle, $nivelActual, $fechaLimiteNivel, $fechaCierre, $estadoRegistro
            );

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close(); $con->close();
                return $insertId;
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

    // =========================
    // ACTUALIZAR
    // =========================
    public function actualizar(
        int $idPqrs,
        string $codigo,
        int $idTipo,
        ?int $idCategoria,
        int $idCanal,
        int $idEstado,
        ?int $idAgencia,
        int $idCliente,
        int $idEncuesta,
        int $idProgEncuesta,
        string $asunto,
        ?string $detalle,
        int $nivelActual = 1,
        ?string $fechaLimiteNivel = null,
        ?string $fechaCierre = null,
        int $estadoRegistro = 1
    ) {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();

            $sql = "UPDATE pqrs SET
                        codigo = ?, idTipo = ?, idCategoria = ?, idCanal = ?, idEstado = ?, idAgencia = ?,
                        idCliente = ?, idEncuesta = ?, idProgEncuesta = ?, asunto = ?, detalle = ?,
                        nivelActual = ?, fechaLimiteNivel = ?, fechaCierre = ?, estadoRegistro = ?
                    WHERE idPqrs = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }

            $stmt->bind_param(
               'siiiiiiiississii',
                $codigo, $idTipo, $idCategoria, $idCanal, $idEstado, $idAgencia, $idCliente, $idEncuesta, $idProgEncuesta,
                $asunto, $detalle, $nivelActual, $fechaLimiteNivel, $fechaCierre, $estadoRegistro, $idPqrs
            );

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $affected;
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

    // =========================
    // ELIMINAR
    // =========================
    public function eliminar(int $idPqrs) {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();
            $sql = 'DELETE FROM pqrs WHERE idPqrs = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
            $stmt->bind_param('i', $idPqrs);
            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close(); $con->close();
                return $ok;
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) {
                    return 'El PQRS tiene dependencias (preguntas o responsables).';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // =========================
    // CAMBIAR ESTADO REGISTRO (activar/desactivar lógico)
    // =========================
    public function cambiarEstadoRegistro(int $idPqrs, int $estadoRegistro) {
        try {
            $conObj = new ClaseConectar();
            $con = $conObj->ProcedimientoParaConectar();
            $sql = 'UPDATE pqrs SET estadoRegistro = ? WHERE idPqrs = ?';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar la consulta: ' . $con->error; }
            $stmt->bind_param('ii', $estadoRegistro, $idPqrs);
            if ($stmt->execute()) { $af = $stmt->affected_rows; $stmt->close(); $con->close(); return $af; }
            else { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }
    public function activar(int $idPqrs) { return $this->cambiarEstadoRegistro($idPqrs, 1); }
    public function desactivar(int $idPqrs) { return $this->cambiarEstadoRegistro($idPqrs, 0); }

    // =========================
    // CONTAR (para paginación)
    // =========================
    public function contar(
        $idTipo = null, $idCategoria = null, $idCanal = null, $idEstado = null, $idAgencia = null, $idCliente = null,
        $idEncuesta = null, $idProgEncuesta = null, $nivelActual = null, $estadoRegistro = null, $fechaDesde = null, $fechaHasta = null, $q = null
    ) {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();

        $conds = [];
        $types = '';
        $vals  = [];

        $map = [
            ['idTipo',         $idTipo,         'i'],
            ['idCategoria',    $idCategoria,    'i'],
            ['idCanal',        $idCanal,        'i'],
            ['idEstado',       $idEstado,       'i'],
            ['idAgencia',      $idAgencia,      'i'],
            ['idCliente',      $idCliente,      'i'],
            ['idEncuesta',     $idEncuesta,     'i'],
            ['idProgEncuesta', $idProgEncuesta, 'i'],
            ['nivelActual',    $nivelActual,    'i'],
        ];
        foreach ($map as [$field, $val, $t]) {
            if ($val !== null && $val !== '') { $conds[] = "$field = ?"; $types .= $t; $vals[] = $val; }
        }

        if ($estadoRegistro === 1 || $estadoRegistro === '1' || $estadoRegistro === true) {
            $conds[] = 'estadoRegistro = 1';
        } elseif ($estadoRegistro === 0 || $estadoRegistro === '0' || $estadoRegistro === false) {
            $conds[] = 'estadoRegistro = 0';
        }
        if (!empty($fechaDesde)) { $conds[] = 'fechaCreacion >= ?'; $types .= 's'; $vals[] = $fechaDesde; }
        if (!empty($fechaHasta)) { $conds[] = 'fechaCreacion <= ?'; $types .= 's'; $vals[] = $fechaHasta; }
        if (!is_null($q) && $q !== '') {
            $conds[] = '(codigo LIKE ? OR asunto LIKE ? OR detalle LIKE ?)';
            $types  .= 'sss'; $like = '%' . $q . '%'; array_push($vals, $like, $like, $like);
        }

        $where = count($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $sql   = "SELECT COUNT(*) AS total FROM pqrs $where";

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

    // =========================
    // DEPENDENCIAS (hijas)
    // =========================
    public function dependencias(int $idPqrs) {
        $conObj = new ClaseConectar();
        $con = $conObj->ProcedimientoParaConectar();
        $out = [
            'pqrs_preguntas'   => 0,
            'pqrs_responsables'=> 0
        ];

        $queries = [
            'pqrs_preguntas'    => 'SELECT COUNT(*) c FROM pqrs_preguntas WHERE idPqrs = ?',
            'pqrs_responsables' => 'SELECT COUNT(*) c FROM pqrs_responsables WHERE idPqrs = ?'
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $con->prepare($sql);
            if (!$stmt) { $out[$key] = -1; continue; }
            $stmt->bind_param('i', $idPqrs);
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

    // =========================
    // GESTIÓN DE PREGUNTAS ASOCIADAS (tabla pqrs_preguntas)
    // =========================
    public function agregarPregunta(int $idPqrs, int $idPregunta, ?int $idCategoria = null) {
        try {
            $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
            $sql = 'INSERT INTO pqrs_preguntas (idPqrs, idPregunta, idCategoria) VALUES (?,?,?)';
            $stmt = $con->prepare($sql);
            if (!$stmt) { http_response_code(500); return 'Error al preparar: ' . $con->error; }
            $stmt->bind_param('iii', $idPqrs, $idPregunta, $idCategoria);
            if ($stmt->execute()) { $id = $stmt->insert_id; $stmt->close(); $con->close(); return $id; }
            else { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        } catch (Exception $e) { http_response_code(500); return $e->getMessage(); }
    }
    public function quitarPregunta(int $idPqrs, int $idPregunta) {
        try {
            $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
            $sql = 'DELETE FROM pqrs_preguntas WHERE idPqrs = ? AND idPregunta = ?';
            $stmt = $con->prepare($sql); if (!$stmt) { http_response_code(500); return 'Error al preparar: ' . $con->error; }
            $stmt->bind_param('ii', $idPqrs, $idPregunta);
            if ($stmt->execute()) { $af = $stmt->affected_rows; $stmt->close(); $con->close(); return $af; }
            else { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        } catch (Exception $e) { http_response_code(500); return $e->getMessage(); }
    }
    public function listarPreguntas(int $idPqrs) {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $sql = "SELECT pp.id, pp.idPqrs, pp.idPregunta, pr.texto, pp.idCategoria, cat.nombre AS nombreCategoria
                FROM pqrs_preguntas pp
                JOIN preguntas pr ON pr.idPregunta = pp.idPregunta
                LEFT JOIN categoriaspqrs cat ON cat.idCategoria = pp.idCategoria
                WHERE pp.idPqrs = ?";
        $stmt = $con->prepare($sql); if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idPqrs);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        $res = $stmt->get_result();
        $rows = []; while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close(); $con->close(); return $rows;
    }

    // =========================
    // GESTIÓN DE RESPONSABLES (tabla pqrs_responsables)
    // =========================
    public function agregarResponsable(int $idPqrs, int $nivel, int $idResponsable, int $horasSLA) {
        try {
            $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
            $sql = 'INSERT INTO pqrs_responsables (idPqrs, nivel, idResponsable, horasSLA) VALUES (?,?,?,?)';
            $stmt = $con->prepare($sql); if (!$stmt) { http_response_code(500); return 'Error al preparar: ' . $con->error; }
            $stmt->bind_param('iiii', $idPqrs, $nivel, $idResponsable, $horasSLA);
            if ($stmt->execute()) { $id = $stmt->insert_id; $stmt->close(); $con->close(); return $id; }
            else { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        } catch (Exception $e) { http_response_code(500); return $e->getMessage(); }
    }
    public function listarResponsables(int $idPqrs) {
        $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
        $sql = "SELECT pr.id, pr.idPqrs, pr.nivel, pr.idResponsable, pr.horasSLA, u.usuario, pe.nombres, pe.apellidos
                FROM pqrs_responsables pr
                JOIN usuarios u ON u.idUsuario = pr.idResponsable
                JOIN personas pe ON pe.idPersona = u.idPersona
                WHERE pr.idPqrs = ?
                ORDER BY pr.nivel ASC";
        $stmt = $con->prepare($sql); if (!$stmt) { $err = $con->error; $con->close(); return $err; }
        $stmt->bind_param('i', $idPqrs);
        if (!$stmt->execute()) { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        $res = $stmt->get_result();
        $rows = []; while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close(); $con->close(); return $rows;
    }
    public function eliminarResponsable(int $id) {
        try {
            $conObj = new ClaseConectar(); $con = $conObj->ProcedimientoParaConectar();
            $sql = 'DELETE FROM pqrs_responsables WHERE id = ?';
            $stmt = $con->prepare($sql); if (!$stmt) { http_response_code(500); return 'Error al preparar: ' . $con->error; }
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) { $af = $stmt->affected_rows; $stmt->close(); $con->close(); return $af; }
            else { $err = $stmt->error; $stmt->close(); $con->close(); return $err; }
        } catch (Exception $e) { http_response_code(500); return $e->getMessage(); }
    }
}
