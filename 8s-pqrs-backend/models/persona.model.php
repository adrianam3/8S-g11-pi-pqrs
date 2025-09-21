<?php
//TODO: Clase de Persona
require_once('../config/config.php');

class Persona
{
    //TODO: Implementar los métodos de la clase

    public $encryption_key = "clave_segura_256bits"; // Clave secreta
    private $cipher_method = "AES-256-CBC"; // Método de cifrado
    private $iv = "1234567890123456"; // IV (16 caracteres)

    public function encrypt($data) {
        return openssl_encrypt($data, $this->cipher_method, $this->encryption_key, 0, $this->iv);
    }

    private function decrypt($data) {
        return openssl_decrypt($data, $this->cipher_method, $this->encryption_key, 0, $this->iv);
    }

    public function todos()
    {
        $con = new ClaseConectar();
        $conn = $con->ProcedimientoParaConectar();
    
        $personas = [];
    
        $stmt = $conn->prepare("SELECT idPersona, cedula, nombres, apellidos, direccion, telefono, extension, celular, email, estado FROM persona");
    
        if ($stmt->execute()) {
            $result = $stmt->get_result();
    
            while ($row = $result->fetch_assoc()) {
                // Desencriptar los campos necesarios
                $row['cedula'] = $this->decrypt($row['cedula']);
                $row['telefono'] = $this->decrypt($row['telefono']);
                $row['celular'] = $this->decrypt($row['celular']);
    
                // Agregar campo nombre completo
                $row['personaNombreCompleto'] = $row['nombres'] . ' ' . $row['apellidos'];
    
                $personas[] = $row;
            }
        }
    
        $stmt->close();
        $conn->close();
    
        return $personas; // Array completo de resultados
    }
    

