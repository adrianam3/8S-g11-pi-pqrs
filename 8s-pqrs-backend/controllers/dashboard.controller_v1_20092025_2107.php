<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Allow: GET, POST, OPTIONS");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// ⚠️ activar errores en dev (comenta en prod)
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once('../config/config.php');
$conObj = new ClaseConectar();
$con = $conObj->ProcedimientoParaConectar();

function param($name, $default=null){ return $_GET[$name] ?? $_POST[$name] ?? $default; }
function fechas(&$ini,&$fin){
  $ini = param('fechaInicio', date('Y-m-01'));
  $fin = param('fechaFin', date('Y-m-d'));
}
function prep(mysqli $con, string $sql, string $types='', array $vals=[]): mysqli_stmt {
  $stmt = $con->prepare($sql);
  if(!$stmt) throw new Exception("SQL PREPARE FAILED: {$con->error} | SQL: $sql");
  if($types!==''){
    if(!$stmt->bind_param($types, ...$vals)){
      throw new Exception("SQL BIND FAILED: {$stmt->error} | SQL: $sql");
    }
  }
  if(!$stmt->execute()){
    throw new Exception("SQL EXEC FAILED: {$stmt->error} | SQL: $sql");
  }
  return $stmt;
}

$op = param('op','kpis');

try {
  // Autotest rápido
  if ($op === '__selftest') {
    $stmt = prep($con, "SELECT 1");
    echo json_encode(['ok' => ($stmt->get_result()->fetch_row()[0] ?? 0) == 1]);
    exit;
  }

  // ========= KPIs (CSAT, NPS, CES) =========
  if ($op === 'kpis') {
    fechas($ini,$fin);

    // CSAT:
    // - Tomamos preguntas cuya escala observada sea <=5 (escala tipo satisfacción 0..4 o 1..5)
    // - Normalizamos cada respuesta a 0..100 (valorNumerico/maxEscala * 100) y promediamos
    //
    // NPS:
    // - Consideramos respuestas con valorNumerico 0..10 (escala NPS)
    // - NPS = (promotores - detractores) * 100 / totalNPS
    //
    // CES:
    // - Como proxy inicial, usamos 100 - CSAT (ajustamos cuando definas la pregunta CES específica)
    $sql = "
      SELECT
        -- CSAT %
        ROUND(AVG(CASE WHEN mv.maxv <= 5 AND mv.maxv > 0
                       THEN rc.valorNumerico * 100.0 / mv.maxv
                  END), 2) AS csat,

        -- NPS (promotores 9-10, detractores 0-6)
        ROUND((
          SUM(CASE WHEN rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
          SUM(CASE WHEN rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END)
        ) * 100.0 /
          NULLIF(SUM(CASE WHEN rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0)
        , 2) AS nps,

        -- CES proxy
        ROUND(100 - AVG(CASE WHEN mv.maxv <= 5 AND mv.maxv > 0
                             THEN rc.valorNumerico * 100.0 / mv.maxv
                        END), 2) AS ces

      FROM respuestascliente rc
      JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
      JOIN (
        SELECT idPregunta, MAX(valorNumerico) AS maxv
        FROM respuestascliente
        GROUP BY idPregunta
      ) mv ON mv.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?;
    ";
    $stmt = prep($con, $sql, 'ss', [$ini,$fin]);
    $row  = $stmt->get_result()->fetch_assoc() ?: ['csat'=>0,'nps'=>0,'ces'=>0];
    echo json_encode($row);
    exit;
  }

  // ========= PQRs por estado =========
  if ($op === 'pqrs_estado') {
    fechas($ini,$fin);
    // Ajusta el campo de fecha si tu tabla usa otro (p.ej. fechaRegistro)
    $sql = "
        SELECT est.nombre AS estado, COUNT(*) AS total
        FROM pqrs p
        JOIN estadospqrs est ON est.idEstado = p.idEstado
        WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
        GROUP BY est.nombre
        ORDER BY total DESC;
    ";
    $stmt = prep($con, $sql, 'ss', [$ini,$fin]);
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // ========= Encuestas por estado =========
  if ($op === 'encuestas_estado') {
    fechas($ini,$fin);
    // Contamos CONTESTADA cuando existe al menos una respuesta (respuestascliente)
    // Para los demás estados tomamos el estadoEnvio actual y que no tengan respuestas.
    $sql = "
      SELECT 'CONTESTADA' AS estado, COUNT(DISTINCT ep.idProgEncuesta) AS total
      FROM encuestasprogramadas ep
      JOIN respuestascliente rc ON rc.idProgEncuesta = ep.idProgEncuesta
      WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?
      UNION ALL
      SELECT ep.estadoEnvio AS estado, COUNT(*) AS total
      FROM encuestasprogramadas ep
      LEFT JOIN respuestascliente rc ON rc.idProgEncuesta = ep.idProgEncuesta
      WHERE rc.idRespCliente IS NULL
        AND DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?
      GROUP BY ep.estadoEnvio;
    ";
    $stmt = prep($con, $sql, 'ssss', [$ini,$fin,$ini,$fin]);
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  http_response_code(400);
  echo json_encode(['error'=>"Operación no soportada: $op"]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
} finally {
  if ($con) $con->close();
}
