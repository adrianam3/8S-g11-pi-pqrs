<?php
require '../vendor/autoload.php';
require_once(__DIR__ . '/security_link.php'); // << importar utilidades
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// function configurarMail(PHPMailer $mail, $emailRecibe, $nombreRecibe)
// {
//     // Configuración del servidor SMTP
//     $mail->isSMTP();
//     $mail->Host = 'smtp.gmail.com';
//     $mail->SMTPAuth = true;
//     $mail->Username = 'sistemas.imbauto@gmail.com';
//     $mail->Password = 'jyru gtfv zgsp kfxp';
//     $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
//     $mail->Port = 587;

//     // Emisor y receptor
//     $mail->setFrom('sistemas.imbauto@gmail.com', 'CENTRO DE ATENCIÓN AL CLIENTE DE IMBAUTO');
//     $mail->addAddress($emailRecibe, $nombreRecibe);
//     $mail->isHTML(true);
//     $mail->CharSet = 'UTF-8'; // Asegúrate de que la codificación es UTF-8
// }

// === Helpers CC/BCC desde variables de entorno (opcional) ===
function _parseList($s): array
{
    if (!is_string($s) || trim($s) === '')
        return [];
    return array_values(array_filter(array_map('trim', explode(',', $s))));
}
function _defaultCcList(): array
{
    return _parseList(getenv('MAIL_CC') ?: '');
}
function _defaultBccList(): array
{
    return _parseList(getenv('MAIL_BCC') ?: '');
}
function _defaultReplyTo(): ?string
{
    return getenv('MAIL_REPLY_TO') ?: null;
}

/** Configura PHPMailer; permite CC/BCC opcionales por llamada */
function configurarMail(
    PHPMailer $mail,
    $emailRecibe,
    $nombreRecibe,
    ?array $cc = null,
    ?array $bcc = null
) {
    // SMTP
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'sistemas.imbauto@gmail.com';
    $mail->Password = 'jyru gtfv zgsp kfxp';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // From / To
    $mail->setFrom('sistemas.imbauto@gmail.com', 'CENTRO DE ATENCIÓN AL CLIENTE DE IMBAUTO');
    $mail->addAddress($emailRecibe, $nombreRecibe);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    // CC/BCC (si no vienen, toma del entorno)
    $cc = $cc ?? _defaultCcList();
    $bcc = $bcc ?? _defaultBccList();
    foreach ($cc as $c) {
        if ($c !== '')
            $mail->addCC($c);
    }
    foreach ($bcc as $b) {
        if ($b !== '')
            $mail->addBCC($b);
    }

    if ($rt = _defaultReplyTo())
        $mail->addReplyTo($rt);
}


function enviarEmailCrearTicket($emailRecibe, $nombreRecibe, $nombreCreadorTicket, $asunto, $detalle)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Determinar mensaje
        $mensaje = ($nombreRecibe !== $nombreCreadorTicket)
            ? "Se ha creado un nuevo ticket con los siguientes detalles:<br/>"
            : "Haz creado un nuevo ticket con los siguientes detalles:<br/>";

        $mostrarDe = ($nombreRecibe !== $nombreCreadorTicket);

        // Asunto y cuerpo del mensaje
        $mail->Subject = 'Alerta de nuevo ticket - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $mensaje
  EOT;
        if ($mostrarDe) {
            $body .= "<strong>De:</strong> $nombreCreadorTicket<br/>";
        }
        $body .= <<<EOT
          <strong>Asunto:</strong> $asunto<br/>
          <strong>Detalle:</strong> $detalle<br/>
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje";
        if ($mostrarDe) {
            $altBody .= "\nDe: $nombreCreadorTicket";
        }
        $altBody .= "\nAsunto: $asunto\nDetalle: $detalle";
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
    }
}

function enviarEmailAgenteAsignado($idTicket, $emailRecibe, $nombreRecibe, $nombrequienAsignaTicket, $asunto)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Mensaje y asunto
        $mensaje = "Ticket #$idTicket te ha sido asignado por $nombrequienAsignaTicket.<br/>";
        $mail->Subject = 'Te han asignado un Ticket - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $mensaje
          <strong>De:</strong> $nombrequienAsignaTicket<br/>
          <strong>Título:</strong> $asunto<br/>
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje\nDe: $nombrequienAsignaTicket\nTítulo: $asunto";
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
    }
}

