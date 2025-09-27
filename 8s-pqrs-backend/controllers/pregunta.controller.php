<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");

$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

require_once('../models/preguntas.model.php');
require_once('../models/respuestasopciones.model.php');
// error_reporting(0);

$preguntas = new Preguntas();
$respuestas = new RespuestasOpciones();

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function param($key, $default = null) {
    $json = read_json_body();
    return $_POST[$key] ?? $_GET[$key] ?? $json[$key] ?? $default;
}

switch ($_GET["op"] ?? '') {
    // === LISTAR PREGUNTAS DE UNA ENCUESTA
    case 'listarPreguntas':
        $datos = array();
        $idEncuesta = (int) param('idEncuesta');
        $datos = $preguntas->obtenerPorEncuesta($idEncuesta);
 
        echo json_encode($datos);
        break;

    // === INSERTAR PREGUNTA
    case 'insertarPregunta':
        $datos = $preguntas->insertar(
            param('idEncuesta'), param('orden'), param('texto'), param('tipo'),
            param('activa', 1), param('permiteComentario', 0), param('generaPqr', 0),
            param('umbralMinimo', null), param('scriptFinal', null), param('esNps', 0)
        );
        echo json_encode(["success" => true, "idPregunta" => $datos]);
        break;

    // === ACTUALIZAR PREGUNTA
    case 'actualizarPregunta':
        $datos = $preguntas->actualizar(
            param('idPregunta'), param('orden'), param('texto'), param('tipo'),
            param('activa', 1), param('permiteComentario', 0), param('generaPqr', 0),
            param('umbralMinimo', null), param('scriptFinal', null), param('esNps', 0)
        );
        echo json_encode(["success" => true, "affected" => $datos]);
        break;

    // === ELIMINAR LÓGICO (estado = 0)
    case 'eliminarPregunta':
        $datos = $preguntas->eliminarLogico(param('idPregunta'));
        echo json_encode(["success" => true, "affected" => $datos]);
        break;

    // === LISTAR OPCIONES DE UNA PREGUNTA
    case 'listarOpciones':
        $idPregunta = (int) param('idPregunta');
        $datos = $respuestas->listarPorPregunta($idPregunta);
        echo json_encode($datos);
        break;

    // === INSERTAR OPCIÓN
    case 'insertarOpcion':
        $datos = $respuestas->insertar(
            param('idPregunta'), param('etiqueta'), param('valorNumerico'),
            param('secuenciaSiguiente', null), param('generaPqr', 0),
            param('requiereComentario', 0), param('estado', 1)
        );
        echo json_encode(["success" => true, "idOpcion" => $datos]);
        break;

    // === ACTUALIZAR OPCIÓN
    case 'actualizarOpcion':
        $datos = $respuestas->actualizar(
            param('idOpcion'), param('etiqueta'), param('valorNumerico'),
            param('secuenciaSiguiente', null), param('generaPqr', 0),
            param('requiereComentario', 0), param('estado', 1)
        );
        echo json_encode(["success" => true, "affected" => $datos]);
        break;

    // === ELIMINAR OPCIÓN (LÓGICO)
    case 'eliminarOpcion':
        $datos = $respuestas->eliminarLogico(param('idOpcion'));
        echo json_encode(["success" => true, "affected" => $datos]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=listarPreguntas|insertarPregunta|actualizarPregunta|eliminarPregunta|listarOpciones|insertarOpcion|actualizarOpcion|eliminarOpcion"]);
        break;
}
