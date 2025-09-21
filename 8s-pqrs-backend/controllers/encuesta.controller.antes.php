<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"];
if($method == "OPTIONS") {
    die();
}
//TODO: Controlador de encuesta
require_once('email.controller.php');
require_once('revisarsesion.controller.php');
require_once('../models/encuesta.model.php');
error_reporting(0); //DESHABILITAR ERROR, DEJAR COMENTADO si se desea que se muestre el error
$encuesta = new Encuesta;

switch ($_GET["op"]) {
    //TODO: Operaciones de encuesta

    case 'todos': //TODO: Procedimiento para cargar todos los datos de encuesta
        $datos = $encuesta->todos();
    
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron encuestas."]);
        }
        break;
    case 'uno': //TODO: Procedimiento para obtener un registro de la base de datos
        $idEncuesta = $_POST["idEncuesta"];
        $datos = array();
        $datos = $encuesta->uno($idEncuesta);
        $res = mysqli_fetch_assoc($datos);
        echo json_encode($res);
        break;


    case 'insertar':
        // Encabezado para indicar que se devuelve JSON
        header('Content-Type: application/json; charset=utf-8');
    
        try {
            // 1. Validar si los campos requeridos están presentes
            if (!isset($_POST["idTicket"], $_POST["idUsuario"], $_POST["puntuacion"], $_POST["comentarios"])) {
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "message" => "Faltan parámetros requeridos."
                ]);
                exit;
            }
    
            // 2. Sanitizar y validar entradas
            $idTicket = filter_var($_POST["idTicket"], FILTER_VALIDATE_INT);
            $idUsuario = filter_var($_POST["idUsuario"], FILTER_VALIDATE_INT);
            $puntuacion = filter_var($_POST["puntuacion"], FILTER_VALIDATE_INT);
            $comentarios = trim(strip_tags($_POST["comentarios"]));
    
            if ($idTicket === false || $idUsuario === false || $puntuacion === false) {
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "message" => "Parámetros inválidos."
                ]);
                exit;
            }
    
            // 3. Insertar encuesta
            $datos = $encuesta->insertar($idTicket, $idUsuario, $puntuacion, $comentarios);
            if (!$datos || $datos === null) {
                http_response_code(500);
                echo json_encode([
                    "success" => false,
                    "message" => "No se pudo insertar la encuesta."
                ]);
                exit;
            }
    
            // 4. Preparar y enviar correo
            // require_once '../config/conexion.php';
            // require_once '../controllers/email.controller.php';
    
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $resCorreo = 'no enviado';
    
            $query = $con->prepare("
                SELECT 
                    t.titulo,
                    CONCAT(p.nombres, ' ', p.apellidos) AS nombreCompletoUsuario,
                    p.email AS emailUsuario,
                    (
                        SELECT MAX(email) FROM v_agentes va1 
                        WHERE va1.idAgente = (
                            SELECT MAX(v1.idAgente) FROM v_agente_ticketdetalle v1 WHERE v1.idTicket = t.idTicket
                        )
                    ) AS emailAgente,
                    (
                        SELECT MAX(nombreAgente) FROM v_agente_ticketdetalle v1 WHERE v1.idTicket = t.idTicket
                    ) AS nombreAgente
                FROM ticket t
                JOIN usuario u ON t.idUsuario = u.idUsuario
                JOIN persona p ON u.idPersona = p.idPersona
                WHERE t.idTicket = ?
                LIMIT 1
            ");
    
            if ($query) {
                $query->bind_param("i", $idTicket);
                $query->execute();
                $result = $query->get_result();
    
                if ($row = $result->fetch_assoc()) {
                    $asuntoTicket = $row['titulo'] ?? "Ticket $idTicket";
                    $nombreUsuario = $row['nombreCompletoUsuario'] ?? 'Usuario';
                    $emailUsuario = $row['emailUsuario'] ?? '';
                    $emailAgente = $row['emailAgente'] ?? '';
                    $nombreAgente = $row['nombreAgente'] ?? 'Agente';
    
                    $destinatarios = [];
    
                    if (!empty($emailUsuario)) {
                        $destinatarios[] = [$emailUsuario, $nombreUsuario];
                    }
                    if (!empty($emailAgente)) {
                        $destinatarios[] = [$emailAgente, $nombreAgente];
                    }
                    $destinatarios[] = ['adrian.merlo.am3+1@gmail.com', 'Copia 1'];
                    $destinatarios[] = ['adrian.merlo.am3+20@gmail.com', 'Copia 2'];
    
                    if (!empty($destinatarios)) {
                        $resCorreo = enviarEmailEncuestaRespondida(
                            $idTicket,
                            $destinatarios,
                            $asuntoTicket,
                            $puntuacion,
                            $comentarios
                        );
                    }
                }
    
                $query->close();
            }
    
            $con->close();
    
            // 5. Respuesta final asegurada
            echo json_encode([
                "success" => true,
                "message" => "Encuesta registrada correctamente.",
                "correo" => $resCorreo,
                "data" => $datos
            ]);
        } catch (Throwable $e) {
            // 6. Captura cualquier excepción
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Ocurrió un error inesperado.",
                "error" => $e->getMessage()
            ]);
        }
        break;
    

    case 'actualizar': //TODO: Procedimiento para actualizar un registro en la base de datos
        $idEncuesta = $_POST["idEncuesta"];
        $idTicket = $_POST["idTicket"];
        $idUsuario = $_POST["idUsuario"];
        $puntuacion = $_POST["puntuacion"];
        $comentarios = $_POST["comentarios"];
        $fechaRespuestaEncuesta = $_POST["fechaRespuestaEncuesta"];
        
        $datos = array();
        $datos = $encuesta->actualizar($idEncuesta, $idTicket, $idUsuario, $puntuacion, $comentarios, $fechaRespuestaEncuesta);
        echo json_encode($datos);
        break;

    case 'eliminar': //TODO: Procedimiento para eliminar un registro en la base de datos
        $idEncuesta = $_POST["idEncuesta"];
        $datos = array();
        $datos = $encuesta->eliminar($idEncuesta);
        echo json_encode($datos);
        break;

    case 'encuestasByUsuario':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Método no permitido"]);
            exit;
        }  
        $idUsuario = filter_input(INPUT_POST, 'idUsuario', FILTER_VALIDATE_INT);
    
        if (!$idUsuario) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de usuario inválido o no proporcionado"]);
            exit;
        }
        $datos = $encuesta->ncuestaByUsuario($idUsuario);
        if (is_array($datos)) {
            echo json_encode($datos);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error al obtener las encuestas"]);
        }
        break;        

    case 'ticketsByUserSinEncuesta':
        $idUsuario = isset($_POST["idUsuario"]) ? intval($_POST["idUsuario"]) : null;
    
        if (!$idUsuario) {
            http_response_code(400);
            echo json_encode(["message" => "Falta el parámetro idUsuario"]);
            break;
        }
    
        $tickets = $encuesta->ticketsByUserSinEncuesta($idUsuario);
    
        if (is_array($tickets) && count($tickets) > 0) {
            echo json_encode($tickets);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No hay tickets pendientes de encuesta para este usuario."]);
        }
        break;
    case 'ticketsSinEncuesta':
        $datos = $encuesta->ticketsSinEncuesta();    
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron encuestas."]);
        }
        break;
}
