<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// Puedes cambiarlo con una variable de entorno FRONTEND_ORIGIN
if (!defined('FRONTEND_ORIGIN')) {
    define('FRONTEND_ORIGIN', getenv('FRONTEND_ORIGIN') ?: 'http://localhost:4200');
}
function baseUrlFrontend(): string {
    return rtrim(FRONTEND_ORIGIN, '/');
}



// TODO: Controlador de Atenciones
// require_once('revisarsesion.controller.php');
require_once('../models/atenciones.model.php');
error_reporting(0);

$atenciones = new Atenciones();

/* =========================
   Helpers (cachean php://input)
   ========================= */
function get_json_cached(): array {
  static $cached = null;
  if ($cached !== null) return $cached;

  $raw = file_get_contents('php://input');        // <-- se lee UNA sola vez
  if (!$raw) { $cached = []; return $cached; }

  $j = json_decode($raw, true);
  $cached = is_array($j) ? $j : [];
  return $cached;
}

/** Orden de precedencia: POST -> GET -> JSON */
function param($key, $default=null) {
  if (isset($_POST[$key])) return $_POST[$key];
  if (isset($_GET[$key]))  return $_GET[$key];

  $json = get_json_cached();                      // <-- reutiliza caché
  if (array_key_exists($key, $json)) return $json[$key];

  return $default;
}


