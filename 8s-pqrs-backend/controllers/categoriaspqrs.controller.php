<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// TODO: Controlador de Categorías PQRS
//require_once('revisarsesion.controller.php');
require_once('../models/categoriaspqrs.model.php');
error_reporting(0);

$categorias = new CategoriasPQRS();

// === Helpers ===
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

    // === LISTAR ===
    case 'todos':
        $limit          = (int) (param('limit', 100));
        $offset         = (int) (param('offset', 0));
        $soloActivos    = param('soloActivos', null); // 1|0|null
        $idCategoriaPadre = param('idCategoriaPadre', null);
        $idCanal        = param('idCanal', null);
        $idTipo         = param('idTipo', null);
        $q              = param('q', null);

        $datos = $categorias->todos($limit, $offset, $soloActivos, $idCategoriaPadre, $idCanal, $idTipo, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron categorías PQRS."]);
        }
        break;

    // === OBTENER UNO ===
    case 'uno':
        $idCategoria = filter_var(param('idCategoria', null), FILTER_VALIDATE_INT);
        if (!$idCategoria) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCategoria inválido"]); break; }
        $res = $categorias->uno($idCategoria);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Categoría PQRS no encontrada"]); }
        break;

    // === INSERTAR ===
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $nombre            = trim((string) param('nombre'));
            $descripcion       = (string) param('descripcion', null);
            $idCategoriaPadre  = filter_var(param('idCategoriaPadre'), FILTER_VALIDATE_INT);
            $idCanal           = filter_var(param('idCanal'), FILTER_VALIDATE_INT);
            $idTipo            = filter_var(param('idTipo'), FILTER_VALIDATE_INT);
            $estado            = (int) (param('estado', 1));

            if ($nombre==='' || !$idCategoriaPadre || !$idCanal || !$idTipo) {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Faltan parámetros requeridos"]);
                break;
            }

            $datos = $categorias->insertar($nombre, $descripcion, $idCategoriaPadre, $idCanal, $idTipo, $estado);
            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idCategoria"=>(int)$datos]);
            } else {
                // Puede venir un string de error (p.ej. Duplicate entry)
                $num = filter_var($datos, FILTER_VALIDATE_INT);
                if ($num !== false) {
                    echo json_encode(["success"=>true, "idCategoria"=>(int)$num]);
                } else {
                    $status = 500;
                    if (is_string($datos) && stripos($datos, 'Duplicate') !== false) { $status = 409; }
                    http_response_code($status);
                    echo json_encode(["success"=>false, "message"=>"No se pudo insertar la categoría", "error"=>$datos]);
                }
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR ===
    case 'actualizar':
        $idCategoria      = filter_var(param('idCategoria', null), FILTER_VALIDATE_INT);
        $nombre           = trim((string) param('nombre'));
        $descripcion      = (string) param('descripcion', null);
        $idCategoriaPadre = filter_var(param('idCategoriaPadre', null), FILTER_VALIDATE_INT);
        $idCanal          = filter_var(param('idCanal', null), FILTER_VALIDATE_INT);
        $idTipo           = filter_var(param('idTipo', null), FILTER_VALIDATE_INT);
        $estado           = (int) (param('estado', 1));

        if (!$idCategoria || $nombre==='' || !$idCategoriaPadre || !$idCanal || !$idTipo) {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $categorias->actualizar($idCategoria, $nombre, $descripcion, $idCategoriaPadre, $idCanal, $idTipo, $estado);
        $affected = is_numeric($datos) ? (int)$datos : 0;
        echo json_encode(["success"=>true, "affected"=>$affected, "raw"=>$datos]);
        break;

    // === ELIMINAR ===
    case 'eliminar':
        $idCategoria = filter_var(param('idCategoria', null), FILTER_VALIDATE_INT);
        if (!$idCategoria) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCategoria inválido"]); break; }
        $datos = $categorias->eliminar($idCategoria);
        if ($datos === 1 || $datos === '1') {
            echo json_encode(["success"=>true]);
        } else if ($datos === 0 || $datos === '0') {
            http_response_code(404);
            echo json_encode(["success"=>false, "message"=>"No existe"]);
        } else {
            $status = 500;
            if (is_string($datos) && (stripos($datos, 'dependen') !== false || stripos($datos, 'relacion') !== false)) { $status = 409; }
            http_response_code($status);
            echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]);
        }
        break;

    // === ACTIVAR / DESACTIVAR ===
    case 'activar':
        $idCategoria = filter_var(param('idCategoria', null), FILTER_VALIDATE_INT);
        if (!$idCategoria) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCategoria inválido"]); break; }
        $res = $categorias->activar($idCategoria);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idCategoria = filter_var(param('idCategoria', null), FILTER_VALIDATE_INT);
        if (!$idCategoria) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCategoria inválido"]); break; }
        $res = $categorias->desactivar($idCategoria);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR (para paginación) ===
    case 'contar':
        $soloActivos     = param('soloActivos', null);
        $idCategoriaPadre= param('idCategoriaPadre', null);
        $idCanal         = param('idCanal', null);
        $idTipo          = param('idTipo', null);
        $q               = param('q', null);
        $total = $categorias->contar($soloActivos, $idCategoriaPadre, $idCanal, $idTipo, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // === DEPENDENCIAS ===
    case 'dependencias':
        $idCategoria = filter_var(param('idCategoria', null), FILTER_VALIDATE_INT);
        if (!$idCategoria) { http_response_code(400); echo json_encode(["message"=>"Parámetro idCategoria inválido"]); break; }
        $res = $categorias->dependencias($idCategoria);
        echo json_encode($res);
        break;

    default:
        http_response_code(400);
        echo json_encode(["message"=>"Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|dependencias"]);
        break;
}
