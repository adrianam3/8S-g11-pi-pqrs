<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de Atenciones
// require_once('revisarsesion.controller.php');
require_once('../models/atenciones.model.php');
error_reporting(0);

$atenciones = new Atenciones();

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
        $limit       = (int) (param('limit', 100));
        $offset      = (int) (param('offset', 0));
        $soloActivas = param('soloActivas', null); // 1|0|null
        $idCliente   = param('idCliente', null);
        $idAgencia   = param('idAgencia', null);
        $fechaDesde  = param('fechaDesde', null); // 'YYYY-MM-DD'
        $fechaHasta  = param('fechaHasta', null); // 'YYYY-MM-DD'
        $q           = param('q', null);

        $datos = $atenciones->todos($limit, $offset, $soloActivas, $idCliente, $idAgencia, $fechaDesde, $fechaHasta, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron atenciones."]);
        }
        break;

    // === OBTENER UNO ===
    case 'uno':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $res = $atenciones->uno($idAtencion);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Atención no encontrada"]); }
        break;

    // === INSERTAR ===
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idCliente       = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
            $idAgencia       = param('idAgencia', null);
            $idAgencia       = is_null($idAgencia) || $idAgencia === '' ? null : (int)$idAgencia;
            $fechaAtencion   = (string)param('fechaAtencion', '');
            $numeroDocumento = trim((string)param('numeroDocumento', ''));
            $tipoDocumento   = trim((string)param('tipoDocumento', ''));
            $numeroFactura   = param('numeroFactura', null);
            $estado          = (int) (param('estado', 1));

            if (!$idCliente || $fechaAtencion==='' || $numeroDocumento==='' || $tipoDocumento==='') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Faltan parámetros requeridos"]);
                break;
            }

            $datos = $atenciones->insertar($idCliente, $idAgencia, $fechaAtencion, $numeroDocumento, $tipoDocumento, $numeroFactura, $estado);
            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idAtencion"=>(int)$datos]);
            } else {
                $num = filter_var($datos, FILTER_VALIDATE_INT);
                if ($num !== false) {
                    echo json_encode(["success"=>true, "idAtencion"=>(int)$num]);
                } else {
                    $status = 500;
                    if (is_string($datos) && stripos($datos, 'Duplicate') !== false) { $status = 409; }
                    http_response_code($status);
                    echo json_encode(["success"=>false, "message"=>"No se pudo insertar la atención", "error"=>$datos]);
                }
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR ===
    case 'actualizar':
        $idAtencion      = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        $idCliente       = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        $idAgencia       = param('idAgencia', null);
        $idAgencia       = is_null($idAgencia) || $idAgencia === '' ? null : (int)$idAgencia;
        $fechaAtencion   = (string)param('fechaAtencion', '');
        $numeroDocumento = trim((string)param('numeroDocumento', ''));
        $tipoDocumento   = trim((string)param('tipoDocumento', ''));
        $numeroFactura   = param('numeroFactura', null);
        $estado          = (int) (param('estado', 1));

        if (!$idAtencion || !$idCliente || $fechaAtencion==='' || $numeroDocumento==='' || $tipoDocumento==='') {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $atenciones->actualizar($idAtencion, $idCliente, $idAgencia, $fechaAtencion, $numeroDocumento, $tipoDocumento, $numeroFactura, $estado);
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
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $datos = $atenciones->eliminar($idAtencion);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { $status = (is_string($datos) && stripos($datos, 'dependen') !== false) ? 409 : 500; http_response_code($status); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === ACTIVAR / DESACTIVAR ===
    case 'activar':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $res = $atenciones->activar($idAtencion);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $res = $atenciones->desactivar($idAtencion);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR ===
    case 'contar':
        $soloActivas = param('soloActivas', null);
        $idCliente   = param('idCliente', null);
        $idAgencia   = param('idAgencia', null);
        $fechaDesde  = param('fechaDesde', null);
        $fechaHasta  = param('fechaHasta', null);
        $q           = param('q', null);
        $total = $atenciones->contar($soloActivas, $idCliente, $idAgencia, $fechaDesde, $fechaHasta, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // === DEPENDENCIAS ===
    case 'dependencias':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $res = $atenciones->dependencias($idAtencion);
        echo json_encode($res);
        break;

    // === UPSERT CLIENTE + ATENCIÓN (SP) ===
    case 'upsert':
    case 'upsert_cliente_y_atencion':
        header('Content-Type: application/json; charset=utf-8');
        try {
            // Datos del cliente
            $idClienteErp    = param('idClienteErp', null); // opcional
            $cedula          = trim((string)param('cedula', ''));
            $nombres         = trim((string)param('nombres', ''));
            $apellidos       = trim((string)param('apellidos', ''));
            $email           = trim((string)param('email', ''));
            $telefono        = param('telefono', null);
            $celular         = param('celular', null);

            // Datos de atención
            $idAgencia       = param('idAgencia', null); // int
            $idAgencia       = is_null($idAgencia) || $idAgencia === '' ? null : (int)$idAgencia;
            $fechaAtencion   = (string)param('fechaAtencion', ''); // YYYY-MM-DD
            $numeroDocumento = trim((string)param('numeroDocumento', ''));
            $tipoDocumento   = trim((string)param('tipoDocumento', ''));
            $numeroFactura   = param('numeroFactura', null); // opcional
            $idCanal         = param('idCanal', null);       // opcional int (validado en SP)
            $idCanal         = is_null($idCanal) || $idCanal === '' ? null : (int)$idCanal;
            $detalle         = param('detalle', null);       // opcional MEDIUMTEXT

            // Validación mínima (el SP valida más cosas)
            if ($cedula==='' || $nombres==='' || $apellidos==='' || !$idAgencia || $fechaAtencion==='' || $numeroDocumento==='' || $tipoDocumento==='') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Faltan parámetros requeridos"]);
                break;
            }

            $res = $atenciones->upsertClienteYAtencion(
                $idClienteErp,
                $cedula,
                $nombres,
                $apellidos,
                $email,
                $telefono,
                $celular,
                $idAgencia,
                $fechaAtencion,
                $numeroDocumento,
                $tipoDocumento,
                $numeroFactura,
                $idCanal,
                $detalle
            );

            if (is_array($res)) {
                echo json_encode([
                    "success"     => true,
                    "idCliente"   => $res['idCliente'],
                    "idAtencion"  => $res['idAtencion']
                ]);
            } else {
                // Es string => error
                $status = 500;
                if (stripos($res, 'idCanal no existe') !== false) { $status = 400; }
                http_response_code($status);
                echo json_encode(["success"=>false, "message"=>"No se pudo procesar el upsert", "error"=>$res]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|dependencias|upsert"]);
        break;
}
