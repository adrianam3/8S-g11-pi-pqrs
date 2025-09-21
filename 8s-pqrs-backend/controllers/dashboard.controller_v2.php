<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Allow: GET, POST, OPTIONS");
$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// ⚠️ Activar errores en DEV (comenta en PROD)
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once('../config/config.php');
$conObj = new ClaseConectar();
$con = $conObj->ProcedimientoParaConectar();

// ------------------------ Helpers ------------------------
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

/**
 * Ejecuta la primera variante de SQL que compile/ejecute correctamente.
 */
function tryPrep(mysqli $con, array $sqls, string $types, array $vals): mysqli_stmt {
  $lastErr = null;
  foreach ($sqls as $sql) {
    $stmt = $con->prepare($sql);
    if ($stmt) {
      if ($types !== '' && !$stmt->bind_param($types, ...$vals)) {
        $lastErr = "BIND: ".$stmt->error." | SQL: $sql";
        continue;
      }
      if (!$stmt->execute()) {
        $lastErr = "EXEC: ".$stmt->error." | SQL: $sql";
        continue;
      }
      return $stmt;
    } else {
      $lastErr = "PREP: ".$con->error." | SQL: $sql";
    }
  }
  throw new Exception("tryPrep failed. Last error: $lastErr");
}

/**
 * Expresión de período para series temporales.
 * W: semanal ISO | M: mensual | Q: trimestral | Y: anual
 */
function periodo_expr(string $periodo, string $colFecha): array {
  switch (strtoupper($periodo)) {
    case 'W':
      return ["YEARWEEK($colFecha, 3)", "CONCAT(YEAR($colFecha),'-W',LPAD(WEEK($colFecha,3),2,'0'))"];
    case 'Q':
      return ["CONCAT(YEAR($colFecha),'-Q',QUARTER($colFecha))", "CONCAT(YEAR($colFecha),'-Q',QUARTER($colFecha))"];
    case 'Y':
      return ["YEAR($colFecha)", "YEAR($colFecha)"];
    default: // M
      return ["DATE_FORMAT($colFecha,'%Y-%m')", "DATE_FORMAT($colFecha,'%Y-%m')"];
  }
}

// ------------------------ Router ------------------------
$op = param('op','kpis');

