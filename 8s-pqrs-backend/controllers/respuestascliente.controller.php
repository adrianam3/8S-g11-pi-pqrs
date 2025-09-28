<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de Respuestas del Cliente
require_once('../models/respuestascliente.model.php');
error_reporting(0);

$model = new RespuestasCliente();

// Helpers
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function param($key, $default=null) {
    static $json = null;
    if ($json === null) $json = read_json_body();
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    if (isset($json[$key]))  return $json[$key];
    return $default;
}

switch ($_GET["op"] ?? '') {

    // === LISTAR
    case 'todos':
        $limit         = (int) param('limit', 100);
        $offset        = (int) param('offset', 0);
        $soloActivos   = param('soloActivos', null);
        $idProgEncuesta= param('idProgEncuesta', null);
        $idPregunta    = param('idPregunta', null);
        $generaPqr     = param('generaPqr', null);
        $idCategoria   = param('idCategoria', null);
        $q             = param('q', null);

        $datos = $model->todos($limit, $offset, $soloActivos, $idProgEncuesta, $idPregunta, $generaPqr, $idCategoria, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron respuestas.", "data" => []]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $idRespCliente = filter_var(param('idRespCliente', null), FILTER_VALIDATE_INT);
        if (!$idRespCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idRespCliente inválido"]); break; }
        $res = $model->uno($idRespCliente);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Respuesta no encontrada"]); }
        break;

    // === INSERTAR
    case 'insertar':
        try {
            $idProgEncuesta = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
            $idPregunta     = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
            $idOpcion       = param('idOpcion', null);
            $valorNumerico  = param('valorNumerico', null);
            $valorTexto     = param('valorTexto', null);
            $comentario     = param('comentario', null);
            $generaPqr      = param('generaPqr', null);
            $idCategoria    = param('idCategoria', null);
            $estado         = (int) param('estado', 1);

            if (!$idProgEncuesta || !$idPregunta) {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"idProgEncuesta e idPregunta son requeridos"]);
                break;
            }

            $res = $model->insertar($idProgEncuesta, $idPregunta, $idOpcion, $valorNumerico, $valorTexto, $comentario, $generaPqr, $idCategoria, $estado);
            if (is_numeric($res)) {
                echo json_encode(["success"=>true, "idRespCliente"=>(int)$res]);
            } else {
                http_response_code(409);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$res]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === UPSERT (insertar o actualizar por (idProgEncuesta,idPregunta))
    case 'upsert':
        try {
            $idProgEncuesta = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
            $idPregunta     = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
            $idOpcion       = param('idOpcion', null);
            $valorNumerico  = param('valorNumerico', null);
            $valorTexto     = param('valorTexto', null);
            $comentario     = param('comentario', null);
            $generaPqr      = param('generaPqr', null);
            $idCategoria    = param('idCategoria', null);
            $estado         = (int) param('estado', 1);

            if (!$idProgEncuesta || !$idPregunta) {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"idProgEncuesta e idPregunta son requeridos"]);
                break;
            }

            $res = $model->upsert($idProgEncuesta, $idPregunta, $idOpcion, $valorNumerico, $valorTexto, $comentario, $generaPqr, $idCategoria, $estado);
            if (is_numeric($res)) {
                echo json_encode(["success"=>true, "idRespCliente"=>(int)$res]);
            } else {
                http_response_code(409);
                echo json_encode(["success"=>false, "message"=>"No se pudo guardar", "error"=>$res]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR
    case 'actualizar':
        try {
            $idRespCliente = filter_var(param('idRespCliente', null), FILTER_VALIDATE_INT);
            if (!$idRespCliente) { http_response_code(400); echo json_encode(["success"=>false, "message"=>"idRespCliente es requerido"]); break; }

            $idOpcion       = param('idOpcion', null);
            $valorNumerico  = param('valorNumerico', null);
            $valorTexto     = param('valorTexto', null);
            $comentario     = param('comentario', null);
            $generaPqr      = param('generaPqr', null);
            $idCategoria    = param('idCategoria', null);
            $estado         = (int) param('estado', 1);

            $res = $model->actualizar($idRespCliente, $idOpcion, $valorNumerico, $valorTexto, $comentario, $generaPqr, $idCategoria, $estado);
            if (is_numeric($res)) {
                echo json_encode(["success"=>true, "affected"=>(int)$res]);
            } else {
                http_response_code(409);
                echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$res]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ELIMINAR
    case 'eliminar':
        $idRespCliente = filter_var(param('idRespCliente', null), FILTER_VALIDATE_INT);
        if (!$idRespCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idRespCliente inválido"]); break; }

        $res = $model->eliminar($idRespCliente);
        if ($res === 1 || $res === '1') { echo json_encode(["success"=>true]); }
        else if ($res === 0 || $res === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(409); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$res]); }
        break;

    // === ACTIVAR / DESACTIVAR
    case 'activar':
        $idRespCliente = filter_var(param('idRespCliente', null), FILTER_VALIDATE_INT);
        if (!$idRespCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idRespCliente inválido"]); break; }
        $res = $model->activar($idRespCliente);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idRespCliente = filter_var(param('idRespCliente', null), FILTER_VALIDATE_INT);
        if (!$idRespCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idRespCliente inválido"]); break; }
        $res = $model->desactivar($idRespCliente);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR
    case 'contar':
        $soloActivos    = param('soloActivos', null);
        $idProgEncuesta = param('idProgEncuesta', null);
        $idPregunta     = param('idPregunta', null);
        $generaPqr      = param('generaPqr', null);
        $idCategoria    = param('idCategoria', null);
        $q              = param('q', null);
        $total = $model->contar($soloActivos, $idProgEncuesta, $idPregunta, $generaPqr, $idCategoria, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // === LISTAR POR PROGRAMACIÓN
    case 'porProgramacion':
        $idProgEncuesta = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idProgEncuesta) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        $datos = $model->porProgramacion($idProgEncuesta);
        if (is_array($datos) && count($datos) > 0) { echo json_encode($datos); }
        else { http_response_code(404); echo json_encode(["message"=>"No se encontraron respuestas.", "data"=>[]]); }
        break;

    case 'guardar_lote':
    try {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"JSON inválido"]);
            break;
        }

        $idProgEncuesta = isset($body['idProgEncuesta']) ? (int)$body['idProgEncuesta'] : null;
        $respuestas     = isset($body['respuestas']) && is_array($body['respuestas']) ? $body['respuestas'] : null;

        if (!$idProgEncuesta || !$respuestas || count($respuestas) === 0) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"idProgEncuesta y respuestas son requeridos"]);
            break;
        }

        // atomic=true => si una falla, se hace rollback
        $atomic = isset($body['atomic']) ? (bool)$body['atomic'] : true;

        $result = $model->insertarLote($idProgEncuesta, $respuestas, $atomic);

        if ($result['success']) {
            echo json_encode([
                "success" => true,
                "insertados" => $result['insertados'],
                "message" => $result['message']
            ]);
        } else {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => $result['message'],
                "errores" => $result['errores'] ?? []
            ]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(["success"=>false, "message"=>"Error inesperado", "error"=>$e->getMessage()]);
    }
    break;


    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|insertar|upsert|actualizar|eliminar|activar|desactivar|contar|porProgramacion"]);
        break;
}
