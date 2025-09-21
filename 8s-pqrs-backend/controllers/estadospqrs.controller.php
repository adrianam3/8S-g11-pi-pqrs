<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de Estados PQRS
require_once('../models/estadospqrs.model.php');
error_reporting(0);

$model = new EstadosPqrs();

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
        $limit       = (int) param('limit', 100);
        $offset      = (int) param('offset', 0);
        $soloActivos = param('soloActivos', null); // 1|0|null
        $q           = param('q', null);

        $datos = $model->todos($limit, $offset, $soloActivos, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron estados.", "data" => []]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $idEstado = filter_var(param('idEstado', null), FILTER_VALIDATE_INT);
        if (!$idEstado) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEstado inválido"]); break; }
        $res = $model->uno($idEstado);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Estado no encontrado"]); }
        break;

    // === INSERTAR
    case 'insertar':
        try {
            $nombre = trim((string)param('nombre', ''));
            $orden  = (int) param('orden', 0);
            $estado = (int) param('estado', 1);

            if ($nombre === '') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"El nombre es requerido"]);
                break;
            }

            $res = $model->insertar($nombre, $orden, $estado);
            if (is_numeric($res)) {
                echo json_encode(["success"=>true, "idEstado"=>(int)$res]);
            } else {
                http_response_code(409);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$res]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR
    case 'actualizar':
        try {
            $idEstado = filter_var(param('idEstado', null), FILTER_VALIDATE_INT);
            $nombre   = trim((string)param('nombre', ''));
            $orden    = (int) param('orden', 0);
            $estado   = (int) param('estado', 1);

            if (!$idEstado || $nombre === '') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
                break;
            }

            $res = $model->actualizar($idEstado, $nombre, $orden, $estado);
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
        $idEstado = filter_var(param('idEstado', null), FILTER_VALIDATE_INT);
        if (!$idEstado) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEstado inválido"]); break; }

        $res = $model->eliminar($idEstado);
        if ($res === 1 || $res === '1') { echo json_encode(["success"=>true]); }
        else if ($res === 0 || $res === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(409); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$res]); }
        break;

    // === ACTIVAR / DESACTIVAR
    case 'activar':
        $idEstado = filter_var(param('idEstado', null), FILTER_VALIDATE_INT);
        if (!$idEstado) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEstado inválido"]); break; }
        $res = $model->activar($idEstado);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idEstado = filter_var(param('idEstado', null), FILTER_VALIDATE_INT);
        if (!$idEstado) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEstado inválido"]); break; }
        $res = $model->desactivar($idEstado);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR (paginación)
    case 'contar':
        $soloActivos = param('soloActivos', null);
        $q           = param('q', null);
        $total = $model->contar($soloActivos, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar"]);
        break;
}