function enviarEmailRecuperacion($email)
{
    try {
        $mail = new PHPMailer(true);
        configurarMail($mail, $email, '');
        $token = generateToken($email);
        $resetLink = "http://localhost:4200/olvido-contrasena?token=$token&usuario=" . base64_encode($email);
        $mail->Subject = 'Restablecer Contraseña - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola,<br/>
          Haz clic en el siguiente enlace para restablecer tu contraseña:<br/>
          <a href="$resetLink">$resetLink</a><br/><br/>
          Si no solicitaste un restablecimiento de contraseña, por favor ignora este correo.
        EOT;
        $mail->Body = $body;
        $altBody = "Hola,\nHaz clic en el siguiente enlace para restablecer tu contraseña:\n$resetLink";
        $mail->AltBody = $altBody;
        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al enviar el correo.']);
    }
}

function enviarEmailCrearCuenta($emailRecibe, $nombreRecibe, $password)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Mensaje
        $asunto = "Te han creado una cuenta para la plataforma HELPDESK IMBAUTO.<br/>";
        $mensaje = "Estas son tus credenciales.<br/>";
        $mail->Subject = 'Creación de cuenta - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $asunto
          $mensaje
          <strong>Usuario:</strong> $emailRecibe<br/>
          <strong>Contraseña:</strong> $password<br/>
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje\nUsuario: $emailRecibe\Contraseña: $password";
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
    }
}
function enviarEmailMensajeDetalleTicket($idTicket, $emailRecibe, $nombreRecibe, $detalle)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Mensaje y asunto
        $mensaje = "Se ha añadido un mensaje al Ticket #$idTicket.<br/>";
        $mail->Subject = 'Actualización de Ticket - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $mensaje
          <strong>Mensaje:</strong> $detalle<br/>
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje\nTítulo: $detalle";
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
    }
}
function enviarEmailTicketCerrado($idTicket, $emailRecibe, $nombreRecibe)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Mensaje y asunto
        $mensaje = "Se cerró el Ticket #$idTicket.<br/>";
        $mail->Subject = 'Ticket Cerrado - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $mensaje
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje";
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
    }
}

function enviarEmailTReapertura($idTicket, $emailRecibe, $nombreRecibe, $nombrequienAsignaTicket, $asunto)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Mensaje y asunto
        $mensaje = " El Ticket #$idTicket ha sido reaperturado por $nombrequienAsignaTicket.<br/>";
        $mail->Subject = 'Ticket Reaperturado - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $mensaje
          <strong>De:</strong> $nombrequienAsignaTicket<br/>
          <strong>Título:</strong> $asunto<br/>
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje\nDe: $nombrequienAsignaTicket\nTítulo: $asunto";
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
    }
}

function enviarEmailTReaperturaUsuario($idTicket, $emailRecibe, $nombreRecibe)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Mensaje y asunto
        $mensaje = "Se reaperturó el Ticket #$idTicket.<br/>";
        $mail->Subject = 'Ticket Reaperturado - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $mensaje
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje";
        $mail->AltBody = $altBody;

        $mail->send();
        return $emailRecibe;
    } catch (Exception $e) {
        http_response_code(500);
    }
}

function enviarEmailTEscaladoUsuario($idTicket, $emailRecibe, $nombreRecibe)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Mensaje y asunto
        $mensaje = "Se escaló el Ticket #$idTicket.<br/>";
        $mail->Subject = 'Ticket Escalado - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $mensaje
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje";
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
    }
}

function enviarEmailTReaperturadoAgente($idTicket, $emailRecibe, $nombreRecibe, $nombrequienAsignaTicket, $asunto)
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        // Mensaje y asunto
        $mensaje = "El Ticket #$idTicket ha sido reaperturado por $nombrequienAsignaTicket.<br/>";
        $mail->Subject = 'Ticket Reaperturado - HELPDESK IMBAUTO';
        $body = <<<EOT
          Hola $nombreRecibe,<br/>
          $mensaje
          <strong>Reaperturado por:</strong> $nombrequienAsignaTicket<br/>
          <strong>Título:</strong> $asunto<br/>
  EOT;
        $mail->Body = $body;

        // Cuerpo alternativo para clientes que no soportan HTML
        $altBody = "Hola $nombreRecibe,\n$mensaje\nDe: $nombrequienAsignaTicket\nTítulo: $asunto";
        $mail->AltBody = $altBody;

        $mail->send();
    } catch (Exception $e) {
        http_response_code(500);
    }
}

