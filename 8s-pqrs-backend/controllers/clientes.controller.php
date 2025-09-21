<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de Clientes
//require_once('revisarsesion.controller.php');
require_once('../models/clientes.model.php');
error_reporting(0);

$clientes = new Clientes();

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

    // === LISTAR ===
    case 'todos':
        $limit          = (int) (param('limit', 100));
        $offset         = (int) (param('offset', 0));
        $soloActivos    = param('soloActivos', null); // 1|0|null
        $q              = param('q', null);
        $bloqueado      = param('bloqueado', null); // 1|0|null
        $consentimiento = param('consentimiento', null); // 1|0|null

        $datos = $clientes->todos($limit, $offset, $soloActivos, $q, $bloqueado, $consentimiento);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron clientes."]);
        }
        break;

    // === OBTENER UNO ===
    case 'uno':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $clientes->uno($idCliente);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Cliente no encontrado"]); }
        break;

    // === INSERTAR ===
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idClienteErp        = param('idClienteErp', null);
            $cedula              = trim((string) param('cedula'));
            $nombres             = trim((string) param('nombres'));
            $apellidos           = trim((string) param('apellidos'));
            $telefono            = param('telefono', null);
            $celular             = param('celular', null);
            $email               = trim((string) param('email'));
            $consentimientoDatos = (int) (param('consentimientoDatos', 1));
            $fechaConsentimiento = param('fechaConsentimiento', null); // opcional (ej. '2025-09-09 12:00:00')
            $bloqueadoEncuestas  = (int) (param('bloqueadoEncuestas', 0));
            $estado              = (int) (param('estado', 1));

            if ($cedula==='' || $nombres==='' || $apellidos==='' || $email==='') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Faltan parámetros requeridos"]);
                break;
            }

            $datos = $clientes->insertar($idClienteErp, $cedula, $nombres, $apellidos, $telefono, $celular, $email, $consentimientoDatos, $fechaConsentimiento, $bloqueadoEncuestas, $estado);
            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idCliente"=>(int)$datos]);
            } else {
                $num = filter_var($datos, FILTER_VALIDATE_INT);
                if ($num !== false) {
                    echo json_encode(["success"=>true, "idCliente"=>(int)$num]);
                } else {
                    $status = 500;
                    if (is_string($datos) && stripos($datos, 'Duplicate') !== false) { $status = 409; }
                    http_response_code($status);
                    echo json_encode(["success"=>false, "message"=>"No se pudo insertar el cliente", "error"=>$datos]);
                }
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR ===
    case 'actualizar':
        $idCliente           = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        $idClienteErp        = param('idClienteErp', null);
        $cedula              = trim((string) param('cedula'));
        $nombres             = trim((string) param('nombres'));
        $apellidos           = trim((string) param('apellidos'));
        $telefono            = param('telefono', null);
        $celular             = param('celular', null);
        $email               = trim((string) param('email'));
        $consentimientoDatos = (int) (param('consentimientoDatos', 1));
        $fechaConsentimiento = param('fechaConsentimiento', null);
        $bloqueadoEncuestas  = (int) (param('bloqueadoEncuestas', 0));
        $estado              = (int) (param('estado', 1));

        if (!$idCliente || $cedula==='' || $nombres==='' || $apellidos==='' || $email==='') {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $clientes->actualizar($idCliente, $idClienteErp, $cedula, $nombres, $apellidos, $telefono, $celular, $email, $consentimientoDatos, $fechaConsentimiento, $bloqueadoEncuestas, $estado);
        if (is_array($datos) && ($datos['status'] ?? '') === 'error') {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$datos['error'] ?? '']);
            break;
        }
        $affected = is_numeric($datos) ? (int)$datos : 0;
        echo json_encode(["success"=>true, "affected"=>$affected]);
        break;

    // === ELIMINAR ===
    case 'eliminar':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $datos = $clientes->eliminar($idCliente);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { $status = (is_string($datos) && stripos($datos, 'dependen') !== false) ? 409 : 500; http_response_code($status); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === ACTIVAR / DESACTIVAR ===
    case 'activar':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $clientes->activar($idCliente);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $clientes->desactivar($idCliente);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === BLOQUEAR / DESBLOQUEAR encuestas ===
    case 'bloquear':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $clientes->bloquear($idCliente);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desbloquear':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $clientes->desbloquear($idCliente);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONSENTIR / REVOCAR tratamiento de datos ===
    case 'consentir':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $clientes->consentir($idCliente);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'revocar':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $clientes->revocar($idCliente);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR (para paginación) ===
    case 'contar':
        $soloActivos    = param('soloActivos', null);
        $q              = param('q', null);
        $bloqueado      = param('bloqueado', null);
        $consentimiento = param('consentimiento', null);
        $total = $clientes->contar($soloActivos, $q, $bloqueado, $consentimiento);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // === DEPENDENCIAS ===
    case 'dependencias':
        $idCliente = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        if (!$idCliente) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCliente inválido"]); break; }
        $res = $clientes->dependencias($idCliente);
        echo json_encode($res);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|bloquear|desbloquear|consentir|revocar|contar|dependencias"]);
        break;
}
