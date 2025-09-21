<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de respuestasopciones (plantillas)
// require_once('revisarsesion.controller.php');
require_once('../models/respuestasopciones.model.php');
error_reporting(0);

$ro = new RespuestasOpciones();

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
        $limit   = (int) (param('limit', 100));
        $offset  = (int) (param('offset', 0));
        $idPreg  = param('idPregunta', null);
        $estado  = param('estado', null);
        $gPqr    = param('generaPqr', null);
        $reqCom  = param('requiereComentario', null);
        $q       = param('q', null);

        $datos = $ro->todos($limit, $offset, $idPreg, $estado, $gPqr, $reqCom, $q);
        if (is_array($datos)) {
            if (count($datos) > 0) echo json_encode($datos);
            else echo json_encode(["message" => "No se encontraron opciones.", "data" => []]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Error al listar opciones."]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $idOpcion = filter_var(param('idOpcion', null), FILTER_VALIDATE_INT);
        if (!$idOpcion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idOpcion inválido"]); break; }
        $res = $ro->uno($idOpcion);
        if (is_array($res)) { echo json_encode($res); }
        else if (is_null($res)) { http_response_code(404); echo json_encode(["message"=>"Opción no encontrada"]); }
        else { http_response_code(500); echo json_encode(["message"=>"Error", "error"=>$res]); }
        break;

    // === INSERTAR
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idPregunta         = filter_var(param('idPregunta'), FILTER_VALIDATE_INT);
            $etiqueta           = trim((string)param('etiqueta'));
            $valorNumerico      = param('valorNumerico', null);
            $secuenciaSiguiente = param('secuenciaSiguiente', null);
            $generaPqr          = (int) (param('generaPqr', 0));
            $requiereComentario = (int) (param('requiereComentario', 0));
            $estado             = (int) (param('estado', 1));

            if (!$idPregunta || $etiqueta === '') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Parámetros obligatorios: idPregunta y etiqueta"]);
                break;
            }

            $datos = $ro->insertar($idPregunta, $etiqueta, $valorNumerico, $secuenciaSiguiente, $generaPqr, $requiereComentario, $estado);
            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idOpcion"=>(int)$datos]);
            } else {
                http_response_code(500);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$datos]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR
    case 'actualizar':
        $idOpcion           = filter_var(param('idOpcion', null), FILTER_VALIDATE_INT);
        $idPregunta         = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
        $etiqueta           = trim((string)param('etiqueta'));
        $valorNumerico      = param('valorNumerico', null);
        $secuenciaSiguiente = param('secuenciaSiguiente', null);
        $generaPqr          = (int) (param('generaPqr', 0));
        $requiereComentario = (int) (param('requiereComentario', 0));
        $estado             = (int) (param('estado', 1));

        if (!$idOpcion || !$idPregunta || $etiqueta==='') {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $ro->actualizar($idOpcion, $idPregunta, $etiqueta, $valorNumerico, $secuenciaSiguiente, $generaPqr, $requiereComentario, $estado);
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
        $idOpcion = filter_var(param('idOpcion', null), FILTER_VALIDATE_INT);
        if (!$idOpcion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idOpcion inválido"]); break; }
        $datos = $ro->eliminar($idOpcion);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(409); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$datos]); }
        break;

    // === ACTIVAR / DESACTIVAR
    case 'activar':
        $idOpcion = filter_var(param('idOpcion', null), FILTER_VALIDATE_INT);
        if (!$idOpcion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idOpcion inválido"]); break; }
        $res = $ro->activar($idOpcion);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idOpcion = filter_var(param('idOpcion', null), FILTER_VALIDATE_INT);
        if (!$idOpcion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idOpcion inválido"]); break; }
        $res = $ro->desactivar($idOpcion);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR (para paginación)
    case 'contar':
        $idPreg  = param('idPregunta', null);
        $estado  = param('estado', null);
        $gPqr    = param('generaPqr', null);
        $reqCom  = param('requiereComentario', null);
        $q       = param('q', null);
        $total = $ro->contar($idPreg, $estado, $gPqr, $reqCom, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar"]);
        break;
}
