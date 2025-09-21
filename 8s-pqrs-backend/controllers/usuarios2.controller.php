<?php
// Controlador de usuarios (formato de respuesta tipo array en "todos")
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

require_once('../models/usuarios.model.php');

$usuario = new Usuario();

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

    case 'todos':
        $datos = $usuario->todos();
        if (is_array($datos) && count($datos) > 0) {
            // ➜ Igual que tu ejemplo: devolver solo el array de objetos
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron usuarios."]);
        }
        break;

    case 'uno':
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        if (!$idUsuario) { http_response_code(400); echo json_encode(["message"=>"Parámetro idUsuario inválido"]); break; }
        $res = $usuario->uno($idUsuario);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Usuario no encontrado"]); }
        break;

    case 'insertar':
        $usuarioName = trim((string)param('usuario'));
        $password    = (string)param('password');
        $descripcion = (string)param('descripcion', '');
        $idPersona   = filter_var(param('idPersona'), FILTER_VALIDATE_INT);
        $idAgencia   = filter_var(param('idAgencia'), FILTER_VALIDATE_INT);
        $idRol       = filter_var(param('idRol'), FILTER_VALIDATE_INT);
        $estado      = (int) (param('estadoUsuario', 1));

        if ($usuarioName==='' || $password==='' || !$idPersona || !$idAgencia || !$idRol) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $res = $usuario->insertar($usuarioName, $password, $descripcion, $idPersona, $idAgencia, $idRol, $estado);
        if (is_numeric($res)) echo json_encode(["success"=>true, "idUsuario"=>(int)$res]);
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$res]); }
        break;

    case 'actualizar':
        $idUsuario   = filter_var(param('idUsuario'), FILTER_VALIDATE_INT);
        $usuarioName = trim((string)param('usuario'));
        $descripcion = (string)param('descripcion', '');
        $idPersona   = filter_var(param('idPersona'), FILTER_VALIDATE_INT);
        $idAgencia   = filter_var(param('idAgencia'), FILTER_VALIDATE_INT);
        $idRol       = filter_var(param('idRol'), FILTER_VALIDATE_INT);
        $estado      = (int) (param('estadoUsuario', 1));

        if (!$idUsuario || $usuarioName==='' || !$idPersona || !$idAgencia || !$idRol) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $res = $usuario->actualizar($idUsuario, $usuarioName, $descripcion, $idPersona, $idAgencia, $idRol, $estado);
        if (is_numeric($res)) echo json_encode(["success"=>true, "affected"=>(int)$res]);
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$res]); }
        break;

    case 'eliminar':
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        if (!$idUsuario) { http_response_code(400); echo json_encode(["message"=>"Parámetro idUsuario inválido"]); break; }
        $res = $usuario->eliminar($idUsuario);
        if ($res === 1 || $res === '1') { echo json_encode(["success"=>true]); }
        else if ($res === 0 || $res === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(409); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$res]); }
        break;

    case 'login':
        $email = (string)param('email', '');
        $password = (string)param('password', '');
        if ($email==='' || $password==='') { http_response_code(400); echo json_encode(["message"=>"Faltan credenciales"]); break; }
        $res = $usuario->login($email, $password);
        if ($res) { echo json_encode($res); }
        else { http_response_code(401); echo json_encode(["message"=>"Credenciales inválidas"]); }
        break;

    case 'cambiar_password':
        $email  = (string)param('email', '');
        $actual = (string)param('actual', '');
        $nueva  = (string)param('nueva', '');
        if ($email==='' || $actual==='' || $nueva==='') { http_response_code(400); echo json_encode(["message"=>"Faltan datos"]); break; }
        $ok = $usuario->login($email, $actual);
        if (!$ok) { http_response_code(401); echo json_encode(["message"=>"La contraseña actual es incorrecta"]); break; }
        $res = $usuario->actualizarcontrasena($nueva, $email);
        if (is_numeric($res) && $res >= 0) echo json_encode(["message"=>"Contraseña actualizada correctamente"]);
        else { http_response_code(500); echo json_encode(["message"=>"Error al actualizar la contraseña", "error"=>$res]); }
        break;

    default:
        http_response_code(400);
        echo json_encode(["message"=>"Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|login|cambiar_password"]);
        break;
}
