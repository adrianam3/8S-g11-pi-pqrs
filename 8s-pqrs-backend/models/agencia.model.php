<?php
// TODO: Clase de Agencia (tabla `agencias`)
require_once('../config/config.php');

class Agencia
{
    // Listar todas las agencias
    public function todos()
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();

        $sql = "SELECT idAgencia, nombre, estado, fechaCreacion, fechaActualizacion
                  FROM agencias
              ORDER BY nombre ASC";

        $stmt = $con->prepare($sql);
        if (!$stmt) {
            error_log("Error al preparar la consulta: " . $con->error);
            $con->close();
            return false;
        }

        if (!$stmt->execute()) {
            error_log("Error al ejecutar la consulta: " . $stmt->error);
            $stmt->close();
            $con->close();
            return false;
        }

        $result = $stmt->get_result();
        $agencias = [];
        while ($row = $result->fetch_assoc()) {
            $agencias[] = $row;
        }

        $stmt->close();
        $con->close();
        return $agencias;
    }

    // Obtener una agencia por ID
    public function uno($idAgencia)
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT * FROM `agencias` WHERE `idAgencia`=$idAgencia";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        return $datos; // el controlador puede usar mysqli_fetch_assoc($datos)
    }

    // Insertar nueva agencia
    public function insertar($nombre, $estado = 1)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            $sql = "INSERT INTO `agencias` (`nombre`, `estado`) VALUES (?, ?)";
            $stmt = $con->prepare($sql);
            if (!$stmt) {
                http_response_code(500);
                return "Error al preparar la consulta: " . $con->error;
            }

            $estado = (int)$estado; // asegurar entero (0/1)
            $stmt->bind_param("si", $nombre, $estado);

            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
            } else {
                $error = $stmt->error;
                $stmt->close();
                $con->close();
                return $error; // puede incluir violación de UNIQUE(nombre)
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Actualizar agencia
    public function actualizar($idAgencia, $nombre, $estado)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            $sql = "UPDATE `agencias`
                       SET `nombre` = ?, `estado` = ?
                     WHERE `idAgencia` = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) {
                http_response_code(500);
                return "Error al preparar la consulta: " . $con->error;
            }

            $estado = (int)$estado;
            $idAgencia = (int)$idAgencia;
            $stmt->bind_param("sii", $nombre, $estado, $idAgencia);

            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close();
                $con->close();
                // devolver el id actualizado (como tu patrón) si hubo cambios, si no, igual devolver id
                return $idAgencia;
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

    // Eliminar agencia
    public function eliminar($idAgencia)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            $sql = "DELETE FROM `agencias` WHERE `idAgencia` = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) {
                http_response_code(500);
                return "Error al preparar la consulta: " . $con->error;
            }

            $idAgencia = (int)$idAgencia;
            $stmt->bind_param("i", $idAgencia);

            if ($stmt->execute()) {
                $ok = $stmt->affected_rows > 0 ? 1 : 0;
                $stmt->close();
                $con->close();
                return $ok; // 1 = eliminado, 0 = no existía
            } else {
                $errno = $stmt->errno; $err = $stmt->error;
                $stmt->close(); $con->close();
                if ($errno == 1451) {
                    // Clave foránea: agencias está referenciada por otras tablas
                    return 'La agencia tiene registros relacionados y no puede eliminarse.';
                }
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }

    // Activar agencia
    public function activar($idAgencia)
    {
        return $this->cambiarEstado($idAgencia, 1);
    }

    // Desactivar agencia
    public function desactivar($idAgencia)
    {
        return $this->cambiarEstado($idAgencia, 0);
    }

    private function cambiarEstado($idAgencia, $estado)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            $sql = "UPDATE `agencias` SET `estado` = ? WHERE `idAgencia` = ?";
            $stmt = $con->prepare($sql);
            if (!$stmt) {
                http_response_code(500);
                return "Error al preparar la consulta: " . $con->error;
            }

            $estado = (int)$estado; $idAgencia = (int)$idAgencia;
            $stmt->bind_param("ii", $estado, $idAgencia);

            if ($stmt->execute()) {
                $af = $stmt->affected_rows;
                $stmt->close(); $con->close();
                return $af; // filas afectadas
            } else {
                $err = $stmt->error; $stmt->close(); $con->close();
                return $err;
            }
        } catch (Exception $e) {
            http_response_code(500);
            return $e->getMessage();
        }
    }
}
