<?php
/**
 * Model: Encuestas
 * Tabla: encuestas
 *
 * Estructura (MySQL 8 / MariaDB):
 *  - idEncuesta (BIGINT, PK, AI)
 *  - nombre (VARCHAR 120)
 *  - asuntoCorreo (VARCHAR 200)
 *  - remitenteNombre (VARCHAR 120)
 *  - scriptInicio (MEDIUMTEXT)
 *  - scriptFinal (MEDIUMTEXT, NULL)
 *  - idCanal (INT, FK -> canales.idCanal)
 *  - activa (TINYINT(1), DEFAULT 1)
 *  - fechaCreacion (TIMESTAMP)
 *  - fechaActualizacion (TIMESTAMP)
 */

require_once(__DIR__ . '/../config/config.php');

class EncuestaModel
{
    /** @var mysqli */
    private $con;

    public function __construct()
    {
        $db = new ClaseConectar();
        $this->con = $db->ProcedimientoParaConectar();
        // Forzamos utf8mb4 para textos largos
        if ($this->con && method_exists($this->con, 'set_charset')) {
            $this->con->set_charset('utf8mb4');
        }
    }

    public function __destruct()
    {
        if ($this->con) {
            $this->con->close();
        }
    }

    /**
     * Listado de encuestas (con paginación opcional)
     */
public function todos(int $limit = 100, int $offset = 0, ?bool $soloActivas = null): array
{
    try {
        $filtroActiva = '';
        if ($soloActivas === true) {
            $filtroActiva = 'WHERE e.activa = 1';
        } elseif ($soloActivas === false) {
            $filtroActiva = 'WHERE e.activa = 0';
        }

        $limit = (int)$limit;
        $offset = (int)$offset;

        $sql = "SELECT e.idEncuesta, e.nombre, e.asuntoCorreo, e.remitenteNombre, e.idCanal, e.activa, e.fechaCreacion, e.fechaActualizacion
                FROM encuestas e
                $filtroActiva
                ORDER BY e.fechaActualizacion DESC, e.idEncuesta DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $this->con->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Error al preparar consulta: " . $this->con->error);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $data = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return ['status' => 'success', 'data' => $data];
    } catch (Throwable $e) {
        http_response_code(500);
        return ['status' => 'error', 'message' => 'Error al listar encuestas', 'error' => $e->getMessage()];
    }
}


