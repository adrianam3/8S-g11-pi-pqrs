<?php
//TODO: Clase de AreaUsuario
require_once('../config/config.php');

class AreaUsuario
{
    //TODO: Implementar los métodos de la clase

    public function todos() // Select * from areaUsuario
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT * FROM `areaUsuario`";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        return $datos;
    }

    public function uno($idAreaU) // Select * from areaUsuario where id = $idAreaU
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT * FROM `areaUsuario` WHERE `idAreaU`=$idAreaU";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        return $datos;
    }

    public function insertar($nombre, $descripcion, $estado) // Insert into areaUsuario (...)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $cadena = "INSERT INTO `areaUsuario` (`nombre`, `descripcion`, `estado`) VALUES ('$nombre','$descripcion','$estado')";
            if (mysqli_query($con, $cadena)) {
                return $con->insert_id;
            } else {
                return $con->error;
            }
        } catch (Exception $th) {
            http_response_code(500);
            return $th->getMessage();
        } finally {
            $con->close();
        }
    }

    public function actualizar($idAreaU, $nombre, $descripcion, $estado) // Update areaUsuario set ... where id = $idAreaU
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $cadena = "UPDATE `areaUsuario` SET `nombre`='$nombre', `descripcion`='$descripcion', `estado`='$estado', `fechaModificacion`=CURRENT_TIMESTAMP WHERE `idAreaU`=$idAreaU";
            if (mysqli_query($con, $cadena)) {
                return $idAreaU;
            } else {
                return $con->error;
            }
        } catch (Exception $th) {
            http_response_code(500);
            return $th->getMessage();
        } finally {
            $con->close();
        }
    }

    public function eliminar($idAreaU) // Delete from areaUsuario where id = $idAreaU
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $cadena = "DELETE FROM `areaUsuario` WHERE `idAreaU`= $idAreaU";
            if (mysqli_query($con, $cadena)) {
                return 1;
            } else {
                return $con->error;
            }
        } catch (Exception $th) {
            http_response_code(500);
            return $th->getMessage();
        } finally {
            $con->close();
        }
    }
}
