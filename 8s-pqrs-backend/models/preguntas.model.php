<?php
require_once('../config/config.php');

class Preguntas
{
    private function conectar()
    {
        $conObj = new ClaseConectar();
        return $conObj->ProcedimientoParaConectar();
    }

    // ====================
    //  CONSULTAR
    // ====================
    public function obtenerPorEncuesta($idEncuesta)
    {
        $con = $this->conectar();
        $preguntas = [];

        $sql = 'SELECT * FROM preguntas WHERE idEncuesta = ?';
        // if ($soloActivas) {
        //     $sql .= ' AND activa = 1';
        // }
        // $sql .= ' ORDER BY orden ASC';

        $stmt = $con->prepare($sql);
        $stmt->bind_param('s', $idEncuesta);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $row['opciones'] = $this->obtenerOpciones($con, $row['idPregunta']);
            $preguntas[] = $row;
        }

        $stmt->close();
        $con->close();
        return $preguntas;
    }

    private function obtenerOpciones($con, $idPregunta)
    {
        $opciones = [];

        $sql = 'SELECT * FROM respuestasopciones WHERE idPregunta = ? AND estado = 1 ORDER BY valorNumerico ASC';
        $stmt = $con->prepare($sql);
        $stmt->bind_param('i', $idPregunta);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $opciones[] = $row;
        }

        $stmt->close();
        return $opciones;
    }

    // ====================
    //   CREAR
    // ====================
    public function insertarPregunta($data)
    {
        $con = $this->conectar();
        $sql = 'INSERT INTO preguntas (idEncuesta, texto, orden, activa, scriptFinal, generaPqr, categoria)
                VALUES (?, ?, ?, ?, ?, ?, ?)';

        $stmt = $con->prepare($sql);
        $stmt->bind_param('isiisis',
            $data['idEncuesta'], $data['texto'], $data['orden'],
            $data['activa'], $data['scriptFinal'],
            $data['generaPqr'], $data['categoria']
        );

        $res = $stmt->execute();
        $idInsertado = $stmt->insert_id;

        $stmt->close();
        $con->close();

        return $res ? $idInsertado : false;
    }

    public function insertarOpcion($data)
    {
        $con = $this->conectar();
        $sql = 'INSERT INTO respuestasopciones (idPregunta, texto, valorNumerico, estado, requiereComentario, saltoPregunta)
                VALUES (?, ?, ?, ?, ?, ?)';

        $stmt = $con->prepare($sql);
        $stmt->bind_param('isiiii',
            $data['idPregunta'], $data['texto'],
            $data['valorNumerico'], $data['estado'],
            $data['requiereComentario'], $data['saltoPregunta']
        );

        $res = $stmt->execute();
        $idInsertado = $stmt->insert_id;

        $stmt->close();
        $con->close();

        return $res ? $idInsertado : false;
    }

    // ====================
    //   ACTUALIZAR
    // ====================
    public function actualizarPregunta($idPregunta, $data)
    {
        $con = $this->conectar();
        $sql = 'UPDATE preguntas SET texto=?, orden=?, activa=?, scriptFinal=?, generaPqr=?, categoria=? WHERE idPregunta=?';

        $stmt = $con->prepare($sql);
        $stmt->bind_param('siisisi',
            $data['texto'], $data['orden'], $data['activa'],
            $data['scriptFinal'], $data['generaPqr'],
            $data['categoria'], $idPregunta
        );

        $res = $stmt->execute();
        $stmt->close();
        $con->close();
        return $res;
    }

    public function actualizarOpcion($idOpcion, $data)
    {
        $con = $this->conectar();
        $sql = 'UPDATE respuestasopciones SET texto=?, valorNumerico=?, estado=?, requiereComentario=?, saltoPregunta=? WHERE idOpcion=?';

        $stmt = $con->prepare($sql);
        $stmt->bind_param('siiiii',
            $data['texto'], $data['valorNumerico'], $data['estado'],
            $data['requiereComentario'], $data['saltoPregunta'], $idOpcion
        );

        $res = $stmt->execute();
        $stmt->close();
        $con->close();
        return $res;
    }

    // ====================
    //   ELIMINACIÓN LÓGICA
    // ====================
    public function eliminarPregunta($idPregunta)
    {
        $con = $this->conectar();
        $sql = 'UPDATE preguntas SET activa = 0 WHERE idPregunta = ?';

        $stmt = $con->prepare($sql);
        $stmt->bind_param('i', $idPregunta);
        $res = $stmt->execute();
        $stmt->close();
        $con->close();
        return $res;
    }

    public function eliminarOpcion($idOpcion)
    {
        $con = $this->conectar();
        $sql = 'UPDATE respuestasopciones SET estado = 0 WHERE idOpcion = ?';

        $stmt = $con->prepare($sql);
        $stmt->bind_param('i', $idOpcion);
        $res = $stmt->execute();
        $stmt->close();
        $con->close();
        return $res;
    }
}
