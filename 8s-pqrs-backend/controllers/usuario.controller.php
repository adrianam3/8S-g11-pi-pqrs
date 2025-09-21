<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"];
if ($method == "OPTIONS") {
    die();
}
//TODO: Controlador de usuario
require_once('revisarsesion.controller.php');
require_once('../models/usuario.model.php');
require_once('email.controller.php');
require_once('../models/persona.model.php');
// error_reporting(0); //DESHABILITAR ERROR, DEJAR COMENTADO si se desea que se muestre el error
$usuario = new Usuario;
$persona = new Persona;
switch ($_GET["op"]) {
    //TODO: Operaciones de usuario

    case 'todos': // Procedimiento para cargar todos los datos de usuario con datos de Persona
        $todos = $usuario->todos();
        // error_log('Usuarios encontrados: ' . count($todos));
        header('Content-Type: application/json');
        echo json_encode($todos);
        break;

    case 'uno': //TODO: Procedimiento para obtener un registro de la base de datos
        $idUsuario = $_POST["idUsuario"];
        $datos = array();
        $datos = $usuario->uno($idUsuario);
        $res = mysqli_fetch_assoc($datos);
        echo json_encode($res);
        break;

    case 'insertar': //TODO: Procedimiento para insertar un registro en la base de datos
        $usuarioName = $_POST["usuario"];
        $password = $_POST["password"];
        $descripcion = $_POST["descripcion"];
        $idPersona = $_POST["idPersona"];
        $idAreaU = $_POST["idAreaU"];
        $idRol = $_POST["idRol"];
        $idCargoU = $_POST["idCargoU"];
        $estado = $_POST["estado"];
        $datos = array();
        $resultadoPersona = $persona->uno($idPersona);
        $fila = $resultadoPersona->fetch_assoc();
        enviarEmailCrearCuenta(
            emailRecibe: $fila['email'],
            nombreRecibe: $fila['personaNombreCompleto'],
            password: $password
        );
        $datos = $usuario->insertar($usuarioName, $password, $descripcion, $idPersona, $idAreaU, $idRol, $idCargoU, $estado);
        echo json_encode($datos);
        break;

    case 'actualizar': //TODO: Procedimiento para actualizar un registro en la base de datos
        $idUsuario = $_POST["idUsuario"];
        $usuarioName = $_POST["usuario"];
        $descripcion = $_POST["descripcion"];
        $idPersona = $_POST["idPersona"];
        $idAreaU = $_POST["idAreaU"];
        $idRol = $_POST["idRol"];
        $idCargoU = $_POST["idCargoU"];
        $estado = $_POST["estado"];
        $datos = array();
        $datos = $usuario->actualizar($idUsuario, $usuarioName, $descripcion, $idPersona, $idAreaU, $idRol, $idCargoU, $estado);
        echo json_encode($datos);
        break;

    case 'eliminar': //TODO: Procedimiento para eliminar un registro en la base de datos
        $idUsuario = $_POST["idUsuario"];
        $datos = array();
        $datos = $usuario->eliminar($idUsuario);
        echo json_encode($datos);
        break;

    case 'cambiar_password':
    if (!isset($_POST["email"]) || !isset($_POST["actual"]) || !isset($_POST["nueva"])) {
        http_response_code(400);
        echo json_encode(["error" => "Faltan datos obligatorios"]);
        break;
    }

    $email = $_POST["email"];
    $actual = $_POST["actual"];
    $nueva = $_POST["nueva"];

    // Buscar usuario por email
    $usuarioData = $usuario->login($email, $actual);

    if (!$usuarioData) {
        http_response_code(401);
        echo json_encode(["error" => "La contraseña actual es incorrecta"]);
        break;
    }

    $resultado = $usuario->actualizarcontrasena($nueva, $email);
    
    if ($resultado) {
        echo json_encode(["message" => "Contraseña actualizada correctamente"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al actualizar la contraseña"]);
    }
    break;

    case 'cambiar_password':
        if (!isset($_POST["email"]) || !isset($_POST["actual"]) || !isset($_POST["nueva"])) {
            http_response_code(400);
            echo json_encode(["error" => "Faltan datos obligatorios"]);
            break;
        }
    
        $email = $_POST["email"];
        $actual = $_POST["actual"];
        $nueva = $_POST["nueva"];
    
        // Buscar usuario por email
        $usuarioData = $usuario->login($email, $actual);
    
        if (!$usuarioData) {
            http_response_code(401);
            echo json_encode(["error" => "La contraseña actual es incorrecta"]);
            break;
        }
    
        $resultado = $usuario->actualizarcontrasena($nueva, $email);
        
        if ($resultado) {
            echo json_encode(["message" => "Contraseña actualizada correctamente"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Error al actualizar la contraseña"]);
        }
        break;
    
}
