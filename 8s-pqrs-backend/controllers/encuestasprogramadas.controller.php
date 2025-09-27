<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") {
    die();
}

// TODO: Controlador de encuestas programadas
require_once('../models/encuestasprogramadas.model.php');
error_reporting(0); // Deshabilitar errores visibles en producción

$ep = new EncuestasProgramadas();

// Helpers
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw)
        return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function param($key, $default = null)
{
    static $json = null;
    if ($json === null)
        $json = read_json_body();
    if (isset($_POST[$key]))
        return $_POST[$key];
    if (isset($_GET[$key]))
        return $_GET[$key];
    if (isset($json[$key]))
        return $json[$key];
    return $default;
}


function get_client_ip_real(): string
{
    $keys = [
        'HTTP_X_FORWARDED_FOR', // si hay proxy/balancer
        'HTTP_CLIENT_IP',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $candidates = array_map('trim', explode(',', $_SERVER[$k])); // XFF puede traer lista
            foreach ($candidates as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP))
                    continue;
                // normaliza ::1 a 127.0.0.1
                if ($ip === '::1')
                    return '127.0.0.1';
                return $ip;
            }
        }
    }
    return '127.0.0.1'; // fallback
}


switch ($_GET["op"] ?? '') {

    // === LISTAR
    case 'todos':
        $limit = (int) (param('limit', 100));
        $offset = (int) (param('offset', 0));
        $soloActivas = param('soloActivas', null);
        $estadoEnvio = param('estadoEnvio', null);
        $canalEnvio = param('canalEnvio', null);
        $idEncuesta = param('idEncuesta', null);
        $idAtencion = param('idAtencion', null);
        $idCliente = param('idCliente', null);
        $desde = param('desde', null);
        $hasta = param('hasta', null);
        $q = param('q', null);

        $datos = $ep->todos($limit, $offset, $soloActivas, $estadoEnvio, $canalEnvio, $idEncuesta, $idAtencion, $idCliente, $desde, $hasta, $q);
        if (is_array($datos) && count($datos) > 0) {
            echo json_encode($datos);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No se encontraron programaciones."]);
        }
        break;

    // === OBTENER UNO
    case 'uno':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }
        $res = $ep->uno($id);
        if ($res) {
            echo json_encode($res);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "No encontrado"]);
        }
        break;

    // === INSERTAR (opcional: se sugiere usar SP de programación)
    case 'insertar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idEncuesta = filter_var(param('idEncuesta'), FILTER_VALIDATE_INT);
            $idAtencion = filter_var(param('idAtencion'), FILTER_VALIDATE_INT);
            $idCliente = filter_var(param('idCliente'), FILTER_VALIDATE_INT);
            $fechaProg = (string) param('fechaProgramadaInicial');
            $maxIntentos = (int) param('maxIntentos', 3);
            $proximo = param('proximoEnvio', null);
            $estadoEnvio = param('estadoEnvio', 'PENDIENTE');
            $canalEnvio = param('canalEnvio', null);
            $enviadoPor = param('enviadoPor', null);
            $observ = param('observacionEnvio', null);
            $asunto = param('asuntoCache', null);
            $cuerpo = param('cuerpoHtmlCache', null);
            $token = param('tokenEncuesta', null);
            $obs = param('observaciones', null);
            $estado = (int) param('estado', 1);

            if (!$idEncuesta || !$idAtencion || !$idCliente || $fechaProg === '') {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Parámetros requeridos: idEncuesta, idAtencion, idCliente, fechaProgramadaInicial"]);
                break;
            }

            $datos = $ep->insertar(
                $idEncuesta,
                $idAtencion,
                $idCliente,
                $fechaProg,
                $maxIntentos,
                $proximo,
                $estadoEnvio,
                $canalEnvio,
                $enviadoPor,
                $observ,
                $asunto,
                $cuerpo,
                $token,
                $obs,
                $estado
            );
            if (is_numeric($datos)) {
                echo json_encode(["success" => true, "idProgEncuesta" => (int) $datos]);
            } else {
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "No se pudo insertar", "error" => $datos]);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Error inesperado", "error" => $e->getMessage()]);
        }
        break;

    // === ACTUALIZAR (parcial)
    case 'actualizar':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }
        $campos = param('campos', []);
        if (!is_array($campos))
            $campos = [];
        $res = $ep->actualizar($id, $campos);
        if (is_numeric($res))
            echo json_encode(["success" => true, "affected" => (int) $res]);
        else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "No se pudo actualizar", "error" => $res]);
        }
        break;

    // === ELIMINAR
    case 'eliminar':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }
        $res = $ep->eliminar($id);
        if ($res === 1 || $res === '1') {
            echo json_encode(["success" => true]);
        } else if ($res === 0 || $res === '0') {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "No existe"]);
        } else {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "No se pudo eliminar", "error" => $res]);
        }
        break;

    // === ACTIVAR / DESACTIVAR
    case 'activar':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }
        $res = $ep->activar($id);
        echo json_encode(["success" => is_numeric($res) && $res >= 0, "affected" => (int) $res]);
        break;

    case 'desactivar':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }
        $res = $ep->desactivar($id);
        echo json_encode(["success" => is_numeric($res) && $res >= 0, "affected" => (int) $res]);
        break;

    // === CONTAR
    case 'contar':
        $soloActivas = param('soloActivas', null);
        $estadoEnvio = param('estadoEnvio', null);
        $canalEnvio = param('canalEnvio', null);
        $idEncuesta = param('idEncuesta', null);
        $idAtencion = param('idAtencion', null);
        $idCliente = param('idCliente', null);
        $desde = param('desde', null);
        $hasta = param('hasta', null);
        $q = param('q', null);
        $total = $ep->contar($soloActivas, $estadoEnvio, $canalEnvio, $idEncuesta, $idAtencion, $idCliente, $desde, $hasta, $q);
        echo json_encode(["total" => is_numeric($total) ? (int) $total : 0]);
        break;

    // === DEPENDENCIAS
    case 'dependencias':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }
        echo json_encode($ep->dependencias($id));
        break;

    // === SP: programar por atención
    case 'programar_por_atencion':
        $idAtencion = filter_var(param('idAtencion', null), FILTER_VALIDATE_INT);
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idAtencion || !$idEncuesta) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetros requeridos: idAtencion, idEncuesta"]);
            break;
        }
        $res = $ep->sp_programar_por_atencion($idAtencion, $idEncuesta);
        if ($res === 1 || $res === '1')
            echo json_encode(["success" => true]);
        else {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => $res]);
        }
        break;

    // === SP: programar por encuesta (para todas las atenciones sin programación)
    case 'programar_por_encuesta':
        $idEncuesta = filter_var(param('idEncuesta', null), FILTER_VALIDATE_INT);
        if (!$idEncuesta) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idEncuesta inválido"]);
            break;
        }
        $res = $ep->sp_programar_encuestas_por_encuesta($idEncuesta);
        if ($res === 1 || $res === '1')
            echo json_encode(["success" => true]);
        else {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => $res]);
        }
        break;

    // === SP: marcar envío manual
    case 'marcar_envio':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        $canal = (string) param('canal', 'EMAIL'); // EMAIL|WHATSAPP|SMS|OTRO
        $observacion = (string) param('observacion', '');
        $asunto = param('asunto', null);
        $cuerpo = param('cuerpo', null);
        if (!$id || !$idUsuario) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetros requeridos: idProgEncuesta, idUsuario"]);
            break;
        }
        $res = $ep->sp_marcar_envio_manual($id, $idUsuario, $canal, $observacion, $asunto, $cuerpo);
        if ($res === 1 || $res === '1')
            echo json_encode(["success" => true]);
        else {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => $res]);
        }
        break;

    // === SP: marcar no contestada
    case 'marcar_no_contestada':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }
        $res = $ep->sp_marcar_no_contestada($id);
        if ($res === 1 || $res === '1')
            echo json_encode(["success" => true]);
        else {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => $res]);
        }
        break;

    // === SP: reabrir encuesta
    case 'reabrir':
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        $idUsuario = filter_var(param('idUsuario', null), FILTER_VALIDATE_INT);
        $observ = (string) param('observacion', '');
        $reset = (int) param('reset_intentos', 0);
        if (!$id || !$idUsuario) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetros requeridos: idProgEncuesta, idUsuario"]);
            break;
        }
        $res = $ep->sp_reabrir_encuesta($id, $idUsuario, $observ, $reset);
        if ($res === 1 || $res === '1')
            echo json_encode(["success" => true]);
        else {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => $res]);
        }
        break;

    case 'registrar_consentimiento':
        header('Content-Type: application/json; charset=utf-8');

        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        $acepta = (int) param('acepta', 1);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }

        require_once(__DIR__ . '/security_link.php');
        require_once(__DIR__ . '/email.controller.php');
        require_once('../models/encuestasprogramadas.model.php'); // si no estaba
        // $ep ya lo tienes instanciado arriba; si no, instáncialo.

        // --- DEBUG: confirmar include y función --- //
        error_log('INC_FILES=' . json_encode(get_included_files()));
        if (!function_exists('enviarEmailConsentimientoHTML')) {
            $userFns = get_defined_functions();
            error_log('CONSENT: enviarEmailConsentimientoHTML NO está definida. Funciones user cargadas: ' . implode(',', $userFns['user']));
        }

        // Traer datos de la programación para armar token y correo
        $row = $ep->uno($id); // debe traer idCliente, email, nombres, apellidos, idEncuesta, etc.
        if (!$row) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Programación no encontrada"]);
            break;
        }

        $ipReal = get_client_ip_real();
        if ($ipReal === '::1') {
            $ipReal = '127.0.0.1';
        }

        // intentar varios nombres de columna posibles
        $emailCli = trim((string) (
            $row['email']
            ?? $row['correo']
            ?? $row['emailCliente']
            ?? $row['correoCliente']
            ?? ''
        ));

        $nomCli = trim(
            ($row['nombres'] ?? $row['nombresCliente'] ?? '')
            . ' '
            . ($row['apellidos'] ?? $row['apellidosCliente'] ?? '')
        );

        // Fallback: si sigue vacío, trae el cliente por idProgEncuesta
        if ($emailCli === '' || !filter_var($emailCli, FILTER_VALIDATE_EMAIL)) {
            $cli = $ep->clientePorProg($id);  // <-- método nuevo abajo
            if (is_array($cli)) {
                $emailCli = trim((string) ($cli['email'] ?? $cli['correo'] ?? ''));
                if ($nomCli === '') {
                    $nomCli = trim(($cli['nombres'] ?? '') . ' ' . ($cli['apellidos'] ?? ''));
                }
            }
        }

        // Log auxiliar
        error_log("CONSENT_FALLBACK_EMAIL='{$emailCli}' nom='{$nomCli}'");

        $idCli = (int) ($row['idCliente'] ?? 0);

        // Token/firma única (no expira)
        $firmaToken = build_consent_token($idCli, $id, $emailCli);      
        $userAgentDesc = "ENCUESTA: " . ($row['idEncuesta'] ?? '') . '-' . ($row['nombreEncuesta'] ?? '(sin nombre)');

        // Trae detalle/fecha de la atención (usa 'uno' si ya lo trae)
        $detAt = $row['detalle'] ?? null;
        $fechaAt = $row['fechaAtencion'] ?? null;
        $idAtencion = (int) ($row['idAtencion'] ?? 0);

        if ($detAt === null || $fechaAt === null || !$idAtencion) {
            $aux = $ep->detalleAtencionPorProg($id); // helper nuevo del model
            if (is_array($aux)) {
                $detAt = $detAt ?? ($aux['detalle'] ?? null);
                $fechaAt = $fechaAt ?? ($aux['fechaAtencion'] ?? null);
                $idAtencion = $idAtencion ?: (int) ($aux['idAtencion'] ?? 0);
            }
        }

        // Llamar SP con firma
        $res = $ep->sp_registrar_consentimiento($id, $acepta, $ipReal, $userAgentDesc, $firmaToken);
        $already = is_string($res) && stripos($res, 'Consentimiento ya registrado') !== false;

        if ($res === 1 || $res === '1' || $already) {
            $emailStatus = 'skip';
            $mailRes = null; // <-- para evitar logs vacíos

            // Datos de atención (si no venían en $row)
            if ($detAt === null || $fechaAt === null || !$idAtencion) {
                $aux = $ep->detalleAtencionPorProg($id);
                if (is_array($aux)) {
                    $detAt = $detAt ?? ($aux['detalle'] ?? null);
                    $fechaAt = $fechaAt ?? ($aux['fechaAtencion'] ?? null);
                    $idAtencion = $idAtencion ?: (int) ($aux['idAtencion'] ?? 0);
                }
            }

            // Log único de “preparación”
            error_log(
                "CONSENT_MAIL PREP: to='{$emailCli}' valid=" .
                (filter_var($emailCli, FILTER_VALIDATE_EMAIL) ? 1 : 0) .
                " nombre='{$nomCli}' idAt={$idAtencion} fechaAt='{$fechaAt}' ua='{$userAgentDesc}' detAt='" .
                substr((string) $detAt, 0, 120) . "' acepta={$acepta}"
            );

// Enviar solo si acepta=1 y el email es válido
            if ($acepta === 1 && filter_var($emailCli, FILTER_VALIDATE_EMAIL)) {
                try {
                    error_log("CONSENT_CALL: entrando a enviarEmailConsentimientoHTML() para {$emailCli}");

                    if (function_exists('enviarEmailConsentimientoHTML')) {
                        $mailRes = enviarEmailConsentimientoHTML(
                            $emailCli,
                            $nomCli,
                            [
                                'firmaToken' => $firmaToken,
                                'userAgentDesc' => $userAgentDesc,
                                'idAtencion' => $idAtencion,
                                'fechaAtencion' => $fechaAt,
                                'detalleAtencion' => (string) $detAt,
                                // 'cc'  => ['experiencia_cliente@imbauto.com'],
                                // 'bcc' => ['auditoria@imbauto.com'],
                            ]
                        );
                        error_log("CONSENT_AFTER_CALL: enviarEmailConsentimientoHTML() devolvió '{$mailRes}'");
                        $emailStatus = ($mailRes === 'ok') ? 'sent' : ('error: ' . $mailRes);
                    } else {
                        error_log('CONSENT: enviarEmailConsentimientoHTML NO existe, usando fallback enviarEmailConsentimientoAceptado()');
                        $mailRes = enviarEmailConsentimientoAceptado($emailCli, $nomCli, $firmaToken, date('Y-m-d H:i:s'));
                        error_log("CONSENT_AFTER_CALL (fallback): '{$mailRes}'");
                        $emailStatus = ($mailRes === 'ok') ? 'sent' : ('error: ' . $mailRes);
                    }
                } catch (Throwable $e) {
                    $emailStatus = 'error: ' . $e->getMessage();
                    error_log("CONSENT_MAIL EXCEPTION: " . $e->getMessage());
                }
            } else {
                $emailStatus = 'skip: acepta!=1 o email inválido';
                error_log("CONSENT_SKIP: acepta={$acepta} emailValid=" . (int) filter_var($emailCli, FILTER_VALIDATE_EMAIL));
            }

            echo json_encode([
                "success" => true,
                "message" => $already ? "Consentimiento ya estaba registrado" : "Consentimiento registrado",
                "emailStatus" => $emailStatus,
                "firmaToken" => $firmaToken
            ]);

            // Logea resultado SOLO si hubo intento de envío
            if ($mailRes !== null) {
                error_log("CONSENT_MAIL RESULT={$mailRes}");
            }
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "error" => $res]);
        }
        break;


        // === SP: generar PQR desde respuestas de una programación
    case 'generar_pqrs':
        header('Content-Type: application/json; charset=utf-8');
        $id = filter_var(param('idProgEncuesta', null), FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Parámetro idProgEncuesta inválido"]);
            break;
        }
        $res = $ep->sp_generar_pqrs_desde_respuestas($id);
        if (is_array($res)) {
            // Tenemos idPqrs
            echo json_encode(["success" => true, "idPqrs" => $res['idPqrs']]);
        } else if ($res === 1 || $res === '1') {
            // Se ejecutó ok, pero no pudimos leer idPqrs (fallback)
            echo json_encode(["success" => true]);
        } else {
            // Error de negocio (SIGNAL) o técnico
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "No se pudo generar el PQR desde las respuestas",
                "error" => $res
            ]);
        }
        break;



    default:
        http_response_code(400);
        echo json_encode([
            "message" => "Operación no soportada. Usa op=todos|uno|insertar|actualizar|eliminar|activar|desactivar|contar|dependencias|programar_por_atencion|programar_por_encuesta|marcar_envio|marcar_no_contestada|reabrir|registrar_consentimiento|generar_pqrs"
        ]);
        break;

}
