<?php
require_once('../vendor/autoload.php'); // Asegúrate de tener instalado Firebase\JWT
require_once('../config/config.php');
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = SECRET_KEY;

function generarToken($idUsuario, $idRol) {
    global $secret_key;

    $payload = [
        "iss" => "http://localhost",
        "aud" => "http://localhost",
        "iat" => time(),
        "exp" => time() + (10 * 60), // Token válido por 1 hora
        "sub" => $idUsuario,
        "rol" => $idRol
    ];

    return JWT::encode($payload, $secret_key, 'HS256');
}

// function verificarToken() {
//     global $secret_key;

//     $headers = apache_request_headers();
//     if (!isset($headers['Authorization'])) {
//         http_response_code(401);
//         echo json_encode(["message" => "Token no encontrado"]);
//         exit();
//     }

//     $token = str_replace("Bearer ", "", $headers['Authorization']);

//     try {
//         return JWT::decode($token, new Key($secret_key, 'HS256'));
//     } catch (Exception $e) {
//         http_response_code(401);
//         echo json_encode(["message" => "Token inválido", "error" => $e->getMessage()]);
//         exit();
//     }
// }

function verificarToken() {
    global $secret_key;

    $headers = getallheaders();
    file_put_contents("headers_debug.log", print_r($headers, true));
    
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(["message" => "Token no encontrado"]);
        exit();
    }

    $token = str_replace("Bearer ", "", $headers['Authorization']);

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
       error_log("decoded : " . print_r($decoded, true));

        // Verificar si el token ha expirado
        if ($decoded->exp < time()) {
            http_response_code(401);
            echo json_encode(["message" => "Token expirado"]);
            exit();
        }

        return $decoded; // Retornar datos del usuario en el token
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(["message" => "Tokennnnn inválido", "error" => $e->getMessage()]);
        exit();
    }
}

?>
