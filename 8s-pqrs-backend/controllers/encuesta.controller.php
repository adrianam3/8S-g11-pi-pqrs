<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de encuestas (plantillas)
//require_once('email.controller.php');
//require_once('revisarsesion.controller.php');
require_once('../models/encuesta.model.php');
// error_reporting(0); //DESHABILITAR ERROR, DEJAR COMENTADO si se desea que se muestre el error

$encuestas = new Encuestas();

// Helpers
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function param($key, $default=null) {
    $json = read_json_body();
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    if (isset($json[$key]))  return $json[$key];
    return $default;
}

switch ($_GET["op"] ?? '') {

    // === LISTAR
    case 'todos':
        $limit       = (int) (param('limit', 100));
        $offset      = (int) (param('offset', 0));
        $soloActivas = param('soloActivas', null); // 1|0|null
        $idCanal     = param('idCanal', null);
        $q           = param('q', null);

        $datos = $encuestas->todos($limit, $offset, $soloActivas, $idCanal, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron encuestas."]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idEncuesta) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEncuesta inválido"]); break; }
        $res = $encuestas->uno($idEncuesta);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Encuesta no encontrada"]); }
        break;

    // === INSERTAR
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $nombre          = trim((string)param('nombre'));
            $asuntoCorreo    = trim((string)param('asuntoCorreo'));
            $remitenteNombre = trim((string)param('remitenteNombre'));
            $scriptInicio    = (string)param('scriptInicio');
            $scriptFinal     = param('scriptFinal', null); // puede ser null
            $idCanal         = filter_var(param('idCanal'), FILTER_VALIDATE_INT);
            $activa          = (int) (param('activa', 1));

            if ($nombre==='' || $asuntoCorreo==='' || $remitenteNombre==='' || $scriptInicio==='' || !$idCanal) {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Faltan parámetros requeridos"]);
                break;
            }

            $datos = $encuestas->insertar($nombre, $asuntoCorreo, $remitenteNombre, $scriptInicio, $scriptFinal, $idCanal, $activa);
            if (is_array($datos) && ($datos['status'] ?? '') === 'error') {
                http_response_code(500);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar la encuesta", "error"=>$datos['error'] ?? '']);
                break;
            }
            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idEncuesta"=>(int)$datos]);
            } else {
                // El modelo puede devolver string de error o id; contemplar ambos
                $num = filter_var($datos, FILTER_VALIDATE_INT);
                if ($num !== false) {
                    echo json_encode(["success"=>true, "idEncuesta"=>(int)$num]);
                } else {
                    http_response_code(500);
                    echo json_encode(["success"=>false, "message"=>"No se pudo insertar la encuesta", "error"=>$datos]);
                }
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR
    case 'actualizar':
        $idEncuesta      = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        $nombre          = trim((string)param('nombre'));
        $asuntoCorreo    = trim((string)param('asuntoCorreo'));
        $remitenteNombre = trim((string)param('remitenteNombre'));
        $scriptInicio    = (string)param('scriptInicio');
        $scriptFinal     = param('scriptFinal', null);
        $idCanal         = filter_var(param('idCanal', null), FILTER_VALIDATE_INT);
        $activa          = (int) (param('activa', 1));

        if (!$idEncuesta || $nombre==='' || $asuntoCorreo==='' || $remitenteNombre==='' || $scriptInicio==='' || !$idCanal) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $encuestas->actualizar($idEncuesta, $nombre, $asuntoCorreo, $remitenteNombre, $scriptInicio, $scriptFinal, $idCanal, $activa);
        if (is_array($datos) && ($datos['status'] ?? '') === 'error') {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$datos['error'] ?? '']);
            break;
        }
        $affected = is_numeric($datos) ? (int)$datos : 0;
        echo json_encode(["success"=>true, "affected"=>$affected]);
        break;

    // === ELIMINAR
    case 'eliminar':
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idEncuesta) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEncuesta inválido"]); break; }
        $datos = $encuestas->eliminar($idEncuesta);
        if (is_array($datos) && ($datos['status'] ?? '') === 'error') {
            http_response_code(409);
            echo json_encode(["success"=>false, "message"=>$datos['message'] ?? 'No se pudo eliminar', "error"=>$datos['error'] ?? '']);
            break;
        }
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === ACTIVAR / DESACTIVAR
    case 'activar':
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idEncuesta) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEncuesta inválido"]); break; }
        $res = $encuestas->activar($idEncuesta);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idEncuesta) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEncuesta inválido"]); break; }
        $res = $encuestas->desactivar($idEncuesta);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR (para paginación)
    case 'contar':
        $soloActivas = param('soloActivas', null);
        $idCanal     = param('idCanal', null);
        $q           = param('q', null);
        $total = $encuestas->contar($soloActivas, $idCanal, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // === DEPENDENCIAS
    case 'dependencias':
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idEncuesta) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEncuesta inválido"]); break; }
        $res = $encuestas->dependencias($idEncuesta);
        echo json_encode($res);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|dependencias"]);
        break;
}
