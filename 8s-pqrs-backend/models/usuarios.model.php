<?php
//TODO: Clase de Usuario
require_once('../config/config.php');
require_once('../models/personas.model.php');
class Usuario
{
    
    //TODO: Implementar los métodos de la clase

    // Método para verificar las credenciales del usuario
    public function login($email, $password)
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();

        // Usamos sentencia preparada para evitar inyecciones SQL
        $query = "SELECT u.*, p.* 
                FROM usuarios u
                LEFT JOIN personas p ON u.idPersona = p.idPersona
                WHERE p.email = ? LIMIT 1";

        $stmt = mysqli_prepare($con, $query);

        if (!$stmt) {
            error_log("Error preparando la consulta: " . mysqli_error($con));
            return false;
        }

        // Asociar parámetros y ejecutar
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        // Verificar si se encontró usuario
        if ($row = mysqli_fetch_assoc($result)) {
            // Verificar contraseña cifrada
            if (password_verify($password, $row['password'])) {
                mysqli_stmt_close($stmt);
                $con->close();
                unset($row['password']); // Opcional: eliminar el hash por seguridad
                return $row;
            }
        }

        mysqli_stmt_close($stmt);
        $con->close();
        return false; // Fallo de login
    }
    
    // public function todos() // Select * from usuario
    public function todos()
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
    
            $cadena = "SELECT usuarios.*, 
                              personas.nombres AS personaNombres, 
                              personas.apellidos AS personaApellidos, 
                              CONCAT(personas.nombres, ' ', personas.apellidos) AS usuarioNombreCompleto,
                              personas.email AS personaEmail, 
                              roles.nombreRol AS rolNombre,
                              agencias.nombre AS agenciaNombre
                       FROM usuarios
                       LEFT JOIN personas ON usuarios.idPersona = personas.idPersona
                       LEFT JOIN roles ON usuarios.idRol = roles.idRol
                       LEFT JOIN agencias ON usuarios.idAgencia = agencias.idAgencia";
    
            // Preparar la consulta
            $stmt = mysqli_prepare($con, $cadena);
            if (!$stmt) {
                throw new Exception("Error al preparar la consulta: " . mysqli_error($con));
            }
            // Ejecutar la consulta
            mysqli_stmt_execute($stmt);
            // Obtener resultados
            $result = mysqli_stmt_get_result($stmt);
    
            $usuarios = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $usuarios[] = $row;
            }
    
            mysqli_stmt_close($stmt);
            $con->close();
    
            return $usuarios;
    
        } catch (Exception $e) {
            error_log("Error en todos(): " . $e->getMessage());
            http_response_code(500);
            return [];
        }
    }
    

    public function uno($idUsuario)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            $cadena = "SELECT usuarios.*, 
                            personas.nombres AS personaNombres, 
                            personas.apellidos AS personaApellidos, 
                            personas.email AS personaEmail, 
                            roles.nombreRol AS rolNombre,
                            agencias.nombre AS agenciaNombre
                    FROM usuarios
                    LEFT JOIN personas ON usuarios.idPersona = personas.idPersona
                    LEFT JOIN roles ON usuarios.idRol = roles.idRol
                    LEFT JOIN agencias ON usuarios.idAgencia = agencias.idAgencia
                    WHERE usuarios.idUsuario = ?";

            $stmt = mysqli_prepare($con, $cadena);
            if (!$stmt) {
                throw new Exception("Error al preparar consulta: " . mysqli_error($con));
            }

            mysqli_stmt_bind_param($stmt, 'i', $idUsuario);
            mysqli_stmt_execute($stmt);
            // Obtener resultados
            $result = mysqli_stmt_get_result($stmt);
            $usuario = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            $con->close();

            return $usuario ?: null;

        } catch (Exception $e) {
            error_log("Error en uno(): " . $e->getMessage());
            http_response_code(500);
            return null;
        }
    }

    public function insertar($usuario, $password, $descripcion, $idPersona, $idAgencia, $idRol, $estado)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            $passwordHash = password_hash($password, PASSWORD_BCRYPT); // Encriptar contraseña

            $sql = "INSERT INTO usuarios 
                    (usuario, password, descripcion, idPersona, idAgencia, idRol, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = mysqli_prepare($con, $sql);
            if (!$stmt) {
                throw new Exception("Error preparando consulta: " . mysqli_error($con));
            }

            mysqli_stmt_bind_param($stmt, "sssiiii", $usuario, $passwordHash, $descripcion, $idPersona, $idRol, $estado);
            mysqli_stmt_execute($stmt);

            $idInsertado = mysqli_stmt_insert_id($stmt);
            mysqli_stmt_close($stmt);
            $con->close();

            return $idInsertado;

        } catch (Exception $e) {
            error_log("Error en insertar usuario: " . $e->getMessage());
            http_response_code(500);
            return false;
        }
    }

    public function actualizar($idUsuario, $usuario, $descripcion, $idPersona, $idAgencia, $idRol, $estado)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            $sql = "UPDATE usuarios SET 
                        usuario = ?, 
                        descripcion = ?, 
                        idPersona = ?, 
                        idAgencia = ?, 
                        idRol = ?, 
                        estado = ?, 
                        fechaModificacion = CURRENT_TIMESTAMP 
                    WHERE idUsuario = ?";

            $stmt = mysqli_prepare($con, $sql);
            if (!$stmt) {
                throw new Exception("Error preparando consulta: " . mysqli_error($con));
            }

            mysqli_stmt_bind_param($stmt, "ssiiiii", $usuario, $descripcion, $idPersona, $idAgencia, $idRol, $estado, $idUsuario);
            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);
            $con->close();

            return $idUsuario;

        } catch (Exception $e) {
            error_log("Error en actualizar usuario: " . $e->getMessage());
            http_response_code(500);
            return false;
        }
    }


    public function actualizarcontrasena($password, $email)
    {
        $persona = new Persona;
        $idPersona = $persona->personaByEmail($email);
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $cadena = "UPDATE `usuarios` SET `password`='$passwordHash' WHERE `idPersona`=$idPersona";
            if (mysqli_query($con, $cadena)) {
                return $idPersona;
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
    public function eliminar($idUsuario) // Delete from usuario where id = $idUsuario
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

             // Verificar si existen relaciones con el usuario
             $query = "SELECT COUNT(p.idPqrs) AS total
                        FROM usuarios u
                        JOIN pqrs_responsables pr ON pr.idResponsable = u.idUsuario
                        JOIN pqrs p               ON p.idPqrs = pr.idPqrs
                        where u.idUsuario= $idUsuario";
             $result = mysqli_query($con, $query);
             $row = mysqli_fetch_assoc($result);

             $query1 = "SELECT COUNT(ce.idConfig) AS total1
                        FROM usuarios u
                        LEFT JOIN config_escalamiento ce ON ce.idResponsable = u.idUsuario
                        where u.idUsuario = $idUsuario";
             $result1 = mysqli_query($con, $query1);
             $row1 = mysqli_fetch_assoc($result1);
     
             if ($row['total'] > 0 || $row1['total1'] > 0) {
                     // devolver un mensaje de error
                     return [
                         'status' => 'error',
                         'message' => 'No se puede eliminar el Usuario, ya se ha vinculado con PQRS o Escalamientos'
                     ];
                 } else {
                    $cadena = "DELETE FROM `usuarios` WHERE `idUsuario`= $idUsuario";
                    if (mysqli_query($con, $cadena)) {
                        return 1;
                    } else {
                        return [
                            'status' => 'error',
                            'message' => 'Error al intentar eliminar el usuario.'
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
