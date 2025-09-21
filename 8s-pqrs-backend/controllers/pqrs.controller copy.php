<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de PQRS
require_once('../models/pqrs.model.php');
error_reporting(0);

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
function bool_or_null($v) {
    if ($v === null) return null;
    if ($v === '1' || $v === 1 || $v === true) return 1;
    if ($v === '0' || $v === 0 || $v === false) return 0;
    return null;
}

// Generar código si no llega
function generar_codigo_pqrs() {
    // Prefijo + fecha + 5 chars de hash
    $base = 'PQRS-' . date('YmdHis');
    return $base . '-' . substr(uniqid('', true), -5);
}

$pqrs = new Pqrs();

switch ($_GET["op"] ?? '') {

    // === LISTAR
    case 'todos':
        $limit  = (int) param('limit', 100);
        $offset = (int) param('offset', 0);

        $datos = $pqrs->todos(
            $limit, $offset,
            param('idTipo',  null),
            param('idCategoria',  null),
            param('idCanal',  null),
            param('idEstado', null),
            param('idAgencia', null),
            param('idCliente', null),
            param('idEncuesta', null),
            param('idProgEncuesta', null),
            param('nivelActual', null),
            bool_or_null(param('estadoRegistro', null)),
            param('fechaDesde', null),
            param('fechaHasta', null),
            param('q', null)
        );
        if (is_array($datos)) { echo json_encode($datos); }
        else { http_response_code(500); echo json_encode(["message"=>"Error al listar PQRS"]); }
        break;

    // === OBTENER UNO
    case 'uno':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = $pqrs->uno($idPqrs);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"PQRS no encontrado"]); }
        break;

    // === INSERTAR
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $codigo         = trim((string) param('codigo', ''));
            if ($codigo === '') { $codigo = generar_codigo_pqrs(); }

            $idTipo         = filter_var(param('idTipo'), FILTER_VALIDATE_INT);
            $idCategoria    = param('idCategoria', null); $idCategoria = $idCategoria!==null ? (int)$idCategoria : null;
            $idCanal        = filter_var(param('idCanal'), FILTER_VALIDATE_INT);
            $idEstado       = filter_var(param('idEstado'), FILTER_VALIDATE_INT);
            $idAgencia      = param('idAgencia', null); $idAgencia = $idAgencia!==null ? (int)$idAgencia : null;
            $idCliente      = filter_var(param('idCliente'), FILTER_VALIDATE_INT);
            $idEncuesta     = filter_var(param('idEncuesta'), FILTER_VALIDATE_INT);
            $idProgEncuesta = filter_var(param('idProgEncuesta'), FILTER_VALIDATE_INT);
            $asunto         = trim((string) param('asunto'));
            $detalle        = param('detalle', null); $detalle = $detalle !== null ? (string)$detalle : null;
            $nivelActual    = (int) param('nivelActual', 1);
            $fechaLimite    = param('fechaLimiteNivel', null);
            $fechaCierre    = param('fechaCierre', null);
            $estadoReg      = (int) param('estadoRegistro', 1);

            if (!$idTipo || !$idCanal || !$idEstado || !$idCliente || !$idEncuesta || !$idProgEncuesta || $asunto==='') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Parámetros requeridos faltantes"]);
                break;
            }

            $datos = $pqrs->insertar($codigo, $idTipo, $idCategoria, $idCanal, $idEstado, $idAgencia, $idCliente, $idEncuesta, $idProgEncuesta, $asunto, $detalle, $nivelActual, $fechaLimite, $fechaCierre, $estadoReg);
            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idPqrs"=>(int)$datos, "codigo"=>$codigo]);
            } else {
                http_response_code(500);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$datos]);
            }
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(["success"=>false, "message"=>"Error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR
    case 'actualizar':
        $idPqrs        = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $codigo        = trim((string) param('codigo', ''));
        $idTipo        = filter_var(param('idTipo'), FILTER_VALIDATE_INT);
        $idCategoria   = param('idCategoria', null); $idCategoria = $idCategoria!==null ? (int)$idCategoria : null;
        $idCanal       = filter_var(param('idCanal'), FILTER_VALIDATE_INT);
        $idEstado      = filter_var(param('idEstado'), FILTER_VALIDATE_INT);
        $idAgencia     = param('idAgencia', null); $idAgencia = $idAgencia!==null ? (int)$idAgencia : null;
        $idCliente     = filter_var(param('idCliente'), FILTER_VALIDATE_INT);
        $idEncuesta    = filter_var(param('idEncuesta'), FILTER_VALIDATE_INT);
        $idProgEncuesta= filter_var(param('idProgEncuesta'), FILTER_VALIDATE_INT);
        $asunto        = trim((string) param('asunto'));
        $detalle       = param('detalle', null); $detalle = $detalle !== null ? (string)$detalle : null;
        $nivelActual   = (int) param('nivelActual', 1);
        $fechaLimite   = param('fechaLimiteNivel', null);
        $fechaCierre   = param('fechaCierre', null);
        $estadoReg     = (int) param('estadoRegistro', 1);

        if (!$idPqrs || !$codigo || !$idTipo || !$idCanal || !$idEstado || !$idCliente || !$idEncuesta || !$idProgEncuesta || $asunto==='') {
            http_response_code(400); echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]); break;
        }
        $res = $pqrs->actualizar($idPqrs, $codigo, $idTipo, $idCategoria, $idCanal, $idEstado, $idAgencia, $idCliente, $idEncuesta, $idProgEncuesta, $asunto, $detalle, $nivelActual, $fechaLimite, $fechaCierre, $estadoReg);
        if (is_numeric($res)) { echo json_encode(["success"=>true, "affected"=>(int)$res]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$res]); }
        break;

    // === ELIMINAR
    case 'eliminar':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $datos = $pqrs->eliminar($idPqrs);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(409); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$datos]); }
        break;

    // === ACTIVAR / DESACTIVAR (estadoRegistro)
    case 'activar':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = $pqrs->activar($idPqrs);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = $pqrs->desactivar($idPqrs);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR
    case 'contar':
        $total = $pqrs->contar(
            param('idTipo',  null), param('idCategoria',  null), param('idCanal',  null), param('idEstado', null),
            param('idAgencia', null), param('idCliente', null), param('idEncuesta', null), param('idProgEncuesta', null),
            param('nivelActual', null), bool_or_null(param('estadoRegistro', null)), param('fechaDesde', null), param('fechaHasta', null), param('q', null)
        );
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // === DEPENDENCIAS
    case 'dependencias':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = $pqrs->dependencias($idPqrs);
        echo json_encode($res);
        break;

    // === PREGUNTAS ASOCIADAS
    case 'agregarPregunta':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $idPregunta = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
        $idCategoria = param('idCategoria', null); $idCategoria = $idCategoria!==null ? (int)$idCategoria : null;
        if (!$idPqrs || !$idPregunta) { http_response_code(400); echo json_encode(["message"=>"Parámetros inválidos"]); break; }
        $res = $pqrs->agregarPregunta($idPqrs, $idPregunta, $idCategoria);
        if (is_numeric($res)) { echo json_encode(["success"=>true, "id"=>$res]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo agregar", "error"=>$res]); }
        break;

    case 'quitarPregunta':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $idPregunta = filter_var(param('idPregunta', null), FILTER_VALIDATE_INT);
        if (!$idPqrs || !$idPregunta) { http_response_code(400); echo json_encode(["message"=>"Parámetros inválidos"]); break; }
        $res = $pqrs->quitarPregunta($idPqrs, $idPregunta);
        if (is_numeric($res)) { echo json_encode(["success"=>true, "affected"=>$res]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo quitar", "error"=>$res]); }
        break;

    case 'listarPreguntas':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = $pqrs->listarPreguntas($idPqrs);
        if (is_array($res)) { echo json_encode($res); }
        else { http_response_code(500); echo json_encode(["message"=>"Error al listar preguntas", "error"=>$res]); }
        break;

    // === RESPONSABLES
    case 'agregarResponsable':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $nivel = filter_var(param('nivel', null), FILTER_VALIDATE_INT);
        $idResponsable = filter_var(param('idResponsable', null), FILTER_VALIDATE_INT);
        $horasSLA = (int) param('horasSLA', 24);
        if (!$idPqrs || !$nivel || !$idResponsable) { http_response_code(400); echo json_encode(["message"=>"Parámetros inválidos"]); break; }
        $res = $pqrs->agregarResponsable($idPqrs, $nivel, $idResponsable, $horasSLA);
        if (is_numeric($res)) { echo json_encode(["success"=>true, "id"=>$res]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo agregar", "error"=>$res]); }
        break;

    case 'listarResponsables':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = $pqrs->listarResponsables($idPqrs);
        if (is_array($res)) { echo json_encode($res); }
        else { http_response_code(500); echo json_encode(["message"=>"Error al listar responsables", "error"=>$res]); }
        break;

    case 'eliminarResponsable':
        $id = filter_var(param('id', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro id inválido"]); break; }
        $res = $pqrs->eliminarResponsable($id);
        if (is_numeric($res)) { echo json_encode(["success"=>true, "affected"=>$res]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$res]); }
        break;

    default:
        http_response_code(400);
        echo json_encode(["message"=>"Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|dependencias|agregarPregunta|quitarPregunta|listarPreguntas|agregarResponsable|listarResponsables|eliminarResponsable"]);
        break;
}
