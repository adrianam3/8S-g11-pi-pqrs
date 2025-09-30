<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de pqrs_responsables
require_once('../models/pqrs_responsables.model.php');
error_reporting(0);

$model = new PqrsResponsables();

// Helpers JSON/POST/GET
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
        $limit         = (int) (param('limit', 100));
        $offset        = (int) (param('offset', 0));
        $idPqrs        = param('idPqrs', null);
        $nivel         = param('nivel', null);
        $idResponsable = param('idResponsable', null);
        $q             = param('q', null);

        $datos = $model->todos($limit, $offset, $idPqrs, $nivel, $idResponsable, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode(["data"=>$datos]);
        } else {
            http_response_code(200);
            echo json_encode(["message" => "No se encontraron responsables.", "data" => []]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $id = filter_var(param('id', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro id inválido"]); break; }
        $res = $model->uno($id);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Responsable no encontrado"]); }
        break;

    // === OBTENER POR (idPqrs, nivel)
    case 'obtenerPorCompuesto':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $nivel  = filter_var(param('nivel', null), FILTER_VALIDATE_INT);
        if (!$idPqrs || !$nivel) { http_response_code(400); echo json_encode(["message"=>"Parámetros idPqrs y nivel son requeridos"]); break; }
        $res = $model->obtenerPorCompuesto($idPqrs, $nivel);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"No existe asignación para ese nivel"]); }
        break;

    // === INSERTAR
    case 'insertar':
        $idPqrs        = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $nivel         = filter_var(param('nivel', null), FILTER_VALIDATE_INT);
        $idResponsable = filter_var(param('idResponsable', null), FILTER_VALIDATE_INT);
        $horasSLA      = filter_var(param('horasSLA', null), FILTER_VALIDATE_INT);

        if (!$idPqrs || !$nivel || !$idResponsable || !$horasSLA) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $model->insertar($idPqrs, $nivel, $idResponsable, $horasSLA);
        if (is_numeric($datos)) {
            echo json_encode(["success"=>true, "id"=>(int)$datos]);
        } else {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo insertar", "error"=>$datos]);
        }
        break;

    // === UPSERT (idPqrs, nivel)
    case 'upsert':
        $idPqrs        = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $nivel         = filter_var(param('nivel', null), FILTER_VALIDATE_INT);
        $idResponsable = filter_var(param('idResponsable', null), FILTER_VALIDATE_INT);
        $horasSLA      = filter_var(param('horasSLA', null), FILTER_VALIDATE_INT);

        if (!$idPqrs || !$nivel || !$idResponsable || !$horasSLA) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $id = $model->upsert($idPqrs, $nivel, $idResponsable, $horasSLA);
        if (is_numeric($id)) {
            echo json_encode(["success"=>true, "id"=>(int)$id]);
        } else {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo insertar/actualizar", "error"=>$id]);
        }
        break;

    // === ACTUALIZAR por id
    case 'actualizar':
        $id            = filter_var(param('id', null), FILTER_VALIDATE_INT);
        $idPqrs        = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $nivel         = filter_var(param('nivel', null), FILTER_VALIDATE_INT);
        $idResponsable = filter_var(param('idResponsable', null), FILTER_VALIDATE_INT);
        $horasSLA      = filter_var(param('horasSLA', null), FILTER_VALIDATE_INT);

        if (!$id || !$idPqrs || !$nivel || !$idResponsable || !$horasSLA) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $model->actualizar($id, $idPqrs, $nivel, $idResponsable, $horasSLA);
        $affected = is_numeric($datos) ? (int)$datos : 0;
        echo json_encode(["success"=>true, "affected"=>$affected]);
        break;

    // === ELIMINAR por id
    case 'eliminar':
        $id = filter_var(param('id', null), FILTER_VALIDATE_INT);
        if (!$id) { http_response_code(400); echo json_encode(["message"=>"Parámetro id inválido"]); break; }
        $datos = $model->eliminar($id);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === ELIMINAR por (idPqrs, nivel)
    case 'eliminarPorCompuesto':
        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $nivel  = filter_var(param('nivel', null), FILTER_VALIDATE_INT);
        if (!$idPqrs || !$nivel) { http_response_code(400); echo json_encode(["message"=>"Parámetros idPqrs y nivel son requeridos"]); break; }
        $datos = $model->eliminarPorCompuesto($idPqrs, $nivel);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { http_response_code(500); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === CONTAR
    case 'contar':
        $idPqrs        = param('idPqrs', null);
        $nivel         = param('nivel', null);
        $idResponsable = param('idResponsable', null);
        $q             = param('q', null);

        $total = $model->contar($idPqrs, $nivel, $idResponsable, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    case 'responsable_activo_pqrs':
    header('Content-Type: application/json; charset=utf-8');

        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetro idPqrs inválido"]);
            break;
        }

        $res = $model->activoPorPqrs($idPqrs);

        if (is_array($res) && isset($res["__error__"])) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>$res["__error__"]]);
            break;
        }

        if ($res) {
            echo json_encode(["success"=>true, "data"=>$res]);
        } else {
            http_response_code(404);
            echo json_encode(["success"=>false, "message"=>"No hay responsable activo para este PQR"]);
        }
    break;

    case 'responsables_por_pqrs':
        header('Content-Type: application/json; charset=utf-8');

        $idPqrs = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $soloInactivos = filter_var(param('soloInactivos', null), FILTER_VALIDATE_INT);
        if (!$idPqrs) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetro idPqrs inválido"]);
            break;
        }

        $res = $model->responsablesPorPqrs($idPqrs, $soloInactivos);

        if (is_array($res) && isset($res["__error__"])) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>$res["__error__"]]);
            break;
        }

        // Devolver lista (vacía o con registros)
        echo json_encode(["success"=>true, "data"=>$res]);
    break;

    case 'asignar_responsable':
        header('Content-Type: application/json; charset=utf-8');

        $idPqrs        = filter_var(param('idPqrs', null), FILTER_VALIDATE_INT);
        $idResponsable = filter_var(param('idResponsable', null), FILTER_VALIDATE_INT);

        if (!$idPqrs || !$idResponsable) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Parámetros inválidos: idPqrs e idResponsable son requeridos"
            ]);
            break;
        }

        try {
            $res = $model->asignarResponsableAtomic($idPqrs, $idResponsable);

            if (is_string($res)) {
                // El model devolvió mensaje de error
                http_response_code(500);
                echo json_encode([
                    "success" => false,
                    "message" => "No se pudo asignar el responsable",
                    "error"   => $res
                ]);
                break;
            }

            // Éxito
            echo json_encode([
                "success"          => true,
                "message"          => "Responsable asignado correctamente",
                "activated_rows"   => $res["activated_rows"] ?? 0,
                "deactivated_rows" => $res["deactivated_rows"] ?? 0
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Error inesperado",
                "error"   => $e->getMessage()
            ]);
        }
    break;


    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|obtenerPorCompuesto|insertar|upsert|actualizar|eliminar|eliminarPorCompuesto|contar"]);
        break;
}