    /**
     * Obtener una encuesta por ID
     */
    public function porId(int $idEncuesta): array
    {
        try {
            $sql = "SELECT e.* FROM encuestas e WHERE e.idEncuesta = ?";
            $stmt = $this->con->prepare($sql);
            $stmt->bind_param('i', $idEncuesta);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                http_response_code(404);
                return ['status' => 'not_found', 'message' => 'Encuesta no encontrada'];
            }

            return ['status' => 'success', 'data' => $row];
        } catch (Throwable $e) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Error al obtener la encuesta', 'error' => $e->getMessage()];
        }
    }

    /**
     * Crear una nueva encuesta
     */
    public function crear(array $data): array
    {
        try {
            $sql = "INSERT INTO encuestas (nombre, asuntoCorreo, remitenteNombre, scriptInicio, scriptFinal, idCanal, activa)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->con->prepare($sql);

            // Valores y tipos
            $nombre           = trim((string)($data['nombre'] ?? ''));
            $asuntoCorreo     = trim((string)($data['asuntoCorreo'] ?? ''));
            $remitenteNombre  = trim((string)($data['remitenteNombre'] ?? ''));
            $scriptInicio     = (string)($data['scriptInicio'] ?? '');
            $scriptFinal      = $data['scriptFinal'] !== null ? (string)$data['scriptFinal'] : null; // puede ser NULL
            $idCanal          = (int)($data['idCanal'] ?? 0);
            $activa           = isset($data['activa']) ? (int)!!$data['activa'] : 1;

            $stmt->bind_param('sssssis',
                $nombre,
                $asuntoCorreo,
                $remitenteNombre,
                $scriptInicio,
                $scriptFinal,
                $idCanal,
                $activa
            );

            $stmt->execute();
            $id = $stmt->insert_id;
            $stmt->close();

            return ['status' => 'success', 'idEncuesta' => $id];
        } catch (Throwable $e) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Error al crear la encuesta', 'error' => $e->getMessage()];
        }
    }

    /**
     * Actualizar una encuesta existente
     */
    public function actualizar(int $idEncuesta, array $data): array
    {
        try {
            $sql = "UPDATE encuestas
                       SET nombre = ?,
                           asuntoCorreo = ?,
                           remitenteNombre = ?,
                           scriptInicio = ?,
                           scriptFinal = ?,
                           idCanal = ?,
                           activa = ?
                     WHERE idEncuesta = ?";
            $stmt = $this->con->prepare($sql);

            $nombre           = trim((string)($data['nombre'] ?? ''));
            $asuntoCorreo     = trim((string)($data['asuntoCorreo'] ?? ''));
            $remitenteNombre  = trim((string)($data['remitenteNombre'] ?? ''));
            $scriptInicio     = (string)($data['scriptInicio'] ?? '');
            $scriptFinal      = $data['scriptFinal'] !== null ? (string)$data['scriptFinal'] : null; // puede ser NULL
            $idCanal          = (int)($data['idCanal'] ?? 0);
            $activa           = isset($data['activa']) ? (int)!!$data['activa'] : 1;

            $stmt->bind_param('sssssiii',
                $nombre,
                $asuntoCorreo,
                $remitenteNombre,
                $scriptInicio,
                $scriptFinal,
                $idCanal,
                $activa,
                $idEncuesta
            );

            $stmt->execute();
            $af = $stmt->affected_rows;
            $stmt->close();

            return ['status' => 'success', 'affected' => $af];
        } catch (Throwable $e) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Error al actualizar la encuesta', 'error' => $e->getMessage()];
        }
    }

    /**
     * Activar / Desactivar (soft delete)
     */
    public function activar(int $idEncuesta): array
    {
        return $this->cambiarEstado($idEncuesta, 1);
    }

    public function desactivar(int $idEncuesta): array
    {
        return $this->cambiarEstado($idEncuesta, 0);
    }

    private function cambiarEstado(int $idEncuesta, int $activa): array
    {
        try {
            $sql = "UPDATE encuestas SET activa = ? WHERE idEncuesta = ?";
            $stmt = $this->con->prepare($sql);
            $stmt->bind_param('ii', $activa, $idEncuesta);
            $stmt->execute();
            $af = $stmt->affected_rows;
            $stmt->close();
            return ['status' => 'success', 'affected' => $af];
        } catch (Throwable $e) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Error al cambiar estado de la encuesta', 'error' => $e->getMessage()];
        }
    }

    /**
     * Eliminación física (opcional). Úsese con cuidado.
     */
    public function eliminar(int $idEncuesta): array
    {
        try {
            $sql = "DELETE FROM encuestas WHERE idEncuesta = ?";
            $stmt = $this->con->prepare($sql);
            $stmt->bind_param('i', $idEncuesta);
            $stmt->execute();
            $af = $stmt->affected_rows;
            $stmt->close();
            return ['status' => 'success', 'affected' => $af];
        } catch (Throwable $e) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'No se pudo eliminar la encuesta. Verifique FKs en encuestasprogramadas.', 'error' => $e->getMessage()];
        }
    }

    /**
     * Búsqueda simple por nombre/asunto (case-insensitive)
     */
    public function buscar(string $q, int $limit = 50, int $offset = 0, ?bool $soloActivas = null): array
    {
        try {
            $qLike = '%' . $this->con->real_escape_string($q) . '%';
            $filtroActiva = '';
            if ($soloActivas === true) {
                $filtroActiva = 'AND e.activa = 1';
            } elseif ($soloActivas === false) {
                $filtroActiva = 'AND e.activa = 0';
            }

            $sql = "SELECT e.idEncuesta, e.nombre, e.asuntoCorreo, e.remitenteNombre, e.idCanal, e.activa, e.fechaActualizacion
                      FROM encuestas e
                     WHERE (e.nombre LIKE ? OR e.asuntoCorreo LIKE ?)
                       $filtroActiva
                     ORDER BY e.fechaActualizacion DESC
                     LIMIT ? OFFSET ?";

            $stmt = $this->con->prepare($sql);
            $stmt->bind_param('ssii', $qLike, $qLike, $limit, $offset);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            return ['status' => 'success', 'data' => $data];
        } catch (Throwable $e) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Error en la búsqueda de encuestas', 'error' => $e->getMessage()];
        }
    }
}

?>