switch ($_GET["op"] ?? '') {

    // === LISTAR ===
    case 'todos':
        $limit       = (int) (param('limit', 100));
        $offset      = (int) (param('offset', 0));
        $soloActivas = param('soloActivas', null); // 1|0|null
        $idCliente   = param('idCliente', null);
        $idAgencia   = param('idAgencia', null);
        $fechaDesde  = param('fechaDesde', null); // 'YYYY-MM-DD'
        $fechaHasta  = param('fechaHasta', null); // 'YYYY-MM-DD'
        $q           = param('q', null);

        $datos = $atenciones->todos($limit, $offset, $soloActivas, $idCliente, $idAgencia, $fechaDesde, $fechaHasta, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron atenciones."]);
        }
        break;

    // === OBTENER UNO ===
    case 'uno':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $res = $atenciones->uno($idAtencion);
        if ($res) { echo json_encode($res); }
        else { http_response_code(404); echo json_encode(["message"=>"Atención no encontrada"]); }
        break;

    // === INSERTAR ===
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idCliente       = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
            $idAgencia       = param('idAgencia', null);
            $idAgencia       = is_null($idAgencia) || $idAgencia === '' ? null : (int)$idAgencia;
            $fechaAtencion   = (string)param('fechaAtencion', '');
            $numeroDocumento = trim((string)param('numeroDocumento', ''));
            $tipoDocumento   = trim((string)param('tipoDocumento', ''));
            $numeroFactura   = param('numeroFactura', null);
            $estado          = (int) (param('estado', 1));

            if (!$idCliente || $fechaAtencion==='' || $numeroDocumento==='' || $tipoDocumento==='') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Faltan parámetros requeridos"]);
                break;
            }

            $datos = $atenciones->insertar($idCliente, $idAgencia, $fechaAtencion, $numeroDocumento, $tipoDocumento, $numeroFactura, $estado);
            if (is_numeric($datos)) {
                echo json_encode(["success"=>true, "idAtencion"=>(int)$datos]);
            } else {
                $num = filter_var($datos, FILTER_VALIDATE_INT);
                if ($num !== false) {
                    echo json_encode(["success"=>true, "idAtencion"=>(int)$num]);
                } else {
                    $status = 500;
                    if (is_string($datos) && stripos($datos, 'Duplicate') !== false) { $status = 409; }
                    http_response_code($status);
                    echo json_encode(["success"=>false, "message"=>"No se pudo insertar la atención", "error"=>$datos]);
                }
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

    // === ACTUALIZAR ===
    case 'actualizar':
        $idAtencion      = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        $idCliente       = filter_var(param('idCliente', null), FILTER_VALIDATE_INT);
        $idAgencia       = param('idAgencia', null);
        $idAgencia       = is_null($idAgencia) || $idAgencia === '' ? null : (int)$idAgencia;
        $fechaAtencion   = (string)param('fechaAtencion', '');
        $numeroDocumento = trim((string)param('numeroDocumento', ''));
        $tipoDocumento   = trim((string)param('tipoDocumento', ''));
        $numeroFactura   = param('numeroFactura', null);
        $estado          = (int) (param('estado', 1));

        if (!$idAtencion || !$idCliente || $fechaAtencion==='' || $numeroDocumento==='' || $tipoDocumento==='') {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetros inválidos o incompletos"]);
            break;
        }

        $datos = $atenciones->actualizar($idAtencion, $idCliente, $idAgencia, $fechaAtencion, $numeroDocumento, $tipoDocumento, $numeroFactura, $estado);
        if (is_array($datos) && ($datos['status'] ?? '') === 'error') {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"No se pudo actualizar", "error"=>$datos['error'] ?? '']);
            break;
        }
        $affected = is_numeric($datos) ? (int)$datos : 0;
        echo json_encode(["success"=>true, "affected"=>$affected]);
        break;

    // === ELIMINAR ===
    case 'eliminar':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $datos = $atenciones->eliminar($idAtencion);
        if ($datos === 1 || $datos === '1') { echo json_encode(["success"=>true]); }
        else if ($datos === 0 || $datos === '0') { http_response_code(404); echo json_encode(["success"=>false, "message"=>"No existe"]); }
        else { $status = (is_string($datos) && stripos($datos, 'dependen') !== false) ? 409 : 500; http_response_code($status); echo json_encode(["success"=>false, "message"=>"Error al eliminar", "error"=>$datos]); }
        break;

    // === ACTIVAR / DESACTIVAR ===
    case 'activar':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $res = $atenciones->activar($idAtencion);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    case 'desactivar':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $res = $atenciones->desactivar($idAtencion);
        echo json_encode(["success"=> is_numeric($res) && $res >= 0, "affected"=> (int)$res]);
        break;

    // === CONTAR ===
    case 'contar':
        $soloActivas = param('soloActivas', null);
        $idCliente   = param('idCliente', null);
        $idAgencia   = param('idAgencia', null);
        $fechaDesde  = param('fechaDesde', null);
        $fechaHasta  = param('fechaHasta', null);
        $q           = param('q', null);
        $total = $atenciones->contar($soloActivas, $idCliente, $idAgencia, $fechaDesde, $fechaHasta, $q);
        echo json_encode(["total" => is_numeric($total) ? (int)$total : 0]);
        break;

    // === DEPENDENCIAS ===
    case 'dependencias':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        if (!$idAtencion) { http_response_code(400); echo json_encode(["message"=>"Parámetro idAtencion inválido"]); break; }
        $res = $atenciones->dependencias($idAtencion);
        echo json_encode($res);
        break;

    // === UPSERT CLIENTE + ATENCIÓN (SP) ===
    case 'upsert':
    case 'upsert_cliente_y_atencion':
        header('Content-Type: application/json; charset=utf-8');
        try {
            // Datos del cliente (cedula en TEXTO PLANO)
            $idClienteErp    = param('idClienteErp', null); // opcional
            $cedula          = trim((string)param('cedula', ''));
            $nombres         = trim((string)param('nombres', ''));
            $apellidos       = trim((string)param('apellidos', ''));
            $email           = trim((string)param('email', ''));
            $telefono        = param('telefono', null);
            $celular         = param('celular', null);

            // Datos de atención
            $idAgencia       = param('idAgencia', null); // int
            $idAgencia       = is_null($idAgencia) || $idAgencia === '' ? null : (int)$idAgencia;
            $fechaAtencion   = (string)param('fechaAtencion', ''); // YYYY-MM-DD
            $numeroDocumento = trim((string)param('numeroDocumento', ''));
            $tipoDocumento   = trim((string)param('tipoDocumento', ''));
            $numeroFactura   = param('numeroFactura', null); // opcional
            $idCanal         = param('idCanal', null);       // opcional int (validado en SP)
            $idCanal         = is_null($idCanal) || $idCanal === '' ? null : (int)$idCanal;
            $detalle         = param('detalle', null);       // opcional MEDIUMTEXT

            // NUEVO: cédula del asesor (TEXTO PLANO aquí; el modelo la cifrará antes del SP)
            $cedulaAsesor    = param('cedulaAsesor', null);

            // Programación automática (opcional)
            // $programarAuto   = (bool) (param('programarAuto', false));
            // antes de usar $programarAuto:
            $programarAuto = filter_var(param('programarAuto', false), FILTER_VALIDATE_BOOLEAN);
            $canalEnvio      = param('canalEnvio', null); // EMAIL|WHATSAPP|SMS|OTRO


            // Validación mínima (el SP valida más cosas)
            $req = ['cedula','nombres','apellidos','idAgencia','fechaAtencion','numeroDocumento','tipoDocumento'];
            $faltan = [];
            foreach ($req as $r) {
            $v = param($r, null);
            if ($v === null || $v === '') $faltan[] = $r;
            }

            if (!empty($faltan)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Faltan parámetros requeridos",
                "missing" => $faltan
            ]);
            break;
            }

            $res = $atenciones->upsertClienteYAtencion(
                $idClienteErp,
                $cedula,
                $nombres,
                $apellidos,
                $email,
                $telefono,
                $celular,
                $idAgencia,
                $fechaAtencion,
                $numeroDocumento,
                $tipoDocumento,
                $numeroFactura,
                $idCanal,
                $detalle,
                $cedulaAsesor // NUEVO
            );


            if (!is_array($res)) {
            // Si el SP hizo SIGNAL (1644), en $res viene el texto como 'La cédula del asesor...'
            $status = 500;
            // $msgLower = is_string($res) ? mb_strtolower($res, 'UTF-8') : '';
            $msgLower = is_string($res) 
                ? (function($s){ return function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s); })($res)
                : '';

            if (is_string($res) && (
                strpos($msgLower, 'sqlstate') !== false ||
                strpos($msgLower, 'la cédula del asesor') !== false ||
                strpos($msgLower, 'idcanal no existe') !== false
            )) {
                $status = 400;
            }

            http_response_code($status);
            echo json_encode(["success"=>false, "message"=>"No se pudo procesar el upsert", "error"=>$res]);
            break;
            }


            $payload = [
                "success"     => true,
                "idCliente"   => $res['idCliente'],
                "idAtencion"  => $res['idAtencion']
            ];
            if (isset($res['idAsesor'])) { $payload['idAsesor'] = $res['idAsesor']; }

            // Si pidieron programar automáticamente y tenemos idAtencion válido
            if ($programarAuto && !empty($res['idAtencion']) && !empty($canalEnvio)) {
                $prog = $atenciones->programarDespuesDeUpsertAuto($res['idAtencion'], $canalEnvio);
                if (is_array($prog)) {
                    $payload['idProgEncuesta'] = $prog['idProgEncuesta'];

                    // >>>>>> ENVÍO DE CORREO SI EL CANAL ES EMAIL <<<<<<
                    if (
                        strtoupper((string)$canalEnvio) === 'EMAIL' &&
                        !empty($email) &&
                        filter_var($email, FILTER_VALIDATE_EMAIL)
                    ) {
                        // reutiliza tu helper (está en el mismo directorio /controllers)
                        require_once(__DIR__ . '/email.controller.php');

                        $nombreCompleto = trim($nombres . ' ' . $apellidos);

                        $opts = [
                            'idProgEncuesta' => $prog['idProgEncuesta'],
                            'linkEncuesta'   => param('linkEncuesta', ''), 

                            //   'cc'             => ['experiencia_cliente@imbauto.com'],
                            'bcc'            => ['adrian_am3@hotmail.com']
                        ];

                        $mailRes = enviarEmailEncuestaProgramada(
                            $email,
                            $nombreCompleto,
                            $res['idAtencion'],     // <-- el id de la atención recién creada/actualizada
                            $fechaAtencion,
                            $opts
                        );

                        // no rompas el flujo por el correo; solo anótalo
                        $payload['emailStatus'] = ($mailRes === 'ok') ? 'sent' : ('error: ' . $mailRes);
                    }
                    // <<<<<< FIN ENVÍO EMAIL <<<<<<

                } else {
                    $payload['programarError'] = $prog; // no rompe el upsert
                }
            }

            echo json_encode($payload);


        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success"=>false, "message"=>"Ocurrió un error inesperado", "error"=>$e->getMessage()]);
        }
        break;

        // === PROGRAMAR DESPUÉS DE UPSERT (SP directo) ===
        case 'programar_auto':
            header('Content-Type: application/json; charset=utf-8');

            $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
            $canalEnvio = (string) param('canalEnvio', '');
            if (!$idAtencion || $canalEnvio==='') {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"Parámetros requeridos: idAtencion, canalEnvio"]);
                break;
            }

            $resProg = $atenciones->programarDespuesDeUpsertAuto($idAtencion, $canalEnvio);
            if (!is_array($resProg)) {
                http_response_code(400);
                echo json_encode(["success"=>false, "message"=>"No se pudo programar la encuesta", "error"=>$resProg]);
                break;
            }

            // Armamos respuesta base
            $payload = ["success"=>true, "idProgEncuesta"=>$resProg['idProgEncuesta']];

            // >>>>>> ENVÍO DE CORREO SI EL CANAL ES EMAIL <<<<<<
            if (strtoupper($canalEnvio) === 'EMAIL') {
                // Trae datos de la atención/cliente
                $row = $atenciones->uno($idAtencion);
                // row trae: a.*, c.nombres, c.apellidos, c.email, ag.nombre, etc.
                $email          = trim((string)($row['email'] ?? ''));
                $nombres        = trim((string)($row['nombres'] ?? ''));
                $apellidos      = trim((string)($row['apellidos'] ?? ''));
                $fechaAtencion  = (string)($row['fechaAtencion'] ?? '');

                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    require_once(__DIR__ . '/email.controller.php');

                    $nombreCompleto = trim($nombres . ' ' . $apellidos);
                    
                    $opts = [
                        'idProgEncuesta' => $resProg['idProgEncuesta'],
                        'linkEncuesta'   => param('linkEncuesta', ''), 
                         //   'cc'             => ['experiencia_cliente@imbauto.com'],
                        'bcc'            => ['adrian_am3@hotmail.com']
                    ];

                    $mailRes = enviarEmailEncuestaProgramada(
                        $email,
                        $nombreCompleto,
                        $idAtencion,
                        $fechaAtencion,
                        $opts
                    );

                    

                    $payload['emailStatus'] = ($mailRes === 'ok') ? 'sent' : ('error: ' . $mailRes);
                } else {
                    $payload['emailStatus'] = 'skip: email inválido o vacío';
                }
            }
            // <<<<<< FIN ENVÍO EMAIL <<<<<<

            echo json_encode($payload);
            break;



        case 'validar_asesor':
        header('Content-Type: application/json; charset=utf-8');
        $ced = trim((string) param('cedulaAsesor', ''));
        if ($ced === '') {
            http_response_code(400);
            echo json_encode(["success"=>false, "message"=>"Parámetro requerido: cedulaAsesor"]);
            break;
        }
        $row = $atenciones->validarAsesorPorCedula($ced);
        if ($row) {
            echo json_encode([
                "success"=>true, "exists"=>true,
                "idAsesor"=>(int)$row['idAsesor'],
                "nombres"=>$row['nombres'], "apellidos"=>$row['apellidos'], "email"=>$row['email']
            ]);
        } else {
            http_response_code(404);
            echo json_encode(["success"=>false, "exists"=>false, "message"=>"La cédula del asesor no corresponde a ningún usuario"]);
        }
        break;


    default:
        http_response_code(400);
        echo json_encode(["message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|dependencias|upsert|programar_auto"]);
        break;
}
