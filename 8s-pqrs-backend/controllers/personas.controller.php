<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de Personas (tabla `personas`)
// require_once('revisarsesion.controller.php'); // NO durante pruebas
require_once('../models/personas.model.php');
error_reporting(0);

// ========================== Helpers locales ==========================
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
function wrap_ok($message='OK', $data=[]) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE);
}
function wrap_error($message='Error', $code=500, $debug=null) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $out = ['message'=>$message,'data'=>[]];
    if ($debug !== null) $out['debug'] = $debug;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
// =====================================================================

$personas = new Personas();
$op = $_GET["op"] ?? '';

switch ($op) {

    // === LISTAR (con filtros) ===
    case 'todos': {
        $limit       = (int) param('limit', 100);
        $offset      = (int) param('offset', 0);
        $soloActivas =       param('soloActivas', null); // 1|0|null
        $q           = (string) param('q', null);

        $res = $personas->todos($limit, $offset, $soloActivas, $q);
        if (is_string($res)) { wrap_error('Error al listar personas', 500, $res); break; }

        wrap_ok(count($res) ? 'OK' : 'Sin datos', $res);
        break;
    }

    // === PERSONAS SIN USUARIO ===
    case 'todossinusuario': {
        $res = $personas->todossinusuario();
        if (is_string($res)) { wrap_error('Error al listar personas sin usuario', 500, $res); break; }
        wrap_ok(count($res) ? 'OK' : 'Sin datos', $res);
        break;
    }

    // === POR ROL (email + nombreCompleto) ===
    case 'todosByRol': {
        $idRol = filter_var(param('idRol', null), FILTER_VALIDATE_INT);
        if (!$idRol) { wrap_error('Parámetro idRol inválido', 400); break; }

        $res = $personas->todosByRol($idRol);
        if (is_string($res)) { wrap_error('Error al listar por rol', 500, $res); break; }

        wrap_ok(count($res) ? 'OK' : 'Sin datos', $res);
        break;
    }

    // === OBTENER UNO ===
    case 'uno': {
        $idPersona = filter_var(param('idPersona', null), FILTER_VALIDATE_INT);
        if (!$idPersona) { wrap_error('Parámetro idPersona inválido', 400); break; }

        $res = $personas->uno($idPersona);
        if (is_string($res)) { wrap_error('Error al obtener persona', 500, $res); break; }
        if ($res === null) { wrap_error('Persona no encontrada', 404); break; }

        wrap_ok('OK', $res); // objeto
        break;
    }

    // === INSERTAR ===
    case 'insertar': {
        // Requeridos
        $cedula    = trim((string) param('cedula', ''));
        $nombres   = trim((string) param('nombres', ''));
        $apellidos = trim((string) param('apellidos', ''));
        $direccion = (string) param('direccion', '');
        $telefono  = (string) param('telefono', '');
        $extension = (string) param('extension', '');
        $celular   = (string) param('celular', '');
        $email     = filter_var(param('email', ''), FILTER_SANITIZE_EMAIL);
        $estado    = param('estado', 1);

        if ($cedula==='' || $nombres==='' || $apellidos==='' || !$email) {
            wrap_error('Faltan campos requeridos (cedula, nombres, apellidos, email)', 400); break;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            wrap_error('Correo electrónico inválido', 400); break;
        }

        $res = $personas->insertar($cedula, $nombres, $apellidos, $direccion, $telefono, $extension, $celular, $email, $estado);
        if (is_string($res)) { wrap_error('No se pudo insertar la persona', 500, $res); break; }

        wrap_ok('Persona creada', ['idPersona'=>(int)$res]);
        break;
    }

    // === ACTUALIZAR ===
    case 'actualizar': {
        $idPersona = filter_var(param('idPersona', null), FILTER_VALIDATE_INT);
        $cedula    = trim((string) param('cedula', ''));
        $nombres   = trim((string) param('nombres', ''));
        $apellidos = trim((string) param('apellidos', ''));
        $direccion = (string) param('direccion', '');
        $telefono  = (string) param('telefono', '');
        $extension = (string) param('extension', '');
        $celular   = (string) param('celular', '');
        $email     = filter_var(param('email', ''), FILTER_SANITIZE_EMAIL);
        $estado    = param('estado', 1);

        if (!$idPersona) { wrap_error('Parámetro idPersona inválido', 400); break; }
        if ($cedula==='' || $nombres==='' || $apellidos==='' || !$email) {
            wrap_error('Faltan campos requeridos (cedula, nombres, apellidos, email)', 400); break;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            wrap_error('Correo electrónico inválido', 400); break;
        }

        $res = $personas->actualizar($idPersona, $cedula, $nombres, $apellidos, $direccion, $telefono, $extension, $celular, $email, $estado);
        if (is_string($res)) { wrap_error('No se pudo actualizar la persona', 500, $res); break; }

        wrap_ok('Persona actualizada', ['affected'=>(int)$res]);
        break;
    }

    // === ELIMINAR ===
    case 'eliminar': {
        $idPersona = filter_var(param('idPersona', null), FILTER_VALIDATE_INT);
        if (!$idPersona) { wrap_error('Parámetro idPersona inválido', 400); break; }

        $res = $personas->eliminar($idPersona);

        if ($res === 1 || $res === '1') {
            wrap_ok('Persona eliminada', ['success'=>true]);
        } elseif (is_array($res) && ($res['status'] ?? '') === 'error') {
            // Casos de relación con usuarios u otros bloqueos de negocio
            wrap_error($res['message'] ?? 'No se puede eliminar', 409);
        } else {
            // error técnico
            wrap_error(is_string($res) ? $res : 'Error al eliminar', 500);
        }
        break;
    }

    // === ACTUALIZAR PERFIL (nombres, apellidos, telefono) ===
    case 'actualizar_perfil': {
        $idPersona = filter_var(param('idPersona', null), FILTER_VALIDATE_INT);
        $nombres   = trim((string) param('nombres', ''));
        $apellidos = trim((string) param('apellidos', ''));
        $telefono  = (string) param('telefono', '');

        if (!$idPersona || $nombres==='' || $apellidos==='' || $telefono==='') {
            wrap_error('Faltan campos requeridos (idPersona, nombres, apellidos, telefono)', 400); break;
        }

        $res = $personas->actualizarPerfil($idPersona, $nombres, $apellidos, $telefono);

        if (is_array($res) && ($res['status'] ?? '') === 'success') {
            wrap_ok($res['message'] ?? 'Perfil actualizado', ['success'=>true]);
        } elseif (is_array($res) && ($res['status'] ?? '') === 'error') {
            wrap_error($res['message'] ?? 'No se pudo actualizar el perfil', 500);
        } else {
            // string técnico
            wrap_error(is_string($res) ? $res : 'Error al actualizar perfil', 500);
        }
        break;
    }


case 'desactivar': {
    $idPersona = filter_var(param('idPersona', null), FILTER_VALIDATE_INT);
    if (!$idPersona) { wrap_error('Parámetro idPersona inválido', 400); break; }
    $uno = $personas->uno($idPersona);
    if (!$uno) { wrap_error('Persona no encontrada', 404); break; }
    $res = $personas->actualizar($idPersona, $uno['cedula'], $uno['nombres'], $uno['apellidos'],
                                 $uno['direccion'], $uno['telefono'], $uno['extension'],
                                 $uno['celular'], $uno['email'], 0);
    if (is_string($res)) { wrap_error('No se pudo desactivar la persona', 500, $res); break; }
    wrap_ok('Persona desactivada', ['affected'=>(int)$res]);
    break;
}




    default:
        wrap_error('Operación no soportada. Usa op=todos|todossinusuario|todosByRol|uno|insertar|actualizar|eliminar|actualizar_perfil', 400);
        break;
}
