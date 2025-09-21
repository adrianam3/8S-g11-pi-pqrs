<?php
/**
 * Controller: encuestas
 * Estilo basado en tu controlador existente (headers CORS, método OPTIONS, op por querystring)
 * Endpoints (usar ?op=...):
 *  - GET    op=listar                [&limit=100&offset=0&soloActivas=1|0]
 *  - GET    op=ver                   &id=ID
 *  - GET    op=buscar                &q=texto[&limit=50&offset=0&soloActivas=1|0]
 *  - POST   op=crear                 (body: JSON o x-www-form-urlencoded)
 *  - POST   op=actualizar            &id=ID        (body)
 *  - POST   op=activar               &id=ID
 *  - POST   op=desactivar            &id=ID
 *  - POST   op=eliminar              &id=ID
 *  - DELETE op=eliminar              &id=ID
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Authorization, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, PATCH, DELETE');
header('Allow: GET, POST, OPTIONS, PUT, PATCH, DELETE');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

// Dependencias opcionales (si existen en tu proyecto)
$baseDir = __DIR__;
$tryIncludes = [
    $baseDir . '/email.controller.php',
    $baseDir . '/revisarsesion.controller.php',
];
foreach ($tryIncludes as $inc) { if (file_exists($inc)) { @require_once $inc; } }

// Modelo
require_once __DIR__ . '/../models/encuesta.model.php';

// Helper: leer body JSON de forma segura
function body_json(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Helper: obtener escalar (GET/POST/JSON)
function inparam(string $key, $default = null) {
    $json = body_json();
    if (isset($_GET[$key])) return $_GET[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($json[$key])) return $json[$key];
    return $default;
}

// Instancia del modelo
$model = new EncuestaModel();

// Enrutamiento por operación
$op = strtolower((string)($_GET['op'] ?? $_POST['op'] ?? body_json()['op'] ?? ''));

try {
    switch ($op) {
        case 'listar': {
            $limit = (int) inparam('limit', 100);
            $offset = (int) inparam('offset', 0);
            $soloActivas = inparam('soloActivas', null);
            if ($soloActivas !== null) {
                $soloActivas = (string)$soloActivas === '1' || $soloActivas === 1 || $soloActivas === true;
            }
            $out = $model->todos($limit, $offset, $soloActivas);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'ver': {
            $id = (int) inparam('id', 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Parámetro id inválido']); break; }
            $out = $model->porId($id);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'buscar': {
            $q = (string) inparam('q', '');
            $limit = (int) inparam('limit', 50);
            $offset = (int) inparam('offset', 0);
            $soloActivas = inparam('soloActivas', null);
            if ($soloActivas !== null) {
                $soloActivas = (string)$soloActivas === '1' || $soloActivas === 1 || $soloActivas === true;
            }
            $out = $model->buscar($q, $limit, $offset, $soloActivas);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'crear': {
            $data = body_json();
            if (!$data) { $data = $_POST; }
            // Validación básica
            $requeridos = ['nombre','asuntoCorreo','remitenteNombre','scriptInicio','idCanal'];
            foreach ($requeridos as $k) {
                if (!isset($data[$k]) || $data[$k] === '') {
                    http_response_code(400);
                    echo json_encode(['status'=>'error','message'=>"Falta parámetro requerido: $k"]);
                    exit;
                }
            }
            $out = $model->crear($data);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'actualizar': {
            $id = (int) inparam('id', 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Parámetro id inválido']); break; }
            $data = body_json(); if (!$data) { $data = $_POST; }
            $out = $model->actualizar($id, $data);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'activar': {
            $id = (int) inparam('id', 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Parámetro id inválido']); break; }
            $out = $model->activar($id);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'desactivar': {
            $id = (int) inparam('id', 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Parámetro id inválido']); break; }
            $out = $model->desactivar($id);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'eliminar': {
            $id = (int) inparam('id', 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Parámetro id inválido']); break; }
            $out = $model->eliminar($id);
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            break;
        }
        default: {
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Operación no soportada. Usa op=listar|ver|buscar|crear|actualizar|activar|desactivar|eliminar']);
            break;
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Excepción no controlada','error'=>$e->getMessage()]);
}
