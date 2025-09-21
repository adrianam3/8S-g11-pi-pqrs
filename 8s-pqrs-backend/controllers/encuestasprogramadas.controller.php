<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de encuestas programadas
require_once('../models/encuestasprogramadas.model.php');
error_reporting(0); // Deshabilitar errores visibles en producción

$ep = new EncuestasProgramadas();

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
        $limit        = (int) (param('limit', 100));
        $offset       = (int) (param('offset', 0));
        $soloActivas  = param('soloActivas', null);
        $estadoEnvio  = param('estadoEnvio', null);
        $canalEnvio   = param('canalEnvio', null);
        $idEncuesta   = param('idEncuesta', null);
        $idAtencion   = param('idAtencion', null);
        $idCliente    = param('idCliente', null);
        $desde        = param('desde', null);
        $hasta        = param('hasta', null);
        $q            = param('q', null);

        $datos = $ep->todos($limit, $offset, $soloActivas, $estadoEnvio, $canalEnvio, $idEncuesta, $idAtencion, $idCliente, $desde, $hasta, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron programaciones."]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        $res = $ep->uno($id);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"No encontrado"]); }
        break;

    // === INSERTAR (opcional: se sugiere usar SP de programación)
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idEncuesta  = filter_var(param('idEncuesta'), FILTER_VALIDATE_INT);
            $idAtencion  = filter_var(param('idAtencion'), FILTER_VALIDATE_INT);
            $idCliente   = filter_var(param('idCliente'), FILTER_VALIDATE_INT);
            $fechaProg   = (string) param('fechaProgramadaInicial');
            $maxIntentos = (int) param('maxIntentos', 3);
            $proximo     = param('proximoEnvio', null);
            $estadoEnvio = param('estadoEnvio', 'PENDIENTE');
            $canalEnvio  = param('canalEnvio', null);
            $enviadoPor  = param('enviadoPor', null);
            $observ      = param('observacionEnvio', null);
            $asunto      = param('asuntoCache', null);
            $cuerpo      = param('cuerpoHtmlCache', null);
            $token       = param('tokenEncuesta', null);
            $obs         = param('observaciones', null);
            $estado      = (int) param('estado', 1);

            if (!$idEncuesta || !$idAtencion || !$idCliente || $fechaProg==='') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Parámetros requeridos: idEncuesta, idAtencion, idCliente, fechaProgramadaInicial"]);
                break;
            }

            $datos = $ep->insertar($idEncuesta, $idAtencion, $idCliente, $fechaProg, $maxIntentos, $proximo, $estadoEnvio,
                                   $canalEnvio, $enviadoPor, $observ, $asunto, $cuerpo, $token, $obs, $estado);
            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idProgEncuesta"=>(int)$datos]);
            } else {
                http_response_code(500);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$datos]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR (parcial)
    case 'actualizar':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        $campos = param('campos', []);
        if (!is_array($campos)) $campos = [];
        $res = $ep->actualizar($id, $campos);
        if (is_numeric($res)) echo json_encode(["success"=>true, "affected"=>(int)$res]);
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$res]); }
        break;

    // === ELIMINAR
    case 'eliminar':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        $res = $ep->eliminar($id);
        if ($res === 1 || $res === '1') { echo json_encode(["success"=>true]); }
        else if ($res === 0 || $res === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(409); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$res]); }
        break;

    // === ACTIVAR / DESACTIVAR
    case 'activar':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        $res = $ep->activar($id);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        $res = $ep->desactivar($id);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR
    case 'contar':
        $soloActivas  = param('soloActivas', null);
        $estadoEnvio  = param('estadoEnvio', null);
        $canalEnvio   = param('canalEnvio', null);
        $idEncuesta   = param('idEncuesta', null);
        $idAtencion   = param('idAtencion', null);
        $idCliente    = param('idCliente', null);
        $desde        = param('desde', null);
        $hasta        = param('hasta', null);
        $q            = param('q', null);
        $total = $ep->contar($soloActivas, $estadoEnvio, $canalEnvio, $idEncuesta, $idAtencion, $idCliente, $desde, $hasta, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // === DEPENDENCIAS
    case 'dependencias':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        echo json_encode($ep->dependencias($id));
        break;

    // === SP: programar por atención
    case 'programar_por_atencion':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idAtencion || !$idEncuesta) { http_response_code(400); echo json_encode(["message"=>"Parámetros requeridos: idAtencion, idEncuesta"]); break; }
        $res = $ep->sp_programar_por_atencion($idAtencion, $idEncuesta);
        if ($res === 1 || $res === '1') echo json_encode(["success"=>true]);
        else { http_response_code(500); echo json_encode(["success"=>false, "error"=>$res]); }
        break;

    // === SP: programar por encuesta (para todas las atenciones sin programación)
    case 'programar_por_encuesta':
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idEncuesta) { http_response_code(400); echo json_encode(["message"=>"Parámetro idEncuesta inválido"]); break; }
        $res = $ep->sp_programar_encuestas_por_encuesta($idEncuesta);
        if ($res === 1 || $res === '1') echo json_encode(["success"=>true]);
        else { http_response_code(500); echo json_encode(["success"=>false, "error"=>$res]); }
        break;

    // === SP: marcar envío manual
    case 'marcar_envio':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        $canal = (string) param('canal', 'EMAIL'); // EMAIL|WHATSAPP|SMS|OTRO
        $observacion = (string) param('observacion', '');
        $asunto = param('asunto', null);
        $cuerpo = param('cuerpo', null);
        if (!$id || !$idUsuario) { http_response_code(400); echo json_encode(["message"=>"Parámetros requeridos: idProgEncuesta, idUsuario"]); break; }
        $res = $ep->sp_marcar_envio_manual($id, $idUsuario, $canal, $observacion, $asunto, $cuerpo);
        if ($res === 1 || $res === '1') echo json_encode(["success"=>true]);
        else { http_response_code(500); echo json_encode(["success"=>false, "error"=>$res]); }
        break;

    // === SP: marcar no contestada
    case 'marcar_no_contestada':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        $res = $ep->sp_marcar_no_contestada($id);
        if ($res === 1 || $res === '1') echo json_encode(["success"=>true]);
        else { http_response_code(500); echo json_encode(["success"=>false, "error"=>$res]); }
        break;

    // === SP: reabrir encuesta
    case 'reabrir':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        $observ = (string) param('observacion', '');
        $reset = (int) param('reset_intentos', 0);
        if (!$id || !$idUsuario) { http_response_code(400); echo json_encode(["message"=>"Parámetros requeridos: idProgEncuesta, idUsuario"]); break; }
        $res = $ep->sp_reabrir_encuesta($id, $idUsuario, $observ, $reset);
        if ($res === 1 || $res === '1') echo json_encode(["success"=>true]);
        else { http_response_code(500); echo json_encode(["success"=>false, "error"=>$res]); }
        break;

    // === SP: registrar consentimiento
    case 'registrar_consentimiento':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        $acepta = (int) param('acepta', 1);
        $ip = (string) param('ip', '0.0.0.0');
        $ua = (string) param('userAgent', '');
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idProgEncuesta inválido"]); break; }
        $res = $ep->sp_registrar_consentimiento($id, $acepta, $ip, $ua);
        if ($res === 1 || $res === '1') echo json_encode(["success"=>true]);
        else { http_response_code(500); echo json_encode(["success"=>false, "error"=>$res]); }
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|dependencias|programar_por_atencion|programar_por_encuesta|marcar_envio|marcar_no_contestada|reabrir|registrar_consentimiento"]);
        break;
}
