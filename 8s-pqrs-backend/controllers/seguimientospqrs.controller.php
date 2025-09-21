<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de Seguimientos PQRS
require_once('../models/seguimientospqrs.model.php');
error_reporting(0);

// Instancia
$model = new SeguimientosPqrs();

// Helpers de entrada
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
        $limit        = (int) param('limit', 100);
        $offset       = (int) param('offset', 0);
        $idPqrs       = param('idPqrs', null);
        $idUsuario    = param('idUsuario', null);
        $cambioEstado = param('cambioEstado', null);
        $desde        = param('desde', null);
        $hasta        = param('hasta', null);
        $q            = param('q', null);

        $res = $model->todos($limit, $offset, $idPqrs, $idUsuario, $cambioEstado, $desde, $hasta, $q);
        if (is_array($res) && count($res) > 0) {
            echo json_encode($res);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron seguimientos."]);
        }
        break;

    // === LISTAR POR PQRS (atajo)
    case 'porPqrs':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $limit  = (int) param('limit', 100);
        $offset = (int) param('offset', 0);

        if (!$idPqrs) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }

        $res = $model->porPqrs($idPqrs, $limit, $offset);
        if (is_array($res) && count($res) > 0) {
            echo json_encode($res);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron seguimientos."]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $id = filter_var(param('idSeguimiento', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idSeguimiento inválido"]); break; }
        $res = $model->uno($id);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Seguimiento no encontrado"]); }
        break;

    // === INSERTAR
    case 'insertar':
        try {
            $idPqrs       = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
            $idUsuario    = param('idUsuario', null);
            $comentario   = (string) param('comentario', null);
            $cambioEstado = param('cambioEstado', null);
            $adjuntosUrl  = param('adjuntosUrl', null);

            if (!$idPqrs) {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Parámetro idPqrs es requerido"]);
                break;
            }

            // Validaciones/normalizaciones mínimas
            $idUsuario    = is_null($idUsuario)    ? null : (int)$idUsuario;
            $cambioEstado = is_null($cambioEstado) ? null : (int)$cambioEstado;
            $comentario   = ($comentario === '') ? null : $comentario;
            $adjuntosUrl  = ($adjuntosUrl === '') ? null : $adjuntosUrl;

            $res = $model->insertar($idPqrs, $idUsuario, $comentario, $cambioEstado, $adjuntosUrl);
            if (is_numeric($res)) {
                echo json_encode(["success"=>true, "idSeguimiento"=>(int)$res]);
            } else {
                http_response_code(500);
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
            $idSeguimiento = filter_var(param('idSeguimiento', null), FILTER_VALIDATE_INT);
            if (!$idSeguimiento) { http_response_code(400); echo json_encode(["success"=>false,"message"=>"Parámetro idSeguimiento inválido"]); break; }

            $comentario   = param('comentario', null);
            $cambioEstado = param('cambioEstado', null);
            $adjuntosUrl  = param('adjuntosUrl', null);
            $idUsuario    = param('idUsuario', null);

            $comentario   = is_null($comentario)   ? null : (string)$comentario;
            $adjuntosUrl  = is_null($adjuntosUrl)  ? null : (string)$adjuntosUrl;
            $cambioEstado = is_null($cambioEstado) ? null : (int)$cambioEstado;
            $idUsuario    = is_null($idUsuario)    ? null : (int)$idUsuario;

            $res = $model->actualizar($idSeguimiento, $comentario, $cambioEstado, $adjuntosUrl, $idUsuario);
            if (is_numeric($res)) {
                echo json_encode(["success"=>true, "affected"=>(int)$res]);
            } else {
                http_response_code(500);
                echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$res]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ELIMINAR
    case 'eliminar':
        $id = filter_var(param('idSeguimiento', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idSeguimiento inválido"]); break; }

        $res = $model->eliminar($id);
        if ($res === 1 || $res === '1') { echo json_encode(["success"=>true]); }
        else if ($res === 0 || $res === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$res]); }
        break;

    // === CONTAR
    case 'contar':
        $idPqrs       = param('idPqrs', null);
        $idUsuario    = param('idUsuario', null);
        $cambioEstado = param('cambioEstado', null);
        $desde        = param('desde', null);
        $hasta        = param('hasta', null);
        $q            = param('q', null);

        $total = $model->contar($idPqrs, $idUsuario, $cambioEstado, $desde, $hasta, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            "message" => "Operación no soportada. Usa op=todos|porPqrs|uno|insertar|actualizar|eliminar|contar"
        ]);
        break;
}
