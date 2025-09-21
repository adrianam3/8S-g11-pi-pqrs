<?php
//TODO: Clase de Encuesta
require_once('../config/config.php');

class Encuesta
{
    //TODO: Implementar los métodos de la clase

    public function todos()
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
    
        $cadena = " SELECT
            t.idTicket,
            t.titulo,
            t.descripcion,
            t.fechaCreacion,
            t.fechaCierre,
            NULL AS idEncuesta,
            NULL AS puntuacion,
            NULL AS comentarios,
            NULL AS fechaRespuestaEncuesta,
            p.idPersona,
            p.nombres,
            p.apellidos,
            CONCAT(p.nombres, ' ', p.apellidos) AS nombreCompletoUsuario,

                (select max(nombreAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) as nombreAgente,
            'pendiente' AS estadoEncuesta,
            0 AS completada
            , (select max(nombreDepartamento) from v_agentes va1 WHERE va1.idAgente=(select max(v1.idAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) ) as  departamentoAgente
             , (select max(email) from v_agentes va1 WHERE va1.idAgente=(select max(v1.idAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) ) as  emailAgente
             , (select nombre from estadoticket e1 where e1.idEstadoTicket=t.idEstadoTicket) as estadoticket
             , u.idUsuario, u.usuario
            FROM ticket t
            JOIN usuario u ON t.idUsuario = u.idUsuario
            JOIN persona p ON u.idPersona = p.idPersona
            AND t.idTicket NOT IN (SELECT idTicket FROM encuesta)
            AND t.idEstadoTicket = 4

            UNION ALL

            SELECT
                t.idTicket,
                t.titulo,
                t.descripcion,
                t.fechaCreacion,
                t.fechaCierre,
                e.idEncuesta,
                e.puntuacion,
                e.comentarios,
                e.fechaRespuestaEncuesta,
                p.idPersona,
                p.nombres,
                p.apellidos,
                CONCAT(p.nombres, ' ', p.apellidos) AS nombreCompletoUsuario,

             (select max(nombreAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) as nombreAgente,
                'respondida' AS estadoEncuesta,
                1 AS completada
            , (select max(nombreDepartamento) from v_agentes va1 WHERE va1.idAgente=(select max(v1.idAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) ) as  departamentoAgente
             , (select max(email) from v_agentes va1 WHERE va1.idAgente=(select max(v1.idAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) ) as  emailAgente
             , (select nombre from estadoticket e1 where e1.idEstadoTicket=t.idEstadoTicket) as estadoticket    
             , u.idUsuario, u.usuario
            FROM encuesta e
            JOIN ticket t ON e.idTicket = t.idTicket
            JOIN usuario u ON e.idUsuario = u.idUsuario
            JOIN persona p ON u.idPersona = p.idPersona
            ORDER BY fechaCreacion DESC;
        ";
    
        $stmt = $con->prepare($cadena);
    
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
        $encuestas = [];
    
        while ($row = $result->fetch_assoc()) {
            $encuestas[] = $row;
        }
    
        $stmt->close();
        $con->close();
    
        return $encuestas;
    }    

    public function uno($idEncuesta) // Select * from encuesta where id = $idEncuesta
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT * FROM `encuesta` WHERE `idEncuesta`=$idEncuesta";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        return $datos;
    }


    public function insertar($idTicket, $idUsuario, $puntuacion, $comentarios)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
    
            // Preparar la sentencia con placeholders
            $stmt = $con->prepare("INSERT INTO `encuesta` (`idTicket`, `idUsuario`, `puntuacion`, `comentarios`) VALUES (?, ?, ?, ?)");
    
            if (!$stmt) {
                http_response_code(500);
                return "Error al preparar la consulta: " . $con->error;
            }
    
            // Enlazar los parámetros a la sentencia
            $stmt->bind_param("iiis", $idTicket, $idUsuario, $puntuacion, $comentarios);
            // i = integer, s = string (según el tipo de cada campo)
    
            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                return $insertId;
            } else {
                $error = $stmt->error;
                $stmt->close();
                return $error;
            }
    
        } catch (Exception $th) {
            http_response_code(500);
            return $th->getMessage();
        } finally {
            if (isset($con) && $con) {
                $con->close();
            }
        }
    }
    

    public function actualizar($idEncuesta, $idTicket, $idUsuario, $puntuacion, $comentarios, $fechaRespuestaEncuesta) // Update encuesta set ... where id = $idEncuesta
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $cadena = "UPDATE `encuesta` SET 
                        `idTicket`='$idTicket', 
                        `idUsuario`='$idUsuario', 
                        `puntuacion`='$puntuacion', 
                        `comentarios`='$comentarios', 
                        `fechaRespuestaEncuesta`='$fechaRespuestaEncuesta'
                        WHERE `idEncuesta`=$idEncuesta";
            if (mysqli_query($con, $cadena)) {
                return $idEncuesta;
            } else {
                return $con->error;
            }
        } catch (Exception $th) {
            http_response_code(500);
            return $th->getMessage();
        } finally {
            $con->close();
        }
    }

    public function eliminar($idEncuesta) // Delete from encuesta where id = $idEncuesta
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $cadena = "DELETE FROM `encuesta` WHERE `idEncuesta`= $idEncuesta";
            if (mysqli_query($con, $cadena)) {
                return 1;
            } else {
                return $con->error;
            }
        } catch (Exception $th) {
            http_response_code(500);
            return $th->getMessage();
        } finally {
            $con->close();
        }
    }

    ////////////

    public function encuestasPorUsuario($idUsuario)
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();

        // Consulta SQL segura con prepared statements
        $cadena = "SELECT encuesta.*,
            persona.idPersona,
            persona.nombres,
            persona.apellidos,
            CONCAT(persona.nombres, ' ', persona.apellidos) AS nombreCompletoUsuario,
            ticket.titulo, ticket.descripcion, ticket.fechaCreacion, ticket.fechaCierre,
            (
                SELECT CONCAT(p1.nombres, ' ', p1.apellidos)
                FROM ticketdetalle td1
                JOIN agente a1 ON a1.idAgente = td1.idAgente
                JOIN usuario u1 ON a1.idUsuario = u1.idUsuario
                JOIN persona p1 ON p1.idPersona = u1.idPersona
                WHERE td1.idTicket = ticket.idTicket
                AND td1.idTicketDetalle = (
                    SELECT MAX(idTicketDetalle)
                    FROM ticketdetalle td2
                    WHERE td2.idTicket = td1.idTicket
                )
            ) AS nombreAgente
            FROM encuesta
            JOIN ticket ON encuesta.idTicket = ticket.idTicket
            JOIN usuario ON encuesta.idUsuario = usuario.idUsuario
            JOIN persona ON persona.idPersona = usuario.idPersona
            WHERE encuesta.idUsuario = ?";

        $stmt = $con->prepare($cadena);

        if (!$stmt) {
            http_response_code(500);
            return ['error' => 'Error al preparar la consulta'];
        }

        $stmt->bind_param("i", $idUsuario);
        $stmt->execute();

        $resultado = $stmt->get_result();

        $encuestas = [];
        while ($row = $resultado->fetch_assoc()) {
            $encuestas[] = $row;
        }

        $stmt->close();
        $con->close();

        return $encuestas;
    }

    public function ticketsByUserSinEncuesta($idUsuario)
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();

        $query = "SELECT ticket.idTicket, ticket.titulo, ticket.descripcion, ticket.fechaCreacion, ticket.fechaCierre
                FROM ticket
                WHERE ticket.idUsuario = ?
                AND ticket.idTicket NOT IN (
                    SELECT idTicket FROM encuesta
                )";

        $stmt = $con->prepare($query);
        $stmt->bind_param("i", $idUsuario);
        $stmt->execute();
        $result = $stmt->get_result();

        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }

        $stmt->close();
        $con->close();
        return $tickets;
    }

    public function ticketsSinEncuesta()
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
    
        // Consulta: obtener tickets que no tengan encuesta
        $sql = "
            SELECT 
                t.idTicket,
                t.titulo,
                t.descripcion,
                t.fechaCierre,
                CONCAT(p.nombres, ' ', p.apellidos) AS nombreCompletoUsuario,
                u.idUsuario
            FROM ticket t
            INNER JOIN usuario u ON u.idUsuario = t.idUsuario
            INNER JOIN persona p ON p.idPersona = u.idPersona
            LEFT JOIN encuesta e ON e.idTicket = t.idTicket
            WHERE e.idEncuesta IS NULL AND t.idEstadoTicket = 4
            ORDER BY t.fechaCierre DESC
        ";
    
        try {
            $stmt = $con->prepare($sql);
            if (!$stmt) {
                throw new Exception("Error al preparar la consulta: " . $con->error);
            }
    
            $stmt->execute();
            $result = $stmt->get_result();
    
            $tickets = [];
            while ($row = $result->fetch_assoc()) {
                $tickets[] = $row;
            }
    
            return $tickets;
    
        } catch (Exception $e) {
            http_response_code(500);
            return ['status' => 'error', 'message' => 'Error al obtener tickets sin encuesta', 'error' => $e->getMessage()];
        } finally {
            $stmt->close();
            $con->close();
        }
    }  
    
    public function ncuestaByUsuario()
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        if (!isset($_POST['idUsuario'])) {
            http_response_code(400);
            return ['status' => 'error', 'message' => 'idUsuario no proporcionado'];
        }
    
        $idUsuario = $_POST['idUsuario'];
    
        $cadena = " SELECT
            t.idTicket,
            t.titulo,
            t.descripcion,
            t.fechaCreacion,
            t.fechaCierre,
            NULL AS idEncuesta,
            NULL AS puntuacion,
            NULL AS comentarios,
            NULL AS fechaRespuestaEncuesta,
            p.idPersona,
            p.nombres,
            p.apellidos,
            CONCAT(p.nombres, ' ', p.apellidos) AS nombreCompletoUsuario,

                (select max(nombreAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) as nombreAgente,
            'pendiente' AS estadoEncuesta,
            0 AS completada
            , (select max(nombreDepartamento) from v_agentes va1 WHERE va1.idAgente=(select max(v1.idAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) ) as  departamentoAgente
             , (select max(email) from v_agentes va1 WHERE va1.idAgente=(select max(v1.idAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) ) as  emailAgente
             , (select nombre from estadoticket e1 where e1.idEstadoTicket=t.idEstadoTicket) as estadoticket
             , u.idUsuario, u.usuario
            FROM ticket t
            JOIN usuario u ON t.idUsuario = u.idUsuario
            JOIN persona p ON u.idPersona = p.idPersona
            WHERE t.idUsuario = ?
            AND t.idTicket NOT IN (SELECT idTicket FROM encuesta)
            AND t.idEstadoTicket = 4

            UNION ALL

            SELECT
                t.idTicket,
                t.titulo,
                t.descripcion,
                t.fechaCreacion,
                t.fechaCierre,
                e.idEncuesta,
                e.puntuacion,
                e.comentarios,
                e.fechaRespuestaEncuesta,
                p.idPersona,
                p.nombres,
                p.apellidos,
                CONCAT(p.nombres, ' ', p.apellidos) AS nombreCompletoUsuario,

             (select max(nombreAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) as nombreAgente,
                'respondida' AS estadoEncuesta,
                1 AS completada
            , (select max(nombreDepartamento) from v_agentes va1 WHERE va1.idAgente=(select max(v1.idAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) ) as  departamentoAgente
             , (select max(email) from v_agentes va1 WHERE va1.idAgente=(select max(v1.idAgente) from v_agente_ticketdetalle v1 where v1.idTicket=t.idTicket) ) as  emailAgente
             , (select nombre from estadoticket e1 where e1.idEstadoTicket=t.idEstadoTicket) as estadoticket    
             , u.idUsuario, u.usuario
            FROM encuesta e
            JOIN ticket t ON e.idTicket = t.idTicket
            JOIN usuario u ON e.idUsuario = u.idUsuario
            JOIN persona p ON u.idPersona = p.idPersona
            WHERE e.idUsuario = ?
            ORDER BY fechaCreacion DESC;"; 

        try {
            $stmt = $con->prepare($cadena);
            if (!$stmt) {
                throw new Exception("Error al preparar la consulta: " . $con->error);
            }

        if (!$stmt) {
            http_response_code(500);
            return ['error' => 'Error al preparar la consulta'];
        }

        $stmt->bind_param("ii", $idUsuario, $idUsuario);
        $stmt->execute();

        $resultado = $stmt->get_result();

        $encuestas = [];
        while ($row = $resultado->fetch_assoc()) {
            $encuestas[] = $row;
        }

        return $encuestas;
    } catch (Exception $e) {
        http_response_code(500);
        return ['status' => 'error', 'message' => 'Error al obtener encuestas por usuario', 'error' => $e->getMessage()];
    } finally {
        $stmt->close();
        $con->close();
    }
    }
}
