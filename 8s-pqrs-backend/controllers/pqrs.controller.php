<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// === Modelo
require_once('../models/pqrs.model.php');

// Instancia flexible (Pqrs o PQRS)
$modelClass = class_exists('Pqrs') ? 'Pqrs' : (class_exists('PQRS') ? 'PQRS' : null);
if (!$modelClass) {
    http_response_code(500);
    echo json_encode(["success"=>false,"message"=>"No se encontró la clase del modelo Pqrs/PQRS"]);
    exit;
}
$pqrs = new $modelClass();

// === Helpers de entrada
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

// === Generar código si no llega (requerido)
function generar_codigo_pqrs() {
    // Prefijo + fecha + 5 chars de hash
    $base = 'PQRS-' . date('YmdHis');
    return $base . '-' . substr(uniqid('', true), -5);
}

/**
 * Invoca un método del modelo mapeando argumentos por NOMBRE.
 * - Si el método acepta 1 parámetro => envía el payload completo (array asociativo).
 * - Si acepta >1 => arma el arreglo de argumentos respetando el orden y nombre de parámetros del método.
 */
function invoke_mapped(object $obj, string $method, array $payload) {
    if (!method_exists($obj, $method)) {
        return ["__error__" => "Método $method no existe en el modelo"];
    }
    try {
        $rm = new ReflectionMethod($obj, $method);
        $params = $rm->getParameters();
        if (count($params) === 1) {
            return $rm->invoke($obj, $payload);
        }
        $args = [];
        foreach ($params as $p) {
            $name = $p->getName();
            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
            } else {
                $args[] = $p->isOptional() ? $p->getDefaultValue() : null;
            }
        }
        return $rm->invokeArgs($obj, $args);
    } catch (Throwable $e) {
        return ["__exception__" => $e->getMessage()];
    }
}

// Reúne campos comunes del recurso PQRS
function collect_pqrs_payload(): array {
    return [
        // Identificadores
        "idPqrs"                => param('idPqrs'),
        "idTipo"                => param('idTipo'),
        "idCategoria"           => param('idCategoria'),
        "idCanal"               => param('idCanal'),
        "idAgencia"             => param('idAgencia'),
        "idCliente"             => param('idCliente'),
        "idEncuesta"            => param('idEncuesta'),
        "idEncuestaProgramada"  => param('idEncuestaProgramada'),
        "idEstado"              => param('idEstado'),
        "idUsuarioCreador"      => param('idUsuarioCreador'),
        "idUsuarioAgente"       => param('idUsuarioAgente'),

        // Datos principales
        "codigo"                => param('codigo'),
        "asunto"                => param('asunto'),
        "descripcion"           => param('descripcion'),
        "prioridad"             => param('prioridad'),
        "nivelActual"           => param('nivelActual'),
        "observaciones"         => param('observaciones'),

        // Fechas
        "fechaApertura"         => param('fechaApertura'),
        "fechaCierre"           => param('fechaCierre'),
        "fechaLimite"           => param('fechaLimite'),

        // Estado de registro (activo/inactivo/borrado lógico, según tu esquema)
        "estadoRegistro"        => param('estadoRegistro'),

        // Filtros adicionales para listados
        "q"                     => param('q'),
        "fechaDesde"            => param('fechaDesde'),
        "fechaHasta"            => param('fechaHasta'),
        "limit"                 => (int) param('limit', 100),
        "offset"                => (int) param('offset', 0),
    ];
}

$op = $_GET["op"] ?? '';

