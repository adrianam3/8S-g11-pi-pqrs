<?php
require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function configurarMail(PHPMailer $mail, $emailRecibe, $nombreRecibe)
{
    // Configuración del servidor SMTP
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'sistemas.imbauto@gmail.com';
    $mail->Password = 'jyru gtfv zgsp kfxp';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Emisor y receptor
    $mail->setFrom('sistemas.imbauto@gmail.com', 'HELPDESK IMBAUTO');
    $mail->addAddress($emailRecibe, $nombreRecibe);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8'; // Asegúrate de que la codificación es UTF-8
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
        $resetLink = "http://localhost:4200/olvido-contrasena?token=$token&usuario=".base64_encode($email);
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

// -- am enviar mail

// function enviarEmailEncuestaRespondida($idTicket, $emailRecibe, $nombreRecibe, $asuntoTicket, $puntuacion, $comentarios)
// {
//     $mail = new PHPMailer(true);
//     try {
//         configurarMail($mail, $emailRecibe, $nombreRecibe);

//         // Mensaje y asunto
//         $mail->Subject = 'Encuesta respondida - HELPDESK IMBAUTO';

//         $mensaje = <<<EOT
//             Hola $nombreRecibe,<br/>
//             El ticket <strong>#$idTicket</strong> titulado "<strong>$asuntoTicket</strong>" ha sido evaluado.<br/><br/>
//             <strong>Puntuación:</strong> $puntuacion / 10<br/>
//             <strong>Comentarios:</strong> $comentarios<br/><br/>
//             Gracias por ayudarnos a mejorar nuestro servicio.
//         EOT;

//         $mail->Body = $mensaje;

//         // Cuerpo alternativo para clientes que no soportan HTML
//         $altBody = <<<EOT
// Hola $nombreRecibe,
// El ticket #$idTicket titulado "$asuntoTicket" ha sido evaluado.

// Puntuación: $puntuacion / 10
// Comentarios: $comentarios

// Gracias por ayudarnos a mejorar nuestro servicio.
// EOT;

//         $mail->AltBody = $altBody;

//         $mail->send();
//     } catch (Exception $e) {
//         http_response_code(500);
//     }
// }

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