try {
  // Autotest
  if ($op === '__selftest') {
    $stmt = prep($con, "SELECT 1");
    echo json_encode(['ok' => ($stmt->get_result()->fetch_row()[0] ?? 0) == 1]);
    exit;
  }

  // ======================================================
  // KPIs base
  // ======================================================

  // CSAT (%) promedio de preguntas ESCALA_1_10 con esNps=0 (valorNumerico 0..10 → *10)
  // NPS a partir de esNps=1
  // CES (proxy): 100 - CSAT  (ajustar si defines una pregunta CES específica)
  if ($op === 'kpis') {
    fechas($ini,$fin);
    $sql = "
      SELECT
        IFNULL(ROUND(AVG(CASE WHEN p.tipo='ESCALA_1_10' AND p.esNps=0 THEN rc.valorNumerico END)*10, 2), 0) AS csat,
        IFNULL(ROUND((
          SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
          SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END)
        ) * 100.0 / NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2), 0) AS nps,
        IFNULL(ROUND(100 - (AVG(CASE WHEN p.tipo='ESCALA_1_10' AND p.esNps=0 THEN rc.valorNumerico END)*10), 2), 0) AS ces
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?;
    ";
    $stmt = prep($con, $sql, 'ss', [$ini,$fin]);
    $row  = $stmt->get_result()->fetch_assoc() ?: ['csat'=>0,'nps'=>0,'ces'=>0];
    echo json_encode($row);
    exit;
  }

  // PQRs por estado (usa estadospqrs)
  else if ($op === 'pqrs_estado') {
    fechas($ini,$fin);
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

  // Encuestas por estado (CONTESTADA si existe al menos una respuesta; resto por estadoEnvio)
  else if ($op === 'encuestas_estado') {
    fechas($ini,$fin);
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

  // ======================================================
  // Segmentados (canal | agencia)
  // ======================================================

  // CSAT segmentado (0..100) por canal o agencia
  else if ($op === 'csat_segment') {
    fechas($ini,$fin);
    $segment = strtolower(param('segment','canal'));

    if ($segment === 'canal') {
      $sql = "
        SELECT c.nombre AS segmento,
               IFNULL(ROUND(AVG(rc.valorNumerico)*10,2),0) AS csat
        FROM respuestascliente rc
        JOIN preguntas p             ON p.idPregunta = rc.idPregunta AND p.tipo='ESCALA_1_10' AND p.esNps = 0
        JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
        JOIN encuestas e             ON e.idEncuesta = ep.idEncuesta
        JOIN canales c               ON c.idCanal   = e.idCanal
        WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
        GROUP BY c.nombre
        ORDER BY csat DESC";
      $stmt = prep($con, $sql, 'ss', [$ini,$fin]);

    } else if ($segment === 'agencia') {
      $sql = "
        SELECT ag.nombre AS segmento,
               IFNULL(ROUND(AVG(rc.valorNumerico)*10,2),0) AS csat
        FROM respuestascliente rc
        JOIN preguntas p             ON p.idPregunta = rc.idPregunta AND p.tipo='ESCALA_1_10' AND p.esNps = 0
        JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
        LEFT JOIN atenciones a       ON a.idAtencion = ep.idAtencion
        LEFT JOIN agencias ag        ON ag.idAgencia = a.idAgencia
        WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
        GROUP BY ag.nombre
        ORDER BY csat DESC";
      $stmt = prep($con, $sql, 'ss', [$ini,$fin]);

    } else {
      http_response_code(400);
      echo json_encode(['error'=>'segment no soportado. Usa canal|agencia']);
      exit;
    }

    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // NPS segmentado por canal o agencia
  else if ($op === 'nps_segment') {
    fechas($ini,$fin);
    $segment = strtolower(param('segment','canal'));
    $filtroNps = "AND p.esNps = 1"; // usa solo preguntas NPS

    if ($segment === 'canal') {
      $sql = "
        SELECT c.nombre AS segmento,
               ROUND((
                 (SUM(CASE WHEN rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
                  SUM(CASE WHEN rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END))
                 * 100.0 / NULLIF(SUM(CASE WHEN rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0)
               ), 2) AS nps
        FROM respuestascliente rc
        JOIN preguntas p             ON p.idPregunta = rc.idPregunta $filtroNps
        JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
        JOIN encuestas e             ON e.idEncuesta = ep.idEncuesta
        JOIN canales c               ON c.idCanal   = e.idCanal
        WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
        GROUP BY c.nombre
        ORDER BY nps DESC";
      $stmt = prep($con, $sql, 'ss', [$ini,$fin]);

    } else if ($segment === 'agencia') {
      $sql = "
        SELECT ag.nombre AS segmento,
               ROUND((
                 (SUM(CASE WHEN rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
                  SUM(CASE WHEN rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END))
                 * 100.0 / NULLIF(SUM(CASE WHEN rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0)
               ), 2) AS nps
        FROM respuestascliente rc
        JOIN preguntas p             ON p.idPregunta = rc.idPregunta $filtroNps
        JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
        LEFT JOIN atenciones a       ON a.idAtencion = ep.idAtencion
        LEFT JOIN agencias   ag      ON ag.idAgencia = a.idAgencia
        WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
        GROUP BY ag.nombre
        ORDER BY nps DESC";
      $stmt = prep($con, $sql, 'ss', [$ini,$fin]);

    } else {
      http_response_code(400);
      echo json_encode(['error'=>'segment no soportado. Usa canal|agencia']);
      exit;
    }

    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // ======================================================
  // Series temporales (W | M | Q | Y)
  // ======================================================

  // CSAT series (%)
  else if ($op === 'csat_series') {
    fechas($ini,$fin);
    $periodo = param('period','M');
    [$grp,$label] = periodo_expr($periodo, 'rc.fechaRespuesta');

    $sql = "
      SELECT $label AS periodo,
             IFNULL(ROUND(AVG(CASE WHEN p.tipo='ESCALA_1_10' AND p.esNps=0 THEN rc.valorNumerico END)*10,2),0) AS csat
      FROM respuestascliente rc
      JOIN preguntas p             ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY $grp
      ORDER BY MIN(rc.fechaRespuesta);
    ";
    $stmt = prep($con, $sql, 'ss', [$ini,$fin]);
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // NPS series
  else if ($op === 'nps_series') {
    fechas($ini,$fin);
    $periodo = param('period','M');
    [$grp,$label] = periodo_expr($periodo, 'rc.fechaRespuesta');

    $sql = "
      SELECT $label AS periodo,
             ROUND((
               (SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
                SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END))
               * 100.0 / NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0)
             ), 2) AS nps
      FROM respuestascliente rc
      JOIN preguntas p             ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY $grp
      ORDER BY MIN(rc.fechaRespuesta);
    ";
    $stmt = prep($con, $sql, 'ss', [$ini,$fin]);
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // PQRs series (conteo)
  else if ($op === 'pqrs_series') {
    fechas($ini,$fin);
    $periodo = param('period','M');
    [$grp,$label] = periodo_expr($periodo, 'p.fechaCreacion');

    $sql = "
      SELECT $label AS periodo, COUNT(*) AS total
      FROM pqrs p
      WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
      GROUP BY $grp
      ORDER BY MIN(p.fechaCreacion);
    ";
    $stmt = prep($con, $sql, 'ss', [$ini,$fin]);
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // ======================================================
  // Correlación CSAT vs PQRs por período
  // ======================================================
  else if ($op === 'csat_pqrs_corr') {
    fechas($ini,$fin);
    $periodo = param('period','M');
    [$grp1,$label1] = periodo_expr($periodo, 'rc.fechaRespuesta');
    [$grp2,$label2] = periodo_expr($periodo, 'p.fechaCreacion');

    // CSAT por período
    $sql1 = "
      SELECT $label1 AS periodo,
             IFNULL(ROUND(AVG(CASE WHEN p.tipo='ESCALA_1_10' AND p.esNps=0 THEN rc.valorNumerico END)*10,2),0) AS csat
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY $grp1
    ";
    $s1 = prep($con, $sql1, 'ss', [$ini,$fin]);
    $csat = [];
    foreach ($s1->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $csat[$r['periodo']] = $r['csat'];

    // PQRs por período
    $sql2 = "
      SELECT $label2 AS periodo, COUNT(*) AS pqrs
      FROM pqrs p
      WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
      GROUP BY $grp2
    ";
    $s2 = prep($con, $sql2, 'ss', [$ini,$fin]);

    $out = [];
    foreach ($s2->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
      $per = $r['periodo'];
      $out[] = ['periodo'=>$per, 'csat'=>floatval($csat[$per] ?? 0), 'pqrs'=>intval($r['pqrs'])];
    }
    echo json_encode($out);
    exit;
  }

  // Operación no soportada
  else {
    http_response_code(400);
    echo json_encode(['error'=>"Operación no soportada: $op"]);
    exit;
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
} finally {
  if ($con) $con->close();
}