function enviarEmailEncuestaRespondida($idTicket, $destinatarios, $asuntoTicket, $puntuacion, $comentarios)
{
    $mail = new PHPMailer(true);
    try {
        $principal = array_shift($destinatarios);
        configurarMail($mail, $principal[0], $principal[1]);

        foreach ($destinatarios as [$email, $nombre]) {
            if (!empty($email)) {
                $mail->addCC($email, $nombre);
            }
        }

        $mail->Subject = 'Encuesta respondida - HELPDESK IMBAUTO';

        $mensaje = <<<EOT
            Hola {$principal[1]},<br/>
            El ticket <strong>#$idTicket</strong> titulado "<strong>$asuntoTicket</strong>" ha sido evaluado.<br/><br/>
            <strong>Puntuación:</strong> $puntuacion / 10<br/>
            <strong>Comentarios:</strong> $comentarios<br/><br/>
            Gracias por ayudarnos a mejorar nuestro servicio.
        EOT;

        $mail->Body = $mensaje;
        $mail->AltBody = strip_tags($mensaje);

        $mail->send();
        return "ok"; // para confirmar desde el controlador
    } catch (Exception $e) {
        return "Error al enviar correo: " . $mail->ErrorInfo;
    }
}

// function baseUrlPublic(): string {
//     $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
//     $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
//     return "{$scheme}://{$host}";
// }
function frontendBaseUrl(): string
{
    // Puedes mover esto a un .env o variable de entorno
    $env = getenv('FRONTEND_BASE_URL');
    return $env ?: 'http://localhost:4200';
}


/**
 * Envía el correo de encuesta. El link se construye aquí.
 *
 * $opts:
 *  - idProgEncuesta (int)
 *  - linkEncuesta   (string) si llega, tiene prioridad
 */

