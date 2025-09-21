<?php
session_start();

// Configurar cabeceras para CORS y JSON
header("Access-Control-Allow-Origin: http://localhost:4200");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Origin, Content-Type, Access-Control-Allow-Headers, Accept, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Incluir modelos y autenticación
require_once '../models/usuario.model.php';
require_once '../controllers/auth.controller.php';

// Leer entrada JSON
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Validar campos
$email = filter_var($data["email"] ?? '', FILTER_SANITIZE_EMAIL);
$password = $data["password"] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["message" => "Datos incompletos."]);
    exit;
}

try {
    $usuarioModelo = new Usuario();
    $user_data = $usuarioModelo->login($email, $password); // Usamos método seguro en el modelo

    if ($user_data) {
        $token = generarToken($user_data['idUsuario'], $user_data['idRol']);
        $_SESSION['token'] = $token;
        $_SESSION['loggedin'] = true;
        $_SESSION['email'] = $email;
        $_SESSION['usuarioId'] = $user_data['idUsuario'];
        $_SESSION['idRol'] = $user_data['idRol'];

        setcookie(session_name(), session_id(), [
            'expires' => time() + 86400,
            'path' => '/',
            'domain' => 'localhost',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        http_response_code(200);
        echo json_encode([
            "message" => "Login exitoso.",
            "user" => $user_data,
            "token" => $token
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["message" => "Login fallido. Usuario o contraseña inválidos."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["message" => "Error en el servidor.", "error" => $e->getMessage()]);
}

// Cierre de sesión
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_unset();
    session_destroy();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    http_response_code(200);
    echo json_encode(["message" => "Logout exitoso."]);
    exit;
}
