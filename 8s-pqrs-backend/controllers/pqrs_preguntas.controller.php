<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de pqrs_preguntas
require_once('../models/pqrs_preguntas.model.php');
error_reporting(0);

$model = new PqrsPreguntas();

// Helpers para recibir JSON/POST/GET
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
        $idPqrs      = param('idPqrs', null);
        $idPregunta  = param('idPregunta', null);
        $idCategoria = param('idCategoria', null);
        $q           = param('q', null);

        $datos = $model->todos($limit, $offset, $idPqrs, $idPregunta, $idCategoria, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode(["data"=>$datos]);
        } else {
            http_response_code(200);
            echo json_encode(["message" => "No se encontraron relaciones.", "data" => []]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $id = filter_var(param('id', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro id inválido"]); break; }
        $res = $model->uno($id);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Relación no encontrada"]); }
        break;

    // === OBTENER POR CLAVE COMPUESTA
    case 'obtenerPorCompuesto':
        $idPqrs     = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $idPregunta = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
        if (!$idPqrs || !$idPregunta) { http_response_code(400); echo json_encode(["message"=>"Parámetros inválidos"]); break; }
        $res = $model->obtenerPorCompuesto($idPqrs, $idPregunta);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Relación no encontrada"]); }
        break;

    // === INSERTAR
    case 'insertar':
        $idPqrs      = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $idPregunta  = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
        $idCategoria = param('idCategoria', null);
        if (!$idPqrs || !$idPregunta) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos (idPqrs, idPregunta)"]);
            break;
        }
        $datos = $model->insertar($idPqrs, $idPregunta, $idCategoria);
        if (is_numeric($datos)) {
            echo json_encode(["success"=>true, "id"=>(int)$datos]);
        } else {
            http_response_code(409);
            echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$datos]);
        }
        break;

    // === UPSERT
    case 'upsert':
        $idPqrs      = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $idPregunta  = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
        $idCategoria = param('idCategoria', null);
        if (!$idPqrs || !$idPregunta) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos (idPqrs, idPregunta)"]);
            break;
        }
        $datos = $model->upsert($idPqrs, $idPregunta, $idCategoria);
        if (is_numeric($datos)) {
            echo json_encode(["success"=>true, "id"=>(int)$datos]);
        } else {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo guardar", "error"=>$datos]);
        }
        break;

    // === ACTUALIZAR POR ID
    case 'actualizar':
        $id          = filter_var(param('id', null), FILTER_VALIDATE_INT);
        $idPqrs      = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $idPregunta  = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
        $idCategoria = param('idCategoria', null);
        if (!$id || !$idPqrs || !$idPregunta) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }
        $datos = $model->actualizar($id, $idPqrs, $idPregunta, $idCategoria);
        if (is_array($datos) && ($datos['status'] ?? '') === 'error') {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$datos['error'] ?? '']);
            break;
        }
        $affected = is_numeric($datos) ? (int)$datos : 0;
        echo json_encode(["success"=>true, "affected"=>$affected]);
        break;

    // === ELIMINAR POR ID
    case 'eliminar':
        $id = filter_var(param('id', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro id inválido"]); break; }
        $datos = $model->eliminar($id);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === ELIMINAR POR CLAVE COMPUESTA
    case 'eliminarPorCompuesto':
        $idPqrs     = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $idPregunta = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
        if (!$idPqrs || !$idPregunta) { http_response_code(400); echo json_encode(["message"=>"Parámetros inválidos"]); break; }
        $datos = $model->eliminarPorCompuesto($idPqrs, $idPregunta);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === CONTAR
    case 'contar':
        $idPqrs      = param('idPqrs', null);
        $idPregunta  = param('idPregunta', null);
        $idCategoria = param('idCategoria', null);
        $q           = param('q', null);
        $total = $model->contar($idPqrs, $idPregunta, $idCategoria, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|obtenerPorCompuesto|insertar|upsert|actualizar|eliminar|eliminarPorCompuesto|contar"]);
        break;
}