// function enviarEmailEncuestaProgramada($emailRecibe, $nombreRecibe, $idAtencion, $fechaAtencion, $linkEncuesta = '#') {
function enviarEmailEncuestaProgramada($emailRecibe, $nombreRecibe, $idAtencion, $fechaAtencion, array $opts = [])
{
    $mail = new PHPMailer(true);
    try {

        // configurarMail($mail, $emailRecibe, $nombreRecibe);
        // Si quieres CC/BCC por envío, pásalos en $opts['cc'] / $opts['bcc'] (arrays de emails)
        $cc = isset($opts['cc']) && is_array($opts['cc']) ? $opts['cc'] : null;
        $bcc = isset($opts['bcc']) && is_array($opts['bcc']) ? $opts['bcc'] : null;

        // Depuración a error_log
        // $mail->SMTPDebug  = 2;
        // $mail->Debugoutput = function($str) { error_log('PHPMailer: ' . $str); };

        error_log("CONSENT_HTML start for '{$emailRecibe}'");
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function ($str) {
            error_log('PHPMailer CONSENT: ' . $str); };

        configurarMail($mail, $emailRecibe, $nombreRecibe, $cc, $bcc);

        $mail->Subject = 'IMBAUTO – Encuesta de satisfacción';
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        // ========================
        // Construcción del enlace
        // ========================
        // $linkEncuesta = trim((string)($opts['linkEncuesta'] ?? ''));
        // if ($linkEncuesta === '') {
        //     $idProg = $opts['idProgEncuesta'] ?? null;
        //     if ($idProg) {
        //         $linkEncuesta = baseUrlFrontend() . "/encuesta-cliente/enc/{$idProg}";
        //     } else {
        //         $linkEncuesta = '#';
        //     }
        // }


        // 1) Si viene linkEncuesta, úsalo; si no, construye token seguro
        $linkEncuesta = trim((string) ($opts['linkEncuesta'] ?? ''));
        if ($linkEncuesta === '') {
            $idProg = $opts['idProgEncuesta'] ?? null;
            if ($idProg) {
                // token con 7 días de vigencia (cambia si quieres)
                $token = make_survey_token((int) $idProg, $emailRecibe, 7 * 24 * 3600);
                $linkEncuesta = build_survey_link($token);
            } else {
                $linkEncuesta = '#';
            }
        }

        // Log para verificar
        error_log('LINK_ENCUESTA_RESUELTO=' . $linkEncuesta);

        // Sanitización y fallbacks
        $nombreRecibe = $nombreRecibe ?: $emailRecibe;
        $nombreEsc = htmlspecialchars($nombreRecibe, ENT_QUOTES, 'UTF-8');
        $idEsc = htmlspecialchars((string) $idAtencion, ENT_QUOTES, 'UTF-8');
        $fechaEsc = htmlspecialchars((string) $fechaAtencion, ENT_QUOTES, 'UTF-8');
        $linkEsc = htmlspecialchars($linkEncuesta, ENT_QUOTES, 'UTF-8');

        // =========================================================
        // Embebido CID del logo (con tamaño controlado y fallback)
        // =========================================================
        $logoPathCandidates = [
            __DIR__ . '/../public/img/imbauto-logo.png',   // controllers/ -> public/img
            __DIR__ . '/../../public/img/imbauto-logo.png',
            __DIR__ . '/public/img/imbauto-logo.png',
        ];
        $logoPath = null;
        foreach ($logoPathCandidates as $cand) {
            if (is_file($cand) && is_readable($cand)) {
                $logoPath = realpath($cand);
                break;
            }
        }

        // Fallback URL pública si no se encuentra archivo local
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $publicBase = preg_replace('#/controllers(?:/.*)?$#', '/public', $base);
        $logoUrlFallback = "{$scheme}://{$host}{$publicBase}/img/imbauto-logo.png";
        // URL fija de Política de Privacidad (SharePoint)
        $privacyUrl = 'https://oilgroupec.sharepoint.com/:b:/s/CORPORACIONWAYLOPDP/EV5h0vApBGdNnbIzzn_lo-oB-P_GYNoNEA9r18-Rvcpmpg';
        $privacyUrlEsc = htmlspecialchars($privacyUrl, ENT_QUOTES, 'UTF-8');


        // Tamaño deseado
        $targetW = 260; // ajusta 260–300 px a gusto
        $targetH = 48;  // fallback si no se detectan dimensiones

        if ($logoPath && function_exists('getimagesize')) {
            $info = @getimagesize($logoPath);
            if ($info && !empty($info[0]) && !empty($info[1]) && $info[0] > 0) {
                $targetH = (int) round(($info[1] / $info[0]) * $targetW);
            }
        }

        // Detectar MIME
        $mime = 'image/png';
        if ($logoPath && function_exists('finfo_open')) {
            if ($finfo = finfo_open(FILEINFO_MIME_TYPE)) {
                $detected = finfo_file($finfo, $logoPath);
                if ($detected)
                    $mime = $detected;
                finfo_close($finfo);
            }
        }

        // Construcción del <img>
        $logoImgTag = '';
        if ($logoPath) {
            $cid = 'logoimbauto';
            $ok = $mail->addEmbeddedImage($logoPath, $cid, basename($logoPath), 'base64', $mime);
            if ($ok) {
                $logoImgTag = '<img src="cid:' . $cid . '" alt="Imbauto" width="' . $targetW . '" height="' . $targetH . '" style="display:block;border:0;outline:none;text-decoration:none;">';
            } else {
                $logoImgTag = '<img src="' . $logoUrlFallback . '" alt="Imbauto" width="' . $targetW . '" height="' . $targetH . '" style="display:block;border:0;outline:none;text-decoration:none;">';
            }
        } else {
            $logoImgTag = '<img src="' . $logoUrlFallback . '" alt="Imbauto" width="' . $targetW . '" height="' . $targetH . '" style="display:block;border:0;outline:none;text-decoration:none;">';
        }

        // =======================
        // Cuerpo HTML del mensaje
        // =======================
        $mensaje = <<<HTML
                <!doctype html>
                <html lang="es">
                <body style="margin:0;padding:0;background:#f6f7f9;">
                    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f6f7f9;">
                    <tr>
                        <td align="center" style="padding:24px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#111;">
                            <tr>
                            <!-- <td style="padding:20px 24px;background:#0a3a5a;">{$logoImgTag}</td> -->
                            <td style="padding:20px 24px;background:#EEF6FA;">{$logoImgTag}</td>

                            </tr>
                            <tr>
                            <td style="padding:28px 24px 8px 24px;">
                                <p style="margin:0 0 12px 0;font-size:16px;">Estimado {$nombreEsc},</p>
                                <p style="margin:0 0 16px 0;line-height:1.5;font-size:14px;">
                                Recientemente usted realizó la compra de un bien o un servicio en <strong>Imbauto</strong> el día <strong>{$fechaEsc}</strong> (Atención N.º <strong>{$idEsc}</strong>).
                                </p>
                                <p style="margin:0 0 16px 0;line-height:1.5;font-size:14px;">
                                Nos gustaría conocer su opinión. Le tomará aproximadamente <strong>5 minutos</strong> completar nuestra encuesta de satisfacción.
                                </p>
                            </td>
                            </tr>
                            <tr>
                            <td style="padding:0 24px 8px 24px;">
                                <p style="margin:0 0 8px 0;font-weight:bold;font-size:14px;">
                                ¿Qué tan probable es que recomiende a Imbauto a sus familiares o amigos?
                                </p>
                                <p style="margin:0 0 20px 0;font-size:12px;color:#555;">(0 = Nada probable · 10 = Muy probable)</p>
                                <div style="text-align:center;margin:0 0 24px 0;">
                                <a href="{$linkEncuesta}" style="display:inline-block;padding:12px 20px;text-decoration:none;background:#0a7cc1;color:#fff;border-radius:6px;font-weight:bold;">
                                    Completar encuesta
                                </a>
                                </div>
                            </td>
                            </tr>
                            <tr>
                            <td style="padding:0 24px 16px 24px;">
                                <p style="margin:0 0 12px 0;line-height:1.5;font-size:14px;">
                                En Imbauto, nuestro compromiso va más allá de la venta: buscamos relaciones de confianza y servicio de calidad. 
                                Sus comentarios nos ayudan a mejorar continuamente.
                                </p>
                                <p style="margin:0;line-height:1.5;font-size:14px;">
                                Gracias por elegir <strong>Imbauto</strong>.
                                </p>
                            </td>
                            </tr>
                            <tr>
                            <td style="padding:12px 24px 24px 24px;">
                                <p style="margin:0;font-size:13px;color:#333;">
                                Atentamente,<br>
                                <strong>Equipo de Experiencia del Cliente – Imbauto S.A.</strong>
                                </p>
                            </td>
                            </tr>
                            <tr>
                            <td style="padding:14px 24px;background:#f0f3f7;font-size:12px;color:#667085;">
                                <!-- <a href="{$scheme}://{$host}/politica-privacidad" style="color:#667085;text-decoration:none;">Política de privacidad</a> &nbsp;|&nbsp; -->
                                <a href="{$privacyUrlEsc}" style="color:#667085;text-decoration:none;">Política de privacidad</a> &nbsp;|&nbsp;
                                <a href="{$scheme}://{$host}/contacto" style="color:#667085;text-decoration:none;">Contáctanos</a> &nbsp;|&nbsp;
                                <a href="{$linkEncuesta}&unsub=1" style="color:#667085;text-decoration:none;">Darse de baja</a><br>
                                ©2025 Imbauto S.A. Todos los derechos reservados.
                            </td>
                            </tr>
                        </table>
                        </td>
                    </tr>
                    </table>
                </body>
                </html>
                HTML;

        // Texto alternativo (solo texto)
        $alt = "Estimado {$nombreRecibe},\n\n"
            . "Recientemente usted realizó un servicio en Imbauto el día {$fechaEsc} (Atención N.º {$idEsc}).\n"
            . "Nos gustaría conocer su opinión. Le tomará ~5 minutos completar nuestra encuesta:\n"
            . "{$linkEncuesta}\n\n"
            . "¿Qué tan probable es que recomiende a Imbauto a sus familiares o amigos? (0 = Nada probable · 10 = Muy probable)\n\n"
            . "Gracias por elegir Imbauto.\n"
            . "Equipo de Experiencia del Cliente – Imbauto S.A.\n";

        // $mail->Body    = $mensaje;
        // $mail->AltBody = $alt;
        $mail->Body = str_replace(['{$linkEsc}', '{$linkEncuesta}'], [$linkEncuesta, $linkEncuesta], $mail->Body);
        $mail->AltBody = str_replace(['{$linkEsc}', '{$linkEncuesta}'], [$linkEncuesta, $linkEncuesta], $mail->AltBody);

        // error_log('BODY_LEN=' . strlen($mensaje));
        // error_log('ALT_LEN='  . strlen($alt));

        // Parche defensivo por si algo quedó vacío:
        if (!isset($mail->Body) || trim($mail->Body) === '') {
            // fallback mínimo (no ideal, pero evita el error)
            $mail->Body = '<p>&nbsp;</p>';
        }
        if (!isset($mail->AltBody) || trim($mail->AltBody) === '') {
            $mail->AltBody = ' ';
        }

        // error_log('LINK_ENCUESTA_RESUELTO=' . $linkEncuesta);

        //     $mail->send();
        //     return "ok";
        // } catch (Exception $e) {
        //     return "Error al enviar correo: " . ($mail->ErrorInfo ?: $e->getMessage());
        // }
        // error_log('BODY_LEN=' . strlen($mensaje));
        // error_log('ALT_LEN='  . strlen($alt));

        // Después de armar $mensaje y $alt, y ANTES de $mail->send():
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        // Usa el parser de PHPMailer para HTML (convierte y arma bien multipart/related):
        $mail->msgHTML($mensaje);     // <-- en vez de $mail->Body = $mensaje;

        // Fuerza el texto alternativo que tú ya generas
        $mail->AltBody = $alt;

        error_log('PH_BODY_LEN=' . strlen($mail->Body) . ' ALT=' . strlen($mail->AltBody));


        if (!$mail->send()) {
            error_log('PHPMailer SEND FAILED: ' . $mail->ErrorInfo);
            return 'Error al enviar correo: ' . $mail->ErrorInfo;
        }
        return 'ok';
    } catch (Exception $e) {
        error_log('PHPMailer EXCEPTION: ' . ($mail->ErrorInfo ?: $e->getMessage()));
        return 'Error al enviar correo: ' . ($mail->ErrorInfo ?: $e->getMessage());
    }
}


