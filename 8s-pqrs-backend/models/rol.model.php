<?php
//TODO: Clase de Rol
require_once('../config/config.php');

class Rol
{
    //TODO: Implementar los métodos de la clase

    public function todos() // Select * from rol
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT * FROM `rol`";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        return $datos;
    }

    public function uno($idRol) // Select * from rol where id = $idRol
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT * FROM `rol` WHERE `idRol`=$idRol";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        return $datos;
    }

    public function insertar($nombreRol, $descripcion, $accesos, $estado) // Insert into rol (...)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $cadena = "INSERT INTO `rol` (`nombreRol`, `descripcion`, `accesos`, `estado`) VALUES ('$nombreRol','$descripcion','$accesos','$estado')";
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

    public function actualizar($idRol, $nombreRol, $descripcion, $accesos, $estado) // Update rol set ... where id = $idRol
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $cadena = "UPDATE `rol` SET `nombreRol`='$nombreRol', `descripcion`='$descripcion', `accesos`='$accesos', `estado`='$estado', `fechaModificacion`=CURRENT_TIMESTAMP WHERE `idRol`=$idRol";
            if (mysqli_query($con, $cadena)) {
                return $idRol;
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

    public function eliminar($idRol) // Delete from rol where id = $idRol
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            // Verificar si existen relaciones con la persona
            $query = "SELECT COUNT(*) as total FROM usuario WHERE idRol = $idRol";
            $result = mysqli_query($con, $query);
            $row = mysqli_fetch_assoc($result);
    
            if ($row['total'] > 0) {
                    // devolver un mensaje de error
                    return [
                        'status' => 'error',
                        'message' => 'No se puede eliminar el Rol, ya se ha vinculado con usuarios.'
                    ];
                } else {
                    $cadena = "DELETE FROM `rol` WHERE `idRol`= $idRol";
                    if (mysqli_query($con, $cadena)) {
                        return 1;
                    } else {
                        return [
                            'status' => 'error',
                            'message' => 'Error al intentar eliminar el rol.'
                        ];
                    }
                }
        } catch (Exception $th) {
            return [
                'status' => 'error',
                'message' => $th->getMessage()
            ];
        } finally {
            $con->close();
        }
    }
}