switch ($op) {

    // === LISTAR
    case 'todos':
        $payload = collect_pqrs_payload();
        $res = invoke_mapped($pqrs, 'todos', $payload);

        if (is_array($res) && isset($res["__error__"])) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>$res["__error__"]]); break;
        }
        if (is_array($res) && isset($res["__exception__"])) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Error al listar PQRS", "error"=>$res["__exception__"]]); break;
        }

        if (is_array($res) && count($res) > 0) {
            echo json_encode($res);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron PQRS."]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $id = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = invoke_mapped($pqrs, 'uno', ["idPqrs"=>$id]);
        if (is_array($res) && isset($res["__error__"])) { http_response_code(500); echo json_encode(["success"=>false,"message"=>$res["__error__"]]); break; }
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"PQRS no encontrada"]); }
        break;

    // === INSERTAR
    case 'insertar':
        $p = collect_pqrs_payload();

        // Generar código si no llega
        if (empty($p['codigo'])) {
            $p['codigo'] = generar_codigo_pqrs();
        }

        // Si no llega fechaApertura, se asume ahora
        if (empty($p['fechaApertura'])) {
            $p['fechaApertura'] = date('Y-m-d H:i:s');
        }

        // Validaciones mínimas (ajusta a tus reglas)
        if (trim((string)$p['asunto']) === '' || trim((string)$p['descripcion']) === '' ||
            !filter_var($p['idTipo'], FILTER_VALIDATE_INT) ||
            !filter_var($p['idCategoria'], FILTER_VALIDATE_INT) ||
            !filter_var($p['idCanal'], FILTER_VALIDATE_INT)) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros requeridos faltantes o inválidos (asunto, descripcion, idTipo, idCategoria, idCanal)."]);
            break;
        }

        $res = invoke_mapped($pqrs, 'insertar', $p);
        if (is_array($res) && isset($res["__error__"])) { http_response_code(500); echo json_encode(["success"=>false,"message"=>$res["__error__"]]); break; }
        if (is_array($res) && isset($res["__exception__"])) { http_response_code(500); echo json_encode(["success"=>false,"message"=>"Error al insertar", "error"=>$res["__exception__"]]); break; }

        if (is_numeric($res)) {
            echo json_encode(["success"=>true, "idPqrs"=>(int)$res, "codigo"=>$p['codigo']]);
        } else {
            // Si el modelo devuelve string de error
            $num = filter_var($res, FILTER_VALIDATE_INT);
            if ($num !== false) {
                echo json_encode(["success"=>true, "idPqrs"=>(int)$num, "codigo"=>$p['codigo']]);
            } else {
                http_response_code(500);
                echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$res]);
            }
        }
        break;

    // === ACTUALIZAR
    case 'actualizar':
        $p = collect_pqrs_payload();
        $id = filter_var($p['idPqrs'], FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["success"=>false,"message"=>"Parámetro idPqrs inválido"]); break; }

        $res = invoke_mapped($pqrs, 'actualizar', $p);
        if (is_array($res) && isset($res["__error__"])) { http_response_code(500); echo json_encode(["success"=>false,"message"=>$res["__error__"]]); break; }
        if (is_array($res) && isset($res["__exception__"])) { http_response_code(500); echo json_encode(["success"=>false,"message"=>"Error al actualizar", "error"=>$res["__exception__"]]); break; }

        $af = is_numeric($res) ? (int)$res : 0;
        echo json_encode(["success"=>true, "affected"=>$af]);
        break;

    // === ELIMINAR
    case 'eliminar':
        $id = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }

        $res = invoke_mapped($pqrs, 'eliminar', ["idPqrs"=>$id]);
        if (is_array($res) && isset($res["__error__"])) { http_response_code(500); echo json_encode(["success"=>false,"message"=>$res["__error__"]]); break; }

        if ($res === 1 || $res === '1') { echo json_encode(["success"=>true]); }
        else if ($res === 0 || $res === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(409); echo json_encode(["success"=>false, "message"=>"No se pudo eliminar", "error"=>$res]); }
        break;

    // === ACTIVAR / DESACTIVAR (estadoRegistro)
    case 'activar':
        $id = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = invoke_mapped($pqrs, 'activar', ["idPqrs"=>$id]);
        $ok = is_numeric($res) && (int)$res >= 0;
        echo json_encode(["success"=>$ok, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $id = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = invoke_mapped($pqrs, 'desactivar', ["idPqrs"=>$id]);
        $ok = is_numeric($res) && (int)$res >= 0;
        echo json_encode(["success"=>$ok, "affected"=> (int)$res]);
        break;

    // === CONTAR (para paginación)
    case 'contar':
        $payload = collect_pqrs_payload();
        $res = invoke_mapped($pqrs, 'contar', $payload);
        $total = is_numeric($res) ? (int)$res : 0;
        echo json_encode(["total"=>$total]);
        break;

    // === DEPENDENCIAS (p. ej. preguntas/responsables, etc.)
    case 'dependencias':
        $id = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro idPqrs inválido"]); break; }
        $res = invoke_mapped($pqrs, 'dependencias', ["idPqrs"=>$id]);
        echo json_encode(is_array($res) ? $res : ["result"=>$res]);
        break;

    // === ENDPOINTS AUXILIARES (solo si tu modelo los tiene)
    case 'agregarPregunta':
        $res = invoke_mapped($pqrs, 'agregarPregunta', [
            "idPqrs" => param('idPqrs'),
            "idPregunta" => param('idPregunta'),
            "valor" => param('valor')
        ]);
        echo json_encode(["result"=>$res]);
        break;

    case 'quitarPregunta':
        $res = invoke_mapped($pqrs, 'quitarPregunta', [
            "idPqrs" => param('idPqrs'),
            "idPregunta" => param('idPregunta')
        ]);
        echo json_encode(["result"=>$res]);
        break;

    case 'listarPreguntas':
        $res = invoke_mapped($pqrs, 'listarPreguntas', ["idPqrs"=>param('idPqrs')]);
        echo json_encode(is_array($res) ? $res : ["result"=>$res]);
        break;

    case 'agregarResponsable':
        $res = invoke_mapped($pqrs, 'agregarResponsable', [
            "idPqrs" => param('idPqrs'),
            "idUsuario" => param('idUsuario')
        ]);
        echo json_encode(["result"=>$res]);
        break;

    case 'listarResponsables':
        $res = invoke_mapped($pqrs, 'listarResponsables', ["idPqrs"=>param('idPqrs')]);
        echo json_encode(is_array($res) ? $res : ["result"=>$res]);
        break;

    case 'eliminarResponsable':
        $res = invoke_mapped($pqrs, 'eliminarResponsable', [
            "idPqrs" => param('idPqrs'),
            "idUsuario" => param('idUsuario')
        ]);
        echo json_encode(["result"=>$res]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            "message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|dependencias|agregarPregunta|quitarPregunta|listarPreguntas|agregarResponsable|listarResponsables|eliminarResponsable"
        ]);
        break;
}