function enviarEmailPQRSConfirmacion($emailRecibe, $nombreRecibe, $tipo, $numero, $estado, $detalle = '')
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);

        $mail->Subject = 'IMBAUTO - Confirmación de ' . ($tipo ?: 'PQRS');
        $nombreRecibe = $nombreRecibe ?: $emailRecibe;

        $detalleHtml = $detalle ? '<strong>Detalle:</strong> ' . nl2br(htmlentities($detalle)) . '<br/>' : '';

        $mensaje = <<<EOT
        Estimado/a {$nombreRecibe},<br/><br/>
        Hemos registrado tu {$tipo} con el N° <strong>{$numero}</strong>.<br/>
        <strong>Estado:</strong> {$estado}<br/>
        {$detalleHtml}
        Gracias,<br/>
        IMBAUTO
        EOT;

        $mail->Body = $mensaje;
        $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $mensaje));
        $mail->send();
        return "ok";
    } catch (Exception $e) {
        return "Error al enviar correo: " . ($mail->ErrorInfo ?: $e->getMessage());
    }

}

function enviarEmailConsentimientoAceptado(string $emailRecibe, string $nombreRecibe, string $firmaToken, string $fechaISO = '')
{
    $mail = new PHPMailer(true);
    try {
        configurarMail($mail, $emailRecibe, $nombreRecibe);
        $mail->Subject = 'Confirmación de consentimiento de datos – Imbauto';
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        $fechaEsc = htmlspecialchars($fechaISO ?: date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8');
        $nombreEsc = htmlspecialchars($nombreRecibe ?: $emailRecibe, ENT_QUOTES, 'UTF-8');
        $firmaEsc = htmlspecialchars($firmaToken, ENT_QUOTES, 'UTF-8');

        $mail->Body = <<<HTML
        <p>Hola {$nombreEsc},</p>
        <p>Hemos registrado tu consentimiento para el tratamiento de datos personales el <strong>{$fechaEsc}</strong>.</p>
        <p><strong>Firma electrónica (token):</strong><br>
        <code style="word-break:break-all;display:inline-block;padding:8px 10px;background:#f6f7f9;border-radius:6px;">{$firmaEsc}</code></p>
        <p>Conserva este mensaje para tu respaldo. Gracias por confiar en <strong>Imbauto</strong>.</p>
        HTML;

        $mail->AltBody = "Hola {$nombreRecibe},\n" .
            "Consentimiento registrado el {$fechaEsc}.\n" .
            "Firma electrónica (token): {$firmaToken}\n";

        $mail->send();
        return "ok";
    } catch (Exception $e) {
        return "Error al enviar correo: " . ($mail->ErrorInfo ?: $e->getMessage());
    }
}

function enviarEmailConsentimientoHTML(string $emailRecibe, string $nombreRecibe, array $opts = [])
{
    error_log("CONSENT_HTML start for '{$emailRecibe}'");

    $mail = new PHPMailer(true);
    try {
        // Validación temprana
        if (empty($emailRecibe) || !filter_var($emailRecibe, FILTER_VALIDATE_EMAIL)) {
            error_log("CONSENT_HTML: email inválido='{$emailRecibe}'");
            return 'skip: invalid email';
        }

        // Debug SMTP temporal (quítalo luego)
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function ($str) {
            error_log('PHPMailer CONSENT: ' . $str); };

        // CC/BCC opcionales
        $cc = isset($opts['cc']) && is_array($opts['cc']) ? $opts['cc'] : null;
        $bcc = isset($opts['bcc']) && is_array($opts['bcc']) ? $opts['bcc'] : null;

        // Configuración SMTP (misma que usas para los otros correos que sí llegan)
        configurarMail($mail, $emailRecibe, $nombreRecibe, $cc, $bcc);

        $firmaToken = (string) ($opts['firmaToken'] ?? '');
        $userAgentDesc = (string) ($opts['userAgentDesc'] ?? 'ENCUESTA');
        $idAtencion = (int) ($opts['idAtencion'] ?? 0);
        $fechaAtencion = (string) ($opts['fechaAtencion'] ?? '');
        $detalleAtencion = (string) ($opts['detalleAtencion'] ?? '');

        $mail->Subject = 'Confirmación de consentimiento de datos – Imbauto';
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        // Sanitiza
        $nombreEsc = htmlspecialchars($nombreRecibe ?: $emailRecibe, ENT_QUOTES, 'UTF-8');
        $firmaEsc = htmlspecialchars($firmaToken, ENT_QUOTES, 'UTF-8');
        $uaEsc = htmlspecialchars($userAgentDesc, ENT_QUOTES, 'UTF-8');
        $idEsc = htmlspecialchars((string) $idAtencion, ENT_QUOTES, 'UTF-8');
        $fecEsc = htmlspecialchars($fechaAtencion ?: date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8');
        $detEsc = nl2br(htmlspecialchars($detalleAtencion, ENT_QUOTES, 'UTF-8'));

        // Logo (mismo approach que tu otro mail)
        $logoPathCandidates = [
            __DIR__ . '/../public/img/imbauto-logo.png',
            __DIR__ . '/../../public/img/imbauto-logo.png',
            __DIR__ . '/public/img/imbauto-logo.png',
        ];
        $logoPath = null;
        foreach ($logoPathCandidates as $cand) {
            if (is_file($cand) && is_readable($cand)) {
                $logoPath = realpath($cand);
                break;
            }
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $publicBase = preg_replace('#/controllers(?:/.*)?$#', '/public', $base);
        $logoUrlFallback = "{$scheme}://{$host}{$publicBase}/img/imbauto-logo.png";

        $targetW = 260;
        $targetH = 48;
        $mime = 'image/png';
        if ($logoPath && function_exists('getimagesize')) {
            $info = @getimagesize($logoPath);
            if ($info && !empty($info[0]) && !empty($info[1]) && $info[0] > 0) {
                $targetH = (int) round(($info[1] / $info[0]) * $targetW);
            }
        }
        if ($logoPath && function_exists('finfo_open')) {
            if ($finfo = finfo_open(FILEINFO_MIME_TYPE)) {
                $detected = finfo_file($finfo, $logoPath);
                if ($detected)
                    $mime = $detected;
                finfo_close($finfo);
            }
        }
        $logoImgTag = '';
        if ($logoPath) {
            $cid = 'logoimbauto';
            $ok = $mail->addEmbeddedImage($logoPath, $cid, basename($logoPath), 'base64', $mime);
            $logoImgTag = $ok
                ? '<img src="cid:' . $cid . '" alt="Imbauto" width="' . $targetW . '" height="' . $targetH . '" style="display:block;border:0;outline:none;text-decoration:none;">'
                : '<img src="' . $logoUrlFallback . '" alt="Imbauto" width="' . $targetW . '" height="' . $targetH . '" style="display:block;border:0;outline:none;text-decoration:none;">';
        } else {
            $logoImgTag = '<img src="' . $logoUrlFallback . '" alt="Imbauto" width="' . $targetW . '" height="' . $targetH . '" style="display:block;border:0;outline:none;text-decoration:none;">';
        }

        $mensaje = <<<HTML
        <!doctype html>
        <html lang="es">
        <body style="margin:0;padding:0;background:#f6f7f9;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f6f7f9;">
            <tr>
                <td align="center" style="padding:24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#111;">
                    <tr>
                        <td style="padding:20px 24px;background:#EEF6FA;">{$logoImgTag}</td>
                    </tr>
                    <tr>
                        <td style="padding:28px 24px 8px 24px;">
                            <p style="margin:0 0 12px 0;font-size:16px;">Estimado {$nombreEsc},</p>
                            <p style="margin:0 0 16px 0;line-height:1.5;font-size:14px;">
                                Hemos registrado su consentimiento para el tratamiento de datos personales el <strong>{$fecEsc}</strong>.
                            </p>
                            <p style="margin:0 0 12px 0;line-height:1.5;font-size:14px;">
                                <strong>Firma electrónica (token):</strong><br>
                                <code style="word-break:break-all;display:inline-block;padding:8px 10px;background:#f6f7f9;border-radius:6px;">{$firmaEsc}</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 16px 24px;">
                            <p style="margin:0 0 8px 0;font-weight:bold;font-size:14px;">Referencia de encuesta</p>
                            <p style="margin:0 0 4px 0;font-size:14px;">{$uaEsc}</p>
                            <p style="margin:0 0 4px 0;font-size:14px;">Atención N.º <strong>{$idEsc}</strong> — Fecha atención: <strong>{$fecEsc}</strong></p>
                            <p style="margin:0 0 8px 0;font-weight:bold;font-size:14px;">Detalle de atención</p>
                            <div style="font-size:13px;line-height:1.5;color:#333;">{$detEsc}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 24px 24px 24px;">
                            <p style="margin:0;font-size:13px;color:#333;">
                                Atentamente,<br>
                                <strong>Equipo de Experiencia del Cliente – Imbauto S.A.</strong>
                            </p>
                        </td>
                    </tr>
                </table>
                </td>
            </tr>
            </table>
        </body>
        </html>
        HTML;

        $alt = "Hola {$nombreRecibe},\n" .
            "Consentimiento registrado el {$fechaAtencion}.\n" .
            "Firma electrónica (token): {$firmaToken}\n" .
            "Referencia: {$userAgentDesc}\n" .
            "Atención #{$idAtencion} - Fecha: {$fechaAtencion}\n" .
            "Detalle:\n{$detalleAtencion}\n";

        // Preparación y envío
        $mail->msgHTML($mensaje);
        $mail->AltBody = $alt;

        error_log("CONSENT_HTML about to send to {$emailRecibe}");
        if (!$mail->send()) {
            error_log('CONSENT_HTML send failed: ' . $mail->ErrorInfo);
            return 'error: ' . $mail->ErrorInfo;
        }
        error_log('CONSENT_HTML send OK to ' . $emailRecibe);
        return 'ok';

    } catch (Exception $e) {
        error_log('CONSENT_HTML exception: ' . ($mail->ErrorInfo ?: $e->getMessage()));
        return 'error: ' . ($mail->ErrorInfo ?: $e->getMessage());
    }
}


