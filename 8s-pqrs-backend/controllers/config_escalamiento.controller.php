<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de Configuración de Escalamiento
require_once('../models/config_escalamiento.model.php');
error_reporting(0);

$model = new ConfigEscalamiento();

// Helpers
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function param($key, $default=null) {
    static $json = null;
    if ($json === null) { $json = read_json_body(); }
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    if (isset($json[$key]))  return $json[$key];
    return $default;
}

switch ($_GET["op"] ?? '') {

    // LISTAR
    case 'todos':
        $limit      = (int) param('limit', 100);
        $offset     = (int) param('offset', 0);
        $idAgencia  = param('idAgencia', null);
        $idEncuesta = param('idEncuesta', null);
        $nivel      = param('nivel', null);
        $estado     = param('estado', null); // 1|0|null
        $q          = param('q', null);

        $datos = $model->todos($limit, $offset, $idAgencia, $idEncuesta, $nivel, $estado, $q);
        if (is_array($datos) && count($datos) > 0) echo json_encode($datos);
        else { http_response_code(404); echo json_encode(["message"=>"No se encontraron configuraciones."]); }
        break;

    // OBTENER UNO
    case 'uno':
        $idConfig = filter_var(param('idConfig', null), FILTER_VALIDATE_INT);
        if (!$idConfig) { http_response_code(400); echo json_encode(["message"=>"Parámetro idConfig inválido"]); break; }
        $res = $model->uno($idConfig);
        if ($res) echo json_encode($res);
        else { http_response_code(404); echo json_encode(["message"=>"Configuración no encontrada"]); }
        break;

    // INSERTAR
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idAgencia     = filter_var(param('idAgencia'), FILTER_VALIDATE_INT);
            $idEncuesta    = filter_var(param('idEncuesta'), FILTER_VALIDATE_INT);
            $nivel         = filter_var(param('nivel'), FILTER_VALIDATE_INT);
            $idResponsable = filter_var(param('idResponsable'), FILTER_VALIDATE_INT);
            $horasSLA      = filter_var(param('horasSLA'), FILTER_VALIDATE_INT);
            $estado        = (int) param('estado', 1);

            if (!$idAgencia || !$idEncuesta || !$nivel || !$idResponsable || $horasSLA === false || $horasSLA === null) {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Parámetros requeridos inválidos"]);
                break;
            }

            $datos = $model->insertar($idAgencia, $idEncuesta, $nivel, $idResponsable, $horasSLA, $estado);

            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idConfig"=>(int)$datos]);
            } else {
                http_response_code(500);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$datos]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // ACTUALIZAR
    case 'actualizar':
        $idConfig     = filter_var(param('idConfig', null), FILTER_VALIDATE_INT);
        $idAgencia    = filter_var(param('idAgencia', null), FILTER_VALIDATE_INT);
        $idEncuesta   = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        $nivel        = filter_var(param('nivel', null), FILTER_VALIDATE_INT);
        $idResponsable= filter_var(param('idResponsable', null), FILTER_VALIDATE_INT);
        $horasSLA     = filter_var(param('horasSLA', null), FILTER_VALIDATE_INT);
        $estado       = (int) param('estado', 1);

        if (!$idConfig || !$idAgencia || !$idEncuesta || !$nivel || !$idResponsable || $horasSLA === false || $horasSLA === null) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $model->actualizar($idConfig, $idAgencia, $idEncuesta, $nivel, $idResponsable, $horasSLA, $estado);

        if (is_array($datos) && ($datos['status'] ?? '') === 'error') {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$datos['error'] ?? '']);
            break;
        }
        $affected = is_numeric($datos) ? (int)$datos : 0;
        echo json_encode(["success"=>true, "affected"=>$affected]);
        break;

    // ELIMINAR
    case 'eliminar':
        $idConfig = filter_var(param('idConfig', null), FILTER_VALIDATE_INT);
        if (!$idConfig) { http_response_code(400); echo json_encode(["message"=>"Parámetro idConfig inválido"]); break; }
        $datos = $model->eliminar($idConfig);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(409); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$datos]); }
        break;

    // ACTIVAR / DESACTIVAR
    case 'activar':
        $idConfig = filter_var(param('idConfig', null), FILTER_VALIDATE_INT);
        if (!$idConfig) { http_response_code(400); echo json_encode(["message"=>"Parámetro idConfig inválido"]); break; }
        $res = $model->activar($idConfig);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idConfig = filter_var(param('idConfig', null), FILTER_VALIDATE_INT);
        if (!$idConfig) { http_response_code(400); echo json_encode(["message"=>"Parámetro idConfig inválido"]); break; }
        $res = $model->desactivar($idConfig);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // CONTAR
    case 'contar':
        $idAgencia  = param('idAgencia', null);
        $idEncuesta = param('idEncuesta', null);
        $nivel      = param('nivel', null);
        $estado     = param('estado', null);
        $q          = param('q', null);
        $total = $model->contar($idAgencia, $idEncuesta, $nivel, $estado, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // NIVELES POR AGENCIA+ENCUESTA
    case 'niveles':
        $idAgencia  = filter_var(param('idAgencia', null), FILTER_VALIDATE_INT);
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idAgencia || !$idEncuesta) {
            http_response_code(400);
            echo json_encode(["message"=>"Parámetros idAgencia e idEncuesta son requeridos"]);
            break;
        }
        $rows = $model->nivelesPorEncuestaAgencia($idAgencia, $idEncuesta);
        echo json_encode(is_array($rows) ? $rows : []);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            "message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|niveles"
        ]);
        break;
}
