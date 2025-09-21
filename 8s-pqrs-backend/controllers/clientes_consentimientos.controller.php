<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de clientes_consentimientos
require_once('../models/clientes_consentimientos.model.php');
error_reporting(0);

$model = new ClientesConsentimientos();

// Helpers JSON/POST/GET
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

// Validar origen
function validar_origen($o): bool {
    $allow = ['ENCUESTA','PORTAL','AGENTE'];
    return in_array(strtoupper((string)$o), $allow, true);
}

switch ($_GET["op"] ?? '') {

    // === LISTAR
    case 'todos':
        $limit    = (int) (param('limit', 100));
        $offset   = (int) (param('offset', 0));
        $idCliente= param('idCliente', null);
        $aceptado = param('aceptado', null);
        $origen   = param('origen', null);
        $desde    = param('desde', null);
        $hasta    = param('hasta', null);
        $q        = param('q', null);

        $datos = $model->todos($limit, $offset, $idCliente, $aceptado, $origen, $desde, $hasta, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode(["data"=>$datos]);
        } else {
            http_response_code(200);
            echo json_encode(["message" => "No se encontraron consentimientos.", "data" => []]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $id = filter_var(param('id', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro id inválido"]); break; }
        $res = $model->uno($id);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Consentimiento no encontrado"]); }
        break;

    // === ÚLTIMO POR CLIENTE
    case 'ultimoPorCliente':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $model->ultimoPorCliente($idCliente);
        if ($res) { echo json_encode($res); }
        else { http_response_code(200); echo json_encode(["message"=>"Sin registros", "data"=>null]); }
        break;

    // === INSERTAR
    case 'insertar':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        $aceptado  = param('aceptado', null);
        $origen    = strtoupper((string)param('origen', ''));
        $ip        = param('ip', null);
        $userAgent = param('userAgent', null);
        $fecha     = param('fecha', null); // opcional

        if (!$idCliente || !is_numeric($aceptado) || !validar_origen($origen)) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos (idCliente, aceptado, origen)"]);
            break;
        }

        $aceptado = (int)$aceptado;
        $datos = $model->insertar($idCliente, $aceptado, $origen, $ip, $userAgent, $fecha);
        if (is_numeric($datos)) {
            echo json_encode(["success"=>true, "id"=>(int)$datos]);
        } else {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$datos]);
        }
        break;

    // === ACTUALIZAR
    case 'actualizar':
        $id        = filter_var(param('id', null), FILTER_VALIDATE_INT);
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        $aceptado  = param('aceptado', null);
        $origen    = strtoupper((string)param('origen', ''));
        $ip        = param('ip', null);
        $userAgent = param('userAgent', null);
        $fecha     = param('fecha', null);

        if (!$id || !$idCliente || !is_numeric($aceptado) || !validar_origen($origen)) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $aceptado = (int)$aceptado;
        $datos = $model->actualizar($id, $idCliente, $aceptado, $origen, $ip, $userAgent, $fecha);
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
        $id = filter_var(param('id', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro id inválido"]); break; }
        $datos = $model->eliminar($id);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === CONTAR
    case 'contar':
        $idCliente= param('idCliente', null);
        $aceptado = param('aceptado', null);
        $origen   = param('origen', null);
        $desde    = param('desde', null);
        $hasta    = param('hasta', null);
        $q        = param('q', null);

        $total = $model->contar($idCliente, $aceptado, $origen, $desde, $hasta, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|ultimoPorCliente|insertar|actualizar|eliminar|contar"]);
        break;
}
