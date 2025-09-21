<?php
define("SECRET_KEY", "kEy-secImb."); // Clave secreta para JWT
class ClaseConectar
{
    public $conexion;
    protected $db;
    private $host = "localhost";
    private $usuario = "root";
    private $pass = "";
    private $base = "imbauto3_pqrs";
    // private $base = "help_desk";
    public function ProcedimientoParaConectar()
    {
        //Para mostrar errores
        // ini_set('display_errors', 1);
        // ini_set('display_startup_errors', 1);
        // error_reporting(E_ALL);

        $this->conexion = mysqli_connect($this->host, $this->usuario, $this->pass, $this->base);
        mysqli_query($this->conexion, "SET NAMES 'utf8'");
        if ($this->conexion->connect_error) {
            die("Error al conectar con el servidor: " . $this->conexion->connect_error);
        }
        $this->db = $this->conexion;
        if (!$this->db) {
            die("Error al conectar con la base de datos: " . $this->conexion->connect_error);
        }
        return $this->conexion;
    }
}