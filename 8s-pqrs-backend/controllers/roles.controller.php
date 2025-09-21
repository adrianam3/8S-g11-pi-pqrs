<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER["REQUEST_METHOD"];
if ($method == "OPTIONS") {
    die();
}
//TODO: Controlador de rol
require_once('revisarsesion.controller.php');
require_once('../models/rol.model.php');
error_reporting(0); //DESHABILITAR ERROR, DEJAR COMENTADO si se desea que se muestre el error
$rol = new Rol;

switch ($_GET["op"]) {
    //TODO: Operaciones de rol

    case 'todos': //TODO: Procedimiento para cargar todos los datos de rol
        $datos = array();
        $datos = $rol->todos();
        while ($row = mysqli_fetch_assoc($datos)) {
            $todos[] = $row;
        }
        echo json_encode($todos);
        break;

    case 'uno': //TODO: Procedimiento para obtener un registro de la base de datos
        $idRol = $_POST["idRol"];
        $datos = array();
        $datos = $rol->uno($idRol);
        $res = mysqli_fetch_assoc($datos);
        echo json_encode($res);
        break;

    case 'insertar': //TODO: Procedimiento para insertar un registro en la base de datos
        $nombreRol = $_POST["nombreRol"];
        $descripcion = $_POST["descripcion"];
        $accesos = $_POST["accesos"];
        $estado = $_POST["estado"];

        $datos = array();
        $datos = $rol->insertar($nombreRol, $descripcion, $accesos, $estado);
        echo json_encode($datos);
        break;

    case 'actualizar': //TODO: Procedimiento para actualizar un registro en la base de datos
        $idRol = $_POST["idRol"];
        $nombreRol = $_POST["nombreRol"];
        $descripcion = $_POST["descripcion"];
        $accesos = $_POST["accesos"];
        $estado = $_POST["estado"];
        $datos = array();
        $datos = $rol->actualizar($idRol, $nombreRol, $descripcion, $accesos, $estado);
        echo json_encode($datos);
        break;

    case 'eliminar': //TODO: Procedimiento para eliminar un registro en la base de datos
        $idRol = $_POST["idRol"];
        $datos = array();
        $datos = $rol->eliminar($idRol);
        echo json_encode($datos);
        break;
}
