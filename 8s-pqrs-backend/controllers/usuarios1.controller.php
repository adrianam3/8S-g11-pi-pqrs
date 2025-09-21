<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de usuarios (tabla `usuarios`)
//require_once('revisarsesion.controller.php');
require_once('../models/usuarios1.model.php');
// require_once('email.controller.php'); // si quieres notificar al crear usuario
// error_reporting(0); // comentar si deseas ver errores en desarrollo

$usuarios = new Usuarios();

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
        $limit     = (int) (param('limit', 100));
        $offset    = (int) (param('offset', 0));
        $estado    = param('estado', null);   // 1|0|null
        $idRol     = param('idRol', null);
        $idAgencia = param('idAgencia', null);
        $q         = param('q', null);

        $datos = $usuarios->todos($limit, $offset, $estado, $idRol, $idAgencia, $q);
        if (is_array($datos) && count($datos) > 0) {
           // echo json_encode(["message"=>"OK", "data"=>$datos]);
           echo json_encode($datos);
        } else {
            http_response_code(404);
            // echo json_encode(["message"=>"No se encontraron usuarios.", "data"=>[]]);
            echo json_encode($datos);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        if ($idUsuario === false) {
        http_response_code(400);
        echo json_encode(["message" => "Parámetro idUsuarioooo inválido"]);
        break;
         }
        // if (!$idUsuario) { http_response_code(400); echo json_encode(["message"=>"Parámetro idUsuario inválido"]); break; }
        $res = $usuarios->uno($idUsuario);
        if ($res) { echo json_encode(["message"=>"OK","data"=>$res]); }
        else { http_response_code(404); echo json_encode(["message"=>"Usuario no encontrado"]); }
        break;

    // === LOGIN
    case 'login':
        $email    = trim((string) param('email', ''));
        $password = (string) param('password', '');
        if ($email==='' || $password==='') { http_response_code(400); echo json_encode(["message"=>"Email y password requeridos"]); break; }
        $res = $usuarios->login($email, $password);
        if ($res) { echo json_encode(["message"=>"OK","data"=>$res]); }
        else { http_response_code(401); echo json_encode(["message"=>"Credenciales inválidas"]); }
        break;

    // === INSERTAR
    case 'insertar':
        $usuario      = trim((string) param('usuario'));
        $password     = (string) param('password');
        $descripcion  = (string) param('descripcion', '');
        $idPersona    = filter_var(param('idPersona', null), FILTER_VALIDATE_INT);
        $idAgencia    = filter_var(param('idAgencia', null), FILTER_VALIDATE_INT);
        $idRol        = filter_var(param('idRol', null), FILTER_VALIDATE_INT);
        $estado       = (int) (param('estado', 1));

        if ($usuario==='' || $password==='' || !$idPersona || !$idAgencia || !$idRol) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $res = $usuarios->insertar($usuario, $password, $descripcion, $idPersona, $idAgencia, $idRol, $estado);
        if (is_numeric($res)) {
            echo json_encode(["success"=>true, "idUsuario"=>(int)$res]);
        } else {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$res]);
        }
        break;

    // === ACTUALIZAR (sin contraseña)
    case 'actualizar':
        $idUsuario   = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        $usuario     = trim((string) param('usuario'));
        $descripcion = (string) param('descripcion', '');
        $idPersona   = filter_var(param('idPersona', null), FILTER_VALIDATE_INT);
        $idAgencia   = filter_var(param('idAgencia', null), FILTER_VALIDATE_INT);
        $idRol       = filter_var(param('idRol', null), FILTER_VALIDATE_INT);
        $estado      = (int) (param('estado', 1));

        if (!$idUsuario || $usuario==='' || !$idPersona || !$idAgencia || !$idRol) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $res = $usuarios->actualizar($idUsuario, $usuario, $descripcion, $idPersona, $idAgencia, $idRol, $estado);
        if (is_numeric($res)) {
            echo json_encode(["success"=>true, "affected"=>(int)$res]);
        } else {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$res]);
        }
        break;

    // === ELIMINAR
    case 'eliminar':
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        if (!$idUsuario) { http_response_code(400); echo json_encode(["message"=>"Parámetro idUsuario inválido"]); break; }
        $res = $usuarios->eliminar($idUsuario);
        if ($res === 1 || $res === '1') {
            echo json_encode(["success"=>true]);
        } elseif ($res === 0 || $res === '0') {
            http_response_code(404);
            echo json_encode(["success"=>false, "message"=>"No existe"]);
        } elseif (is_array($res) && ($res['status'] ?? '') === 'error') {
            http_response_code(409);
            echo json_encode(["success"=>false, "message"=>$res['message'] ?? 'No se pudo eliminar']);
        } else {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$res]);
        }
        break;

    // === CAMBIAR PASSWORD (validando la actual por email)
    case 'cambiar_password':
        $email   = (string) param('email', '');
        $actual  = (string) param('actual', '');
        $nueva   = (string) param('nueva', '');
        if ($email==='' || $actual==='' || $nueva==='') { http_response_code(400); echo json_encode(["message"=>"Faltan datos obligatorios"]); break; }
        $okLogin = $usuarios->login($email, $actual);
        if (!$okLogin) { http_response_code(401); echo json_encode(["message"=>"La contraseña actual es incorrecta"]); break; }
        $af = $usuarios->actualizarContrasenaPorEmail($email, $nueva);
        echo json_encode(["success"=> $af>=1, "affected"=> (int)$af]);
        break;

    // === CAMBIAR PASSWORD por ID de usuario (sin validar la actual)
    case 'cambiar_password_por_id':
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        $nueva     = (string) param('nueva', '');
        if (!$idUsuario || $nueva==='') { http_response_code(400); echo json_encode(["message"=>"Parámetros inválidos"]); break; }
        $af = $usuarios->actualizarContrasenaPorId($idUsuario, $nueva);
        echo json_encode(["success"=> $af>=1, "affected"=> (int)$af]);
        break;

    // === ACTIVAR / DESACTIVAR
    case 'activar':
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        if (!$idUsuario) { http_response_code(400); echo json_encode(["message"=>"Parámetro idUsuario inválido"]); break; }
        $af = $usuarios->activar($idUsuario);
        echo json_encode(["success"=> $af>=0, "affected"=> (int)$af]);
        break;

    case 'desactivar':
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        if (!$idUsuario) { http_response_code(400); echo json_encode(["message"=>"Parámetro idUsuario inválido"]); break; }
        $af = $usuarios->desactivar($idUsuario);
        echo json_encode(["success"=> $af>=0, "affected"=> (int)$af]);
        break;

    // === CONTAR (paginación)
    case 'contar':
        $estado    = param('estado', null);
        $idRol     = param('idRol', null);
        $idAgencia = param('idAgencia', null);
        $q         = param('q', null);
        $total = $usuarios->contar($estado, $idRol, $idAgencia, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message"=>"Operación no soportada. Usa op=todos|uno|login|insertar|actualizar|eliminar|cambiar_password|cambiar_password_por_id|activar|desactivar|contar"]);
        break;
}
