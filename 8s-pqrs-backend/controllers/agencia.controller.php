<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de agencias
require_once('email.controller.php');
require_once('revisarsesion.controller.php');
require_once('../models/agencia.model.php');
error_reporting(0); //DESHABILITAR ERROR, DEJAR COMENTADO si se desea que se muestre el error

$agencia = new Agencia;

switch ($_GET["op"] ?? '') {

    // ===== Listar =====
    case 'todos':
        $datos = $agencia->todos();
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron agencias."]);
        }
        break;

    // ===== Obtener uno =====
    case 'uno':
        $idAgencia = isset($_POST["idAgencia"]) ? intval($_POST["idAgencia"]) : 0;
        if ($idAgencia <= 0) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAgencia inválido"]); break; }
        $datos = $agencia->uno($idAgencia);
        $res = mysqli_fetch_assoc($datos);
        if ($res) echo json_encode($res); else { http_response_code(404); echo json_encode(["message"=>"Agencia no encontrada"]); }
        break;

    // ===== Insertar =====
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!isset($_POST["nombre"])) {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Faltan parámetros requeridos (nombre)."]);
                break;
            }
            $nombre = trim(strip_tags($_POST["nombre"]));
            $estado = isset($_POST["estado"]) ? intval($_POST["estado"]) : 1;
            if ($nombre === '') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"El nombre es requerido."]);
                break;
            }

            $res = $agencia->insertar($nombre, $estado);
            if (is_numeric($res)) {
                echo json_encode(["success"=>true, "idAgencia"=>intval($res)]);
            } else {
                // Posible conflicto por UNIQUE(nombre)
                $status = 500;
                if (stripos((string)$res, 'Duplicate') !== false) { $status = 409; }
                http_response_code($status);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar la agencia", "error"=>$res]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // ===== Actualizar =====
    case 'actualizar':
        $idAgencia = isset($_POST["idAgencia"]) ? intval($_POST["idAgencia"]) : 0;
        $nombre    = isset($_POST["nombre"]) ? trim(strip_tags($_POST["nombre"])) : '';
        $estado    = isset($_POST["estado"]) ? intval($_POST["estado"]) : null;
        if ($idAgencia<=0 || $nombre==='' || $estado===null) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }
        $res = $agencia->actualizar($idAgencia, $nombre, $estado);
        if (is_numeric($res)) {
            echo json_encode(["success"=>true, "idAgencia"=>intval($res)]);
        } else {
            $status = 500;
            if (stripos((string)$res, 'Duplicate') !== false) { $status = 409; }
            http_response_code($status);
            echo json_encode(["success"=>false, "message"=>"No se pudo actualizar la agencia", "error"=>$res]);
        }
        break;

    // ===== Eliminar =====
    case 'eliminar':
        $idAgencia = isset($_POST["idAgencia"]) ? intval($_POST["idAgencia"]) : 0;
        if ($idAgencia <= 0) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAgencia inválido"]); break; }
        $res = $agencia->eliminar($idAgencia);
        if ($res === 1 || $res === '1') {
            echo json_encode(["success"=>true]);
        } elseif ($res === 0 || $res === '0') {
            http_response_code(404);
            echo json_encode(["success"=>false, "message"=>"No existe"]);
        } else {
            // Error: puede ser por FK 1451 u otro
            $status = 500;
            if (is_string($res) && stripos($res, 'relacionados') !== false) { $status = 409; }
            http_response_code($status);
            echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$res]);
        }
        break;

    // ===== Cambiar estado =====
    case 'activar':
        $idAgencia = isset($_POST["idAgencia"]) ? intval($_POST["idAgencia"]) : 0;
        if ($idAgencia <= 0) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAgencia inválido"]); break; }
        $res = $agencia->activar($idAgencia);
        echo json_encode(["success"=> is_numeric($res) && intval($res) >= 0, "affected"=> intval($res)]);
        break;

    case 'desactivar':
        $idAgencia = isset($_POST["idAgencia"]) ? intval($_POST["idAgencia"]) : 0;
        if ($idAgencia <= 0) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAgencia inválido"]); break; }
        $res = $agencia->desactivar($idAgencia);
        echo json_encode(["success"=> is_numeric($res) && intval($res) >= 0, "affected"=> intval($res)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message"=>"Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar"]);
        break;
}