    public function todossinusuario() // Select * from persona
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT persona.* 
        FROM `persona`
        LEFT JOIN `usuario` ON usuario.idPersona = persona.idPersona
        WHERE usuario.idPersona IS NULL;
        ";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        return $datos;
    }

    public function todosByRol($idRol)
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT persona.email, 
       CONCAT(persona.nombres, ' ', persona.apellidos) AS nombreCompleto
        FROM `persona`
        LEFT JOIN `usuario` ON usuario.idPersona = persona.idPersona
        LEFT JOIN `rol` ON rol.idRol = usuario.idRol
        WHERE usuario.idRol = $idRol;
        ";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        return $datos;
    }
    public function personaByEmail($email)
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $email = mysqli_real_escape_string($con, $email);
        $cadena = "SELECT persona.idPersona
                   FROM `persona`
                   WHERE persona.email = '$email';";
        $result = mysqli_query($con, $cadena);
        $con->close();
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['idPersona'];
        } else {
            return null;
        }
    }
    
    public function unoOld($idPersona) // Select * from persona where id = $idPersona
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();
        $cadena = "SELECT persona.*,
        CONCAT(persona.nombres, ' ', persona.apellidos) AS personaNombreCompleto
        FROM `persona` WHERE `idPersona`=$idPersona";
        $datos = mysqli_query($con, $cadena);
        $con->close();
        
        if ($row = mysqli_fetch_assoc($result)) {
            // Desencriptar los datos antes de enviarlos
            $row['cedula'] = $this->decrypt($row['cedula']);
            $row['nombres'] =($row['nombres']);
            $row['apellidos'] = ($row['apellidos']);
            $row['direccion'] = ($row['direccion']);
            $row['telefono'] = ($row['telefono']);
            $row['celular'] = $this->decrypt($row['celular']);
            return $row;
        }

        return null;
    }

    public function uno($idPersona)
    {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();

        // Preparar la consulta de forma segura
        $stmt = $con->prepare("SELECT persona.*,
            CONCAT(persona.nombres, ' ', persona.apellidos) AS personaNombreCompleto
            FROM persona WHERE idPersona = ?");

        if (!$stmt) {
            error_log("Error preparando consulta: " . $con->error);
            return null;
        }

        // Asociar parámetro y ejecutar
        $stmt->bind_param("i", $idPersona); // "i" indica que es un entero
        $stmt->execute();

        // Obtener resultados
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Desencriptar campos necesarios
            $row['cedula'] = $this->decrypt($row['cedula']);
            $row['telefono'] = $this->decrypt($row['telefono']);
            $row['celular'] = $this->decrypt($row['celular']);

            // Resto de campos
            $row['nombres'] = $row['nombres'];
            $row['apellidos'] = $row['apellidos'];
            $row['direccion'] = $row['direccion'];
            $row['email'] = $row['email'];
            $row['extension'] = $row['extension'];
            $row['estado'] = $row['estado'];

            $stmt->close();
            $con->close();
            return $row;
        }

        $stmt->close();
        $con->close();
        return null;
    }

    public function insertar($cedula, $nombres, $apellidos, $direccion, $telefono, $extension, $celular, $email, $estado)
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            // Cifrado de campos sensibles
            $cedula_enc = $this->encrypt($cedula);
            $telefono_enc = $this->encrypt($telefono);
            $celular_enc = $this->encrypt($celular);

            // Consulta preparada
            $stmt = $con->prepare("INSERT INTO persona (
                cedula, nombres, apellidos, direccion, telefono, extension, celular, email, estado
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if (!$stmt) {
                throw new Exception("Error preparando la consulta: " . $con->error);
            }

            // Asociar los parámetros
            $stmt->bind_param(
                "sssssssss", // todos string (s)
                $cedula_enc,
                $nombres,
                $apellidos,
                $direccion,
                $telefono_enc,
                $extension,
                $celular_enc,
                $email,
                $estado
            );

            // Ejecutar
            if ($stmt->execute()) {
                $insertId = $stmt->insert_id;
                $stmt->close();
                $con->close();
                return $insertId;
            } else {
                throw new Exception("Error al ejecutar el insert: " . $stmt->error);
            }

        } catch (Exception $e) {
            http_response_code(500);
            return "Error: " . $e->getMessage();
        }
    }

    public function actualizar($idPersona, $cedula, $nombres, $apellidos, $direccion, $telefono, $extension, $celular, $email, $estado) // Update persona set ... where id = $idPersona
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();
            $cadena = "UPDATE `persona` SET `cedula`='$cedula', `nombres`='$nombres', `apellidos`='$apellidos', `direccion`='$direccion', `telefono`='$telefono', `extension`='$extension', `celular`='$celular', `email`='$email', `estado`='$estado', `fechaModificacion`=CURRENT_TIMESTAMP WHERE `idPersona`=$idPersona";
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
    

    public function eliminar($idPersona) // Delete from persona where id = $idPersona
    {
        try {
            $con = new ClaseConectar();
            $con = $con->ProcedimientoParaConectar();

            // Verificar si existen relaciones con la persona
            $query = "SELECT COUNT(*) as total FROM usuario WHERE idPersona = $idPersona";
            $result = mysqli_query($con, $query);
            $row = mysqli_fetch_assoc($result);
    
            if ($row['total'] > 0) {
                    // devolver un mensaje de error
                    return [
                        'status' => 'error',
                        'message' => 'No se puede eliminar la persona se ha vinculado con usuarios.'
                    ];
                } else {

                $cadena = "DELETE FROM `persona` WHERE `idPersona`= $idPersona";
                if (mysqli_query($con, $cadena)) {
                    return 1;
                } else {
                    return [
                        'status' => 'error',
                        'message' => 'Error al intentar eliminar la Persona.'
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

    public function actualizarPerfil($idPersona, $nombres, $apellidos, $telefono)
{
    try {
        $con = new ClaseConectar();
        $con = $con->ProcedimientoParaConectar();

        // Encriptar solo el campo sensible
        $telefono_enc = $this->encrypt($telefono);

        $stmt = $con->prepare("UPDATE persona 
            SET nombres = ?, 
                apellidos = ?, 
                telefono = ?, 
                fechaModificacion = CURRENT_TIMESTAMP 
            WHERE idPersona = ?");

        if (!$stmt) {
            throw new Exception("Error preparando la consulta: " . $con->error);
        }

        $stmt->bind_param("sssi", $nombres, $apellidos, $telefono_enc, $idPersona);

        if ($stmt->execute()) {
            $stmt->close();
            $con->close();
            return [
                "status" => "success",
                "message" => "Perfil actualizado correctamente"
            ];
        } else {
            throw new Exception("Error al ejecutar el update: " . $stmt->error);
        }
    } catch (Exception $e) {
        http_response_code(500);
        return [
            "status" => "error",
            "message" => $e->getMessage()
        ];
    }
}

}
