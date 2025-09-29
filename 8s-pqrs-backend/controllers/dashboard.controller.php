<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Allow: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

$method = $_SERVER["REQUEST_METHOD"] ?? 'GET';
if ($method === "OPTIONS") { die(); }

// ⚠️ Activar errores en DEV (comenta en PROD)
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once('../config/config.php');
$conObj = new ClaseConectar();
$con = $conObj->ProcedimientoParaConectar();

// ================= Helpers =================
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

/** Periodo: W semanal ISO | M mensual | Q trimestral | Y anual */
function periodo_expr(string $periodo, string $colFecha): array {
  switch (strtoupper($periodo)) {
    case 'W': return ["YEARWEEK($colFecha, 3)", "CONCAT(YEAR($colFecha),'-W',LPAD(WEEK($colFecha,3),2,'0'))"];
    case 'Q': return ["CONCAT(YEAR($colFecha),'-Q',QUARTER($colFecha))", "CONCAT(YEAR($colFecha),'-Q',QUARTER($colFecha))"];
    case 'Y': return ["YEAR($colFecha)", "YEAR($colFecha)"];
    default : return ["DATE_FORMAT($colFecha,'%Y-%m')", "DATE_FORMAT($colFecha,'%Y-%m')"];
  }
}

/** Rango anterior del mismo tamaño (para deltas) */
function rango_anterior(string $ini, string $fin): array {
  $d1 = new DateTime($ini);
  $d2 = new DateTime($fin);
  $diffDays = max(1, $d1->diff($d2)->days + 1);
  $prevFin = (clone $d1)->modify('-1 day');
  $prevIni = (clone $prevFin)->modify('-'.($diffDays-1).' day');
  return [$prevIni->format('Y-m-d'), $prevFin->format('Y-m-d')];
}

/** Chequeos opcionales de esquema (evitan romper si falta alguna tabla auxiliar) */
function table_exists(mysqli $con, string $table): bool {
  $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=?";
  $st = $con->prepare($sql); $st->bind_param('s',$table); $st->execute();
  return (bool)$st->get_result()->fetch_row();
}

// ================= Router =================
$op = param('op','kpis');

try {
  // Autotest
  if ($op === '__selftest') {
    $stmt = prep($con, "SELECT 1");
    echo json_encode(['ok' => ($stmt->get_result()->fetch_row()[0] ?? 0) == 1]);
    exit;
  }

  // -------------------------------------------------------
  // KPIs base: kpis | pqrs_estado | encuestas_estado
  // -------------------------------------------------------

  // kpis -> CSAT (% satisfechos no-NPS/CES), NPS (y breakdown), CES (promedio 1–5 solo esCes=1)
  if ($op === 'kpis') {
    fechas($ini,$fin);

    // CSAT: % satisfechos (4–5), excluyendo preguntas NPS/CES
    $sqlCsat = "
      SELECT IFNULL(ROUND(
        SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 4 AND 5 THEN 1 ELSE 0 END) * 100.0 /
        NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico IS NOT NULL THEN 1 ELSE 0 END),0)
      , 2), 0) AS csat
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?;
    ";
    $csat = floatval(prep($con,$sqlCsat,'ss',[$ini,$fin])->get_result()->fetch_assoc()['csat'] ?? 0);

    // NPS: SOLO preguntas esNps=1 (0..10) + breakdown
    $sqlNps = "
      SELECT
        IFNULL(ROUND((
          SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
          SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END)
        ) * 100.0 /
          NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2), 0) AS nps,

        IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END) * 100.0 /
                     NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2), 0) AS detractores_pct,
        IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 7 AND 8  THEN 1 ELSE 0 END) * 100.0 /
                     NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2), 0) AS pasivos_pct,
        IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) * 100.0 /
                     NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2), 0) AS promotores_pct
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?;
    ";
    $rowNps = prep($con,$sqlNps,'ss',[$ini,$fin])->get_result()->fetch_assoc()
              ?? ['nps'=>0,'detractores_pct'=>0,'pasivos_pct'=>0,'promotores_pct'=>0];

    // CES: SOLO preguntas esCes=1 (promedio 1–5)
    $sqlCes = "
      SELECT IFNULL(ROUND(AVG(
        CASE WHEN p.esCes=1 THEN rc.valorNumerico ELSE NULL END
      ), 2), 0) AS ces
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?;
    ";
    $ces = floatval(prep($con,$sqlCes,'ss',[$ini,$fin])->get_result()->fetch_assoc()['ces'] ?? 0);

    echo json_encode([
      'csat' => $csat,
      'nps'  => floatval($rowNps['nps']),
      'nps_breakdown'=>[
        'detractores_pct'=>floatval($rowNps['detractores_pct']),
        'pasivos_pct'    =>floatval($rowNps['pasivos_pct']),
        'promotores_pct' =>floatval($rowNps['promotores_pct'])
      ],
      'ces'  => $ces
    ]);
    exit;
  }

  // PQRs por estado
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
    echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // Encuestas por estado (RESPONDIDA si tiene al menos una respuesta)
  else if ($op === 'encuestas_estado') {
    fechas($ini,$fin);
    $sql = "
      SELECT 'RESPONDIDA' AS estado, COUNT(DISTINCT ep.idProgEncuesta) AS total
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
    echo json_encode(prep($con,$sql,'ssss',[$ini,$fin,$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // -------------------------------------------------------
  // Tarjetas / Tendencias / Resúmenes
  // -------------------------------------------------------

  // Tarjetas overview
  else if ($op === 'cards_overview') {
    fechas($ini,$fin);
    [$pini,$pfin] = rango_anterior($ini,$fin);

    $sqlPqrsTot = "SELECT COUNT(*) tot FROM pqrs p WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?";
    $cAct = intval(prep($con,$sqlPqrsTot,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);
    $cPrev= intval(prep($con,$sqlPqrsTot,'ss',[$pini,$pfin])->get_result()->fetch_assoc()['tot'] ?? 0);

    $sqlOpen = "
      SELECT COUNT(*) tot
      FROM pqrs p JOIN estadospqrs e ON e.idEstado=p.idEstado
      WHERE DATE(p.fechaCreacion) BETWEEN ? AND ? AND UPPER(e.nombre) NOT IN ('CERRADO','RESUELTO')
    ";
    $oAct = intval(prep($con,$sqlOpen,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);
    $oPrev= intval(prep($con,$sqlOpen,'ss',[$pini,$pfin])->get_result()->fetch_assoc()['tot'] ?? 0);

    $sqlEnv = "SELECT COUNT(*) tot FROM encuestasprogramadas ep WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?";
    $eAct = intval(prep($con,$sqlEnv,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);
    $ePrev= intval(prep($con,$sqlEnv,'ss',[$pini,$pfin])->get_result()->fetch_assoc()['tot'] ?? 0);

    $sqlSat = "
      SELECT IFNULL(ROUND(AVG(
        CASE
          WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico IS NOT NULL THEN
            CASE WHEN rc.valorNumerico BETWEEN 1 AND 5 THEN rc.valorNumerico*2 ELSE rc.valorNumerico END
          ELSE NULL
        END
      ),1),0) sat10
      FROM respuestascliente rc JOIN preguntas p ON p.idPregunta=rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?";
    $sAct = floatval(prep($con,$sqlSat,'ss',[$ini,$fin])->get_result()->fetch_assoc()['sat10'] ?? 0);
    $sPrev= floatval(prep($con,$sqlSat,'ss',[$pini,$pfin])->get_result()->fetch_assoc()['sat10'] ?? 0);

    $deltaPct = function($a,$b){ return ($b>0) ? round((($a-$b)/$b)*100,2) : null; };

    echo json_encode([
      'total_pqrs'          => ['value'=>$cAct, 'delta_pct_vs_prev'=>$deltaPct($cAct,$cPrev)],
      'pqrs_abiertas'       => ['value'=>$oAct, 'delta_pct_vs_prev'=>$deltaPct($oAct,$oPrev)],
      'encuestas_enviadas'  => ['value'=>$eAct, 'delta_pct_vs_prev'=>$deltaPct($eAct,$ePrev)],
      'satisfaccion_avg_10' => ['value'=>$sAct, 'delta_abs_vs_prev'=>($sPrev!==0? round($sAct-$sPrev,1):null)]
    ]);
    exit;
  }

  // Tendencia satisfacción (1–5) + PQRs
  else if ($op === 'tendencia_satisfaccion_pqrs') {
    fechas($ini,$fin);
    $sql1 = "
      SELECT DATE_FORMAT(rc.fechaRespuesta,'%Y-%m') periodo,
             IFNULL(ROUND(AVG(
               CASE
                 WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 1 AND 5 THEN
                   CASE WHEN rc.valorNumerico BETWEEN 1 AND 5 THEN
                     CASE WHEN rc.valorNumerico <= 5 THEN rc.valorNumerico
                          ELSE rc.valorNumerico/2
                     END
                   END
                 ELSE NULL
               END
             ),2),0) sat_5
      FROM respuestascliente rc JOIN preguntas p ON p.idPregunta=rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY 1 ORDER BY 1;
    ";
    $sat = prep($con,$sql1,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC);

    $sql2 = "
      SELECT DATE_FORMAT(p.fechaCreacion,'%Y-%m') periodo, COUNT(*) pqrs
      FROM pqrs p WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
      GROUP BY 1 ORDER BY 1;
    ";
    $pq = prep($con,$sql2,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC);

    $map = [];
    foreach($sat as $r){ $map[$r['periodo']] = ['periodo'=>$r['periodo'],'satisfaccion_5'=>floatval($r['sat_5']),'pqrs'=>0]; }
    foreach($pq as $r){ $per=$r['periodo']; $map[$per] = $map[$per] ?? ['periodo'=>$per,'satisfaccion_5'=>0,'pqrs'=>0]; $map[$per]['pqrs']=intval($r['pqrs']); }
    ksort($map);
    echo json_encode(array_values($map));
    exit;
  }

  // Tasa de respuesta (respondidas / enviadas)
  else if ($op === 'tasa_respuesta') {
    fechas($ini,$fin);
    $sqlEnv = "SELECT COUNT(*) tot FROM encuestasprogramadas ep WHERE ep.estado=1 and DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?";
    $sqlResp= "
      SELECT COUNT(DISTINCT ep.idProgEncuesta) tot
      FROM encuestasprogramadas ep JOIN respuestascliente rc ON rc.idProgEncuesta=ep.idProgEncuesta
      WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?";
    $env  = intval(prep($con,$sqlEnv,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);
    $resp = intval(prep($con,$sqlResp,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);
    $tasa = ($env>0)? round($resp*100.0/$env,2) : 0;
    echo json_encode(['enviadas'=>$env,'respondidas'=>$resp,'tasa_pct'=>$tasa]);
    exit;
  }

//27/09/2025
// ===============================
// ENCUESTAS: overview POR SEGMENTO
// ?op=encuestas_segment_overview&segment=canal|agencia&fechaInicio=YYYY-MM-DD&fechaFin=YYYY-MM-DD
// Devuelve: [{ segmento, programadas, enviadas, respondidas, tasa_pct }]
// ===============================
else if ($op === 'encuestas_segment_overview') {
  $segment = strtolower(param('segment', 'canal'));
  if (!in_array($segment, ['canal','agencia'])) {
    http_response_code(400);
    echo json_encode(['error' => "segment inválido. Use 'canal' o 'agencia'."]);
    exit;
  }

  fechas($ini, $fin); // setea $ini y $fin (YYYY-MM-DD)

  if ($segment === 'canal') {
    // Por CANAL
    $sql = "
            SELECT
              c.nombre AS segmento,
              COUNT(DISTINCT ep.idProgEncuesta)                                             AS programadas,
              COUNT(DISTINCT CASE WHEN ep.estado = 1 THEN ep.idProgEncuesta END) AS enviadas,
              COUNT(DISTINCT rc.idProgEncuesta)                                             AS respondidas,
              ROUND(
                COUNT(DISTINCT rc.idProgEncuesta) * 100.0 /
                NULLIF(COUNT(DISTINCT CASE WHEN ep.estado = 1 THEN ep.idProgEncuesta END), 0)
              , 2) AS tasa_pct
            FROM encuestasprogramadas ep
            JOIN atenciones a   ON a.idAtencion = ep.idAtencion
            JOIN canales c      ON c.idCanal    = a.idCanal
            LEFT JOIN respuestascliente rc
                  ON rc.idProgEncuesta = ep.idProgEncuesta
            WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?   -- (opcional)
            GROUP BY c.nombre
            ORDER BY c.nombre;
    ";
  } else {
    // Por AGENCIA
    $sql = "
            SELECT ag.nombre AS segmento,
                  COUNT(DISTINCT ep.idProgEncuesta) AS programadas,
                  COUNT(DISTINCT CASE WHEN ep.estado = 1 THEN ep.idProgEncuesta END) AS enviadas,
                  COUNT(DISTINCT rc.idProgEncuesta) AS respondidas,
                  -- ROUND(COUNT(DISTINCT rc.idProgEncuesta) * 100.0 / COUNT(*), 2) AS tasa_pct
                    ROUND(
                COUNT(DISTINCT rc.idProgEncuesta) * 100.0 /
                NULLIF(COUNT(DISTINCT CASE WHEN ep.estado = 1 THEN ep.idProgEncuesta END), 0)
              , 2)  AS tasa_pct
            FROM encuestasprogramadas ep
            JOIN atenciones a   ON a.idAtencion = ep.idAtencion
            JOIN agencias ag    ON ag.idAgencia = a.idAgencia
            LEFT JOIN respuestascliente rc ON rc.idProgEncuesta = ep.idProgEncuesta
            WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?
            GROUP BY ag.nombre
ORDER BY ag.nombre
    ";
  }

  $stmt = prep($con, $sql, 'ss', [$ini, $fin]);
  $res  = $stmt->get_result();
  $out  = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      'segmento'    => $row['segmento'],
      'programadas' => (int)$row['programadas'],
      'enviadas'    => (int)$row['enviadas'],     // misma definición que en tasa_respuesta
      'respondidas' => (int)$row['respondidas'],
      'tasa_pct'    => (float)$row['tasa_pct']
    ];
  }
  echo json_encode($out);
  exit;
}

else if ($op === 'nps_resumen_entidad') {
  fechas($ini,$fin);
  $idEncuesta = isset($_GET['idEncuesta']) ? intval($_GET['idEncuesta']) : null;

  $extra = $idEncuesta ? " AND e.idEncuesta = ? " : "";

  $sql = "
    SELECT
      e.idEncuesta,
      e.nombre AS encuesta,
      c.nombre AS canal,

      a.idAsesor,
      UPPER(COALESCE(CONCAT(TRIM(pe.nombres),' ',TRIM(pe.apellidos)), '(SIN ASESOR)')) AS asesor,

      ag.idAgencia,
      UPPER(ag.nombre) AS agencia,

      SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END) AS total_nps,
      SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END) AS detractores,
      SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico IN (7,8)          THEN 1 ELSE 0 END) AS pasivos,
      SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) AS promotores,

      IFNULL(ROUND(
        100.0 * SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 6 THEN 1 ELSE 0 END) /
        NULLIF(SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END), 0)
      , 2), 0) AS detractores_pct,

      IFNULL(ROUND(
        100.0 * SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico IN (7,8) THEN 1 ELSE 0 END) /
        NULLIF(SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END), 0)
      , 2), 0) AS pasivos_pct,

      IFNULL(ROUND(
        100.0 * SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) /
        NULLIF(SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END), 0)
      , 2), 0) AS promotores_pct,

      IFNULL(ROUND((
        SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
        SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END)
      ) * 100.0 /
        NULLIF(SUM(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END), 0), 2), 0) AS nps

    FROM respuestascliente rc
    JOIN preguntas p             ON p.idPregunta       = rc.idPregunta
    JOIN encuestasprogramadas ep ON ep.idProgEncuesta  = rc.idProgEncuesta
    JOIN encuestas e             ON e.idEncuesta       = ep.idEncuesta
    JOIN canales   c             ON c.idCanal          = e.idCanal
    JOIN atenciones a            ON a.idAtencion       = ep.idAtencion
    LEFT JOIN usuarios u         ON u.idUsuario        = a.idAsesor
    LEFT JOIN personas pe        ON pe.idPersona       = u.idPersona
    LEFT JOIN agencias ag        ON ag.idAgencia       = a.idAgencia
    WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
    $extra
    GROUP BY
      e.idEncuesta, e.nombre, c.nombre,
      a.idAsesor, asesor,
      ag.idAgencia, agencia
    ORDER BY e.idEncuesta, e.nombre, c.nombre, agencia, asesor
  ";

  $types = $idEncuesta ? 'ssi' : 'ss';
  $vals  = $idEncuesta ? [$ini,$fin,$idEncuesta] : [$ini,$fin];

  $rows = prep($con,$sql,$types,$vals)->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode($rows);
  exit;
}

else if ($op === 'nps_clientes') {
  fechas($ini,$fin);

  $sql = "
    SELECT
      e.idEncuesta,
      e.nombre AS encuesta,
      c.nombre AS canal,

      a.idAsesor,
      UPPER(COALESCE(CONCAT(TRIM(pe.nombres),' ',TRIM(pe.apellidos)), '(SIN ASESOR)')) AS asesor,

      ag.idAgencia,
      UPPER(ag.nombre) AS agencia,

      cl.idCliente,
      CONCAT(TRIM(cl.nombres),' ',TRIM(cl.apellidos)) AS cliente,
      cl.celular,
      cl.email,

      ROUND(AVG(CASE
                  WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10
                  THEN rc.valorNumerico
                END), 2) AS nps_val,

      CASE
        WHEN AVG(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10
                      THEN rc.valorNumerico END) IS NULL
          THEN 'SIN NPS'
        WHEN ROUND(AVG(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10
                            THEN rc.valorNumerico END), 0) BETWEEN 0 AND 6
          THEN 'DETRACTOR'
        WHEN ROUND(AVG(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10
                            THEN rc.valorNumerico END), 0) IN (7,8)
          THEN 'PASIVO'
        WHEN ROUND(AVG(CASE WHEN p.esNps = 1 AND rc.valorNumerico BETWEEN 0 AND 10
                            THEN rc.valorNumerico END), 0) BETWEEN 9 AND 10
          THEN 'PROMOTOR'
        ELSE 'SIN NPS'
      END AS clasificacion_nps

    FROM respuestascliente rc
    JOIN preguntas p             ON p.idPregunta       = rc.idPregunta
    JOIN encuestasprogramadas ep ON ep.idProgEncuesta  = rc.idProgEncuesta
    JOIN encuestas e             ON e.idEncuesta       = ep.idEncuesta
    JOIN canales   c             ON c.idCanal          = e.idCanal
    JOIN atenciones a            ON a.idAtencion       = ep.idAtencion
    LEFT JOIN usuarios u         ON u.idUsuario        = a.idAsesor
    LEFT JOIN personas pe        ON pe.idPersona       = u.idPersona
    LEFT JOIN agencias ag        ON ag.idAgencia       = a.idAgencia
    LEFT JOIN clientes cl        ON cl.idCliente       = ep.idCliente
    WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
    GROUP BY
      e.idEncuesta, e.nombre, c.nombre,
      a.idAsesor, asesor,
      ag.idAgencia, agencia,
      cl.idCliente, cliente, cl.celular, cl.email
    ORDER BY e.idEncuesta, e.nombre, c.nombre, agencia, asesor, cliente
  ";

  $rows = prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode($rows);
  exit;
}


// firn 27/09/2025





  // Resumen de PQRs
  else if ($op === 'pqrs_resumen') {
    fechas($ini,$fin);
    $sql = "
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN UPPER(e.nombre) IN ('ABIERTO','PENDIENTE') THEN 1 ELSE 0 END) AS abiertos,
        SUM(CASE WHEN UPPER(e.nombre) LIKE 'EN PROCESO%'             THEN 1 ELSE 0 END) AS en_proceso,
        SUM(CASE WHEN UPPER(e.nombre) LIKE 'ESCAL%'                  THEN 1 ELSE 0 END) AS escalados,
        SUM(CASE WHEN UPPER(e.nombre) IN ('CERRADO','RESUELTO')      THEN 1 ELSE 0 END) AS cerrados
      FROM pqrs p
      JOIN estadospqrs e ON e.idEstado=p.idEstado
      WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?";
    $row = prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_assoc() ?? [];
    echo json_encode([
      'total'=>intval($row['total'] ?? 0),
      'abiertos'=>intval($row['abiertos'] ?? 0),
      'en_proceso'=>intval($row['en_proceso'] ?? 0),
      'escalados'=>intval($row['escalados'] ?? 0),
      'cerrados'=>intval($row['cerrados'] ?? 0),
    ]);
    exit;
  }

/***********************
 * PQRs – Totales por tipo (PETICION, QUEJA, RECLAMO, SUGERENCIA, TOTAL)
 * ?op=pqrs_tipo_totales&fechaInicio=YYYY-MM-DD&fechaFin=YYYY-MM-DD
 ***********************/
else if ($op === 'pqrs_tipo_totales') {
  // si tu esquema no tiene tipospqrs, devuelve ceros
  if (!table_exists($con,'tipospqrs')) {
    echo json_encode(['peticion'=>0,'queja'=>0,'reclamo'=>0,'sugerencia'=>0,'total'=>0]);
    exit;
  }

  fechas($ini,$fin);

  $sql = "
    SELECT
      SUM(CASE WHEN t.nombre = 'PETICION'   AND p.idPqrs IS NOT NULL THEN 1 ELSE 0 END) AS PETICION,
      SUM(CASE WHEN t.nombre = 'QUEJA'      AND p.idPqrs IS NOT NULL THEN 1 ELSE 0 END) AS QUEJA,
      SUM(CASE WHEN t.nombre = 'RECLAMO'    AND p.idPqrs IS NOT NULL THEN 1 ELSE 0 END) AS RECLAMO,
      SUM(CASE WHEN t.nombre = 'SUGERENCIA' AND p.idPqrs IS NOT NULL THEN 1 ELSE 0 END) AS SUGERENCIA,
      COUNT(p.idPqrs) AS TOTAL
    FROM tipospqrs t
    LEFT JOIN pqrs p ON p.idTipo = t.idTipo
      AND DATE(p.fechaCreacion) BETWEEN ? AND ?;
  ";
  $row = prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_assoc() ?? [
    'PETICION'=>0,'QUEJA'=>0,'RECLAMO'=>0,'SUGERENCIA'=>0,'TOTAL'=>0
  ];

  echo json_encode([
    'peticion'   => intval($row['PETICION'] ?? 0),
    'queja'      => intval($row['QUEJA'] ?? 0),
    'reclamo'    => intval($row['RECLAMO'] ?? 0),
    'sugerencia' => intval($row['SUGERENCIA'] ?? 0),
    'total'      => intval($row['TOTAL'] ?? 0),
  ]);
  exit;
}

/* ===============================
   PQRs: Pivot Agencia × Canal × Tipo (columnas por estado)
   ?op=pqrs_pivot_agencia_canal_tipo&fechaInicio=YYYY-MM-DD&fechaFin=YYYY-MM-DD[&idAgencia=..&idCanal=..&idTipo=..]
   =============================== */
else if ($op === 'pqrs_pivot_agencia_canal_tipo') {
  fechas($ini,$fin);

  // Filtros opcionales
  $idAgencia = param('idAgencia', null);
  $idCanal   = param('idCanal',   null);
  $idTipo    = param('idTipo',    null);

  $sql = "
    SELECT
      ag.idAgencia,
      UPPER(COALESCE(ag.nombre,'(SIN AGENCIA)')) AS agencia,
      c.idCanal,
      COALESCE(c.nombre,'(SIN CANAL)')           AS canal,
      t.idTipo,
      UPPER(COALESCE(t.nombre,'(SIN TIPO)'))     AS tipo,

      COUNT(*) AS total,

      SUM(CASE WHEN UPPER(e.nombre) IN ('ABIERTO','PENDIENTE') THEN 1 ELSE 0 END) AS abiertos,
      SUM(CASE WHEN UPPER(e.nombre) LIKE 'EN PROCESO%' THEN 1 ELSE 0 END)         AS en_proceso,
      SUM(CASE WHEN UPPER(e.nombre) LIKE 'ESCAL%' THEN 1 ELSE 0 END)              AS escalados,
      SUM(CASE WHEN UPPER(e.nombre) IN ('CERRADO','RESUELTO') THEN 1 ELSE 0 END)  AS cerrados
    FROM pqrs p
    JOIN estadospqrs e ON e.idEstado = p.idEstado
    LEFT JOIN agencias  ag ON ag.idAgencia = p.idAgencia
    LEFT JOIN canales   c  ON c.idCanal    = p.idCanal
    LEFT JOIN tipospqrs t  ON t.idTipo     = p.idTipo
    WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
  ";

  $types = "ss";
  $vals  = [$ini,$fin];

  if ($idAgencia !== null && $idAgencia !== '') { $sql .= " AND p.idAgencia = ?"; $types .= "i"; $vals[] = (int)$idAgencia; }
  if ($idCanal   !== null && $idCanal   !== '') { $sql .= " AND p.idCanal   = ?"; $types .= "i"; $vals[] = (int)$idCanal; }
  if ($idTipo    !== null && $idTipo    !== '') { $sql .= " AND p.idTipo    = ?"; $types .= "i"; $vals[] = (int)$idTipo; }

  $sql .= "
    GROUP BY ag.idAgencia, ag.nombre, c.idCanal, c.nombre, t.idTipo, t.nombre
    ORDER BY ag.nombre, c.nombre, t.nombre
  ";

  $res = prep($con,$sql,$types,$vals)->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode($res);
  exit;
}

/* ===============================
   PQRs: Tablero por Responsable (con columnas por estado + SLA)
   ?op=pqrs_por_responsable&fechaInicio=YYYY-MM-DD&fechaFin=YYYY-MM-DD[&idResponsable=..&idAgencia=..&idCanal=..&idTipo=..]
   =============================== */
else if ($op === 'pqrs_por_responsable') {
  fechas($ini,$fin);

  $idResp   = param('idResponsable', null);
  $idAgencia= param('idAgencia', null);
  $idCanal  = param('idCanal',   null);
  $idTipo   = param('idTipo',    null);

  $sql = "
    SELECT
      pr.idResponsable,
      UPPER(COALESCE(CONCAT(TRIM(pe.nombres),' ',TRIM(pe.apellidos)),'(SIN RESPONSABLE)')) AS responsable,

      ag.idAgencia,
      UPPER(COALESCE(ag.nombre,'(SIN AGENCIA)')) AS agencia,
      c.idCanal,
      COALESCE(c.nombre,'(SIN CANAL)')           AS canal,
      t.idTipo,
      UPPER(COALESCE(t.nombre,'(SIN TIPO)'))     AS tipo,

      COUNT(*) AS total,

      SUM(CASE WHEN UPPER(e.nombre) IN ('ABIERTO','PENDIENTE') THEN 1 ELSE 0 END) AS abiertos,
      SUM(CASE WHEN UPPER(e.nombre) LIKE 'EN PROCESO%' THEN 1 ELSE 0 END)         AS en_proceso,
      SUM(CASE WHEN UPPER(e.nombre) LIKE 'ESCAL%'      THEN 1 ELSE 0 END)         AS escalados,
      SUM(CASE WHEN UPPER(e.nombre) IN ('CERRADO','RESUELTO') THEN 1 ELSE 0 END)  AS cerrados,

      SUM(CASE WHEN UPPER(e.nombre) NOT IN ('CERRADO','RESUELTO')
                AND p.fechaLimiteNivel IS NOT NULL
                AND NOW() >  p.fechaLimiteNivel THEN 1 ELSE 0 END) AS vencidos,
      SUM(CASE WHEN UPPER(e.nombre) NOT IN ('CERRADO','RESUELTO')
                AND p.fechaLimiteNivel IS NOT NULL
                AND NOW() <= p.fechaLimiteNivel THEN 1 ELSE 0 END) AS dentro_sla
    FROM pqrs p
    JOIN estadospqrs e        ON e.idEstado = p.idEstado
    JOIN pqrs_responsables pr ON pr.idPqrs  = p.idPqrs AND pr.nivel = p.nivelActual
    LEFT JOIN usuarios u      ON u.idUsuario = pr.idResponsable
    LEFT JOIN personas pe     ON pe.idPersona = u.idPersona
    LEFT JOIN agencias ag     ON ag.idAgencia = p.idAgencia
    LEFT JOIN canales  c      ON c.idCanal    = p.idCanal
    LEFT JOIN tipospqrs t     ON t.idTipo     = p.idTipo
    WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
  ";

  $types = "ss";
  $vals  = [$ini,$fin];

  if ($idResp   !== null && $idResp   !== '') { $sql .= " AND pr.idResponsable = ?"; $types.="i"; $vals[]=(int)$idResp; }
  if ($idAgencia!== null && $idAgencia!=='') { $sql .= " AND p.idAgencia     = ?"; $types.="i"; $vals[]=(int)$idAgencia; }
  if ($idCanal  !== null && $idCanal  !== '') { $sql .= " AND p.idCanal       = ?"; $types.="i"; $vals[]=(int)$idCanal; }
  if ($idTipo   !== null && $idTipo   !== '') { $sql .= " AND p.idTipo        = ?"; $types.="i"; $vals[]=(int)$idTipo; }

  $sql .= "
    GROUP BY
      pr.idResponsable, responsable,
      ag.idAgencia, ag.nombre,
      c.idCanal, c.nombre,
      t.idTipo, t.nombre
    ORDER BY responsable, agencia, canal, tipo
  ";

  $res = prep($con,$sql,$types,$vals)->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode($res);
  exit;
}



  // Resumen de encuestas
  else if ($op === 'encuestas_resumen') {
    fechas($ini,$fin);
    $sqlProg = "SELECT COUNT(*) tot FROM encuestasprogramadas ep WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?";
    $sqlEnv  = "SELECT COUNT(*) tot FROM encuestasprogramadas ep WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?";
    $sqlResp = "
      SELECT COUNT(DISTINCT ep.idProgEncuesta) tot
      FROM encuestasprogramadas ep JOIN respuestascliente rc ON rc.idProgEncuesta=ep.idProgEncuesta
      WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?";
    $prog = intval(prep($con,$sqlProg,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);
    $env  = intval(prep($con,$sqlEnv ,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);
    $resp = intval(prep($con,$sqlResp,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);
    $tasa = ($env>0)? round($resp*100.0/$env,2) : 0;
    echo json_encode(['programadas'=>$prog,'enviadas'=>$env,'respondidas'=>$resp,'tasa_pct'=>$tasa]);
    exit;
  }

  // // Distribución de calificaciones (no NPS, escala 1–10)
  // else if ($op === 'distribucion_calificaciones') {
  //   fechas($ini,$fin);
  //   $sql = "
  //     SELECT
  //       IFNULL(ROUND(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) *100.0 /
  //                    NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0),2),0) AS excelente_pct,
  //       IFNULL(ROUND(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 7 AND 8  THEN 1 ELSE 0 END) *100.0 /
  //                    NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0),2),0) AS bueno_pct,
  //       IFNULL(ROUND(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 5 AND 6  THEN 1 ELSE 0 END) *100.0 /
  //                    NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0),2),0) AS regular_pct
  //     FROM respuestascliente rc JOIN preguntas p ON p.idPregunta=rc.idPregunta
  //     WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?";
  //   echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_assoc());
  //   exit;
  // }

// Distribución de calificaciones (no NPS ni CES, escala 1–5)
else if ($op === 'distribucion_calificaciones') {
  fechas($ini,$fin);
  $sql = "
    SELECT
      IFNULL(ROUND(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico = 1 THEN 1 ELSE 0 END) * 100.0 /
                   NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 1 AND 5 THEN 1 ELSE 0 END),0), 2), 0) AS cal_1_pct,
      IFNULL(ROUND(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico = 2 THEN 1 ELSE 0 END) * 100.0 /
                   NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 1 AND 5 THEN 1 ELSE 0 END),0), 2), 0) AS cal_2_pct,
      IFNULL(ROUND(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico = 3 THEN 1 ELSE 0 END) * 100.0 /
                   NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 1 AND 5 THEN 1 ELSE 0 END),0), 2), 0) AS cal_3_pct,
      IFNULL(ROUND(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico = 4 THEN 1 ELSE 0 END) * 100.0 /
                   NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 1 AND 5 THEN 1 ELSE 0 END),0), 2), 0) AS cal_4_pct,
      IFNULL(ROUND(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico = 5 THEN 1 ELSE 0 END) * 100.0 /
                   NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 1 AND 5 THEN 1 ELSE 0 END),0), 2), 0) AS cal_5_pct
    FROM respuestascliente rc
    JOIN preguntas p ON p.idPregunta = rc.idPregunta
    WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
  ";
  echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_assoc());
  exit;
}


  // Conversión encuestas→PQRs
  else if ($op === 'encuestas_conversion') {
    fechas($ini,$fin);
    $sqlResp = "
      SELECT COUNT(DISTINCT ep.idProgEncuesta) tot
      FROM encuestasprogramadas ep JOIN respuestascliente rc ON rc.idProgEncuesta=ep.idProgEncuesta
      WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?";
    $resp = intval(prep($con,$sqlResp,'ss',[$ini,$fin])->get_result()->fetch_assoc()['tot'] ?? 0);

    $sqlGen = "
      SELECT IFNULL(SUM(CASE WHEN rc.generaPqr=1 THEN 1 ELSE 0 END),0) gen
      FROM respuestascliente rc JOIN encuestasprogramadas ep ON ep.idProgEncuesta=rc.idProgEncuesta
      WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?";
    $gen  = intval(prep($con,$sqlGen,'ss',[$ini,$fin])->get_result()->fetch_assoc()['gen'] ?? 0);

    $conv = ($resp>0)? round($gen*100.0/$resp,2) : 0;
    echo json_encode(['respondidas'=>$resp,'pqrs_generadas'=>$gen,'conversion_pct'=>$conv]);
    exit;
  }

  // -------------------------------------------------------
  // Segmentados: csat | nps | ces
  // -------------------------------------------------------

  // CSAT segmentado (% satisfechos)
  else if ($op === 'csat_segment') {
    fechas($ini,$fin);
    $segment = strtolower(param('segment','canal'));

    if ($segment === 'canal') {
      $sql = "
        SELECT c.nombre AS segmento,
               IFNULL(ROUND(
                 SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 4 AND 5 THEN 1 ELSE 0 END) * 100.0 /
                 NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico IS NOT NULL THEN 1 ELSE 0 END),0)
               ,2),0) AS csat
        FROM respuestascliente rc
        JOIN preguntas p             ON p.idPregunta = rc.idPregunta
        JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
        JOIN encuestas e             ON e.idEncuesta   = ep.idEncuesta
        JOIN canales c               ON c.idCanal      = e.idCanal
        WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
        GROUP BY c.nombre
        ORDER BY csat DESC";
      echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));

    } else if ($segment === 'agencia') {
      $sql = "
        SELECT COALESCE(ag.nombre,'(Sin agencia)') AS segmento,
               IFNULL(ROUND(
                 SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 4 AND 5 THEN 1 ELSE 0 END) * 100.0 /
                 NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico IS NOT NULL THEN 1 ELSE 0 END),0)
               ,2),0) AS csat
        FROM respuestascliente rc
        JOIN preguntas p             ON p.idPregunta = rc.idPregunta
        JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
        LEFT JOIN atenciones a       ON a.idAtencion = ep.idAtencion
        LEFT JOIN agencias ag        ON ag.idAgencia = a.idAgencia
        WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
        GROUP BY COALESCE(ag.nombre,'(Sin agencia)')
        ORDER BY csat DESC";
      echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));

    } else {
      http_response_code(400);
      echo json_encode(['error'=>'segment no soportado. Usa canal|agencia']);
    }
    exit;
  }

  // NPS segmentado (con breakdown)
  else if ($op === 'nps_segment') {
    fechas($ini,$fin);
    $segment = strtolower(param('segment','canal'));

    $tpl = "
      SELECT %SEG% AS segmento,
             ROUND((
               (SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
                SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END))
               * 100.0 / NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0)
             ), 2) AS nps,
             IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END) * 100.0 /
                          NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2),0) AS detractores_pct,
             IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 7 AND 8  THEN 1 ELSE 0 END) * 100.0 /
                          NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2),0) AS pasivos_pct,
             IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) * 100.0 /
                          NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2),0) AS promotores_pct
      FROM respuestascliente rc
      JOIN preguntas p             ON p.idPregunta = rc.idPregunta
      JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
      %JOIN%
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY 1
      ORDER BY nps DESC";

    if ($segment === 'canal') {
      $sql = str_replace(['%SEG%','%JOIN%'], ['c.nombre',
        "JOIN encuestas e ON e.idEncuesta = ep.idEncuesta
         JOIN canales c   ON c.idCanal    = e.idCanal"], $tpl);
      echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));

    } else if ($segment === 'agencia') {
      $sql = str_replace(['%SEG%','%JOIN%'], ["COALESCE(ag.nombre,'(Sin agencia)')",
        "LEFT JOIN atenciones a ON a.idAtencion = ep.idAtencion
         LEFT JOIN agencias ag  ON ag.idAgencia = a.idAgencia"], $tpl);
      echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));

    } else {
      http_response_code(400);
      echo json_encode(['error'=>'segment no soportado. Usa canal|agencia']);
    }
    exit;
  }

  // CES segmentado (promedio 1–5)
  else if ($op === 'ces_segment') {
    fechas($ini,$fin);
    $segment = strtolower(param('segment','canal'));

    if ($segment === 'canal') {
      $sql = "
        SELECT c.nombre AS segmento,
               IFNULL(ROUND(AVG(CASE WHEN p.esCes=1 THEN rc.valorNumerico END),2),0) AS ces
        FROM respuestascliente rc
        JOIN preguntas p             ON p.idPregunta = rc.idPregunta
        JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
        JOIN encuestas e             ON e.idEncuesta   = ep.idEncuesta
        JOIN canales c               ON c.idCanal      = e.idCanal
        WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
        GROUP BY c.nombre
        ORDER BY ces DESC";
      echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));

    } else if ($segment === 'agencia') {
      $sql = "
        SELECT COALESCE(ag.nombre,'(Sin agencia)') AS segmento,
               IFNULL(ROUND(AVG(CASE WHEN p.esCes=1 THEN rc.valorNumerico END),2),0) AS ces
        FROM respuestascliente rc
        JOIN preguntas p             ON p.idPregunta = rc.idPregunta
        JOIN encuestasprogramadas ep ON ep.idProgEncuesta = rc.idProgEncuesta
        LEFT JOIN atenciones a       ON a.idAtencion = ep.idAtencion
        LEFT JOIN agencias ag        ON ag.idAgencia = a.idAgencia
        WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
        GROUP BY COALESCE(ag.nombre,'(Sin agencia)')
        ORDER BY ces DESC";
      echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));

    } else {
      http_response_code(400);
      echo json_encode(['error'=>'segment no soportado. Usa canal|agencia']);
    }
    exit;
  }

  // -------------------------------------------------------
  // Series: csat | nps | pqrs | ces | correlación
  // -------------------------------------------------------

  else if ($op === 'csat_series') {
    fechas($ini,$fin);
    $periodo = param('period','M');
    [$grp,$label] = periodo_expr($periodo, 'rc.fechaRespuesta');

    $sql = "
      SELECT $label AS periodo,
             IFNULL(ROUND(
               SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 4 AND 5 THEN 1 ELSE 0 END) * 100.0 /
               NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico IS NOT NULL THEN 1 ELSE 0 END),0)
             ,2),0) AS csat
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY $grp
      ORDER BY MIN(rc.fechaRespuesta);
    ";
    echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  else if ($op === 'nps_series') {
    fechas($ini,$fin);
    $periodo = param('period','M');
    [$grp,$label] = periodo_expr($periodo, 'rc.fechaRespuesta');

    $sql = "
      SELECT
        $label AS periodo,
        ROUND((
          (SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) -
           SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END))
          * 100.0 / NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0)
        ), 2) AS nps,
        IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 6  THEN 1 ELSE 0 END) * 100.0 /
                     NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2),0) AS detractores_pct,
        IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 7 AND 8  THEN 1 ELSE 0 END) * 100.0 /
                     NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2),0) AS pasivos_pct,
        IFNULL(ROUND(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 9 AND 10 THEN 1 ELSE 0 END) * 100.0 /
                     NULLIF(SUM(CASE WHEN p.esNps=1 AND rc.valorNumerico BETWEEN 0 AND 10 THEN 1 ELSE 0 END),0), 2),0) AS promotores_pct
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY $grp
      ORDER BY MIN(rc.fechaRespuesta);
    ";
    echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

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
    echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  else if ($op === 'ces_series') {
    fechas($ini,$fin);
    $periodo = param('period','M');
    [$grp,$label] = periodo_expr($periodo, 'rc.fechaRespuesta');

    $sql = "
      SELECT $label AS periodo,
             IFNULL(ROUND(AVG(CASE WHEN p.esCes=1 THEN rc.valorNumerico END),2),0) AS ces
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY $grp
      ORDER BY MIN(rc.fechaRespuesta);
    ";
    echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  else if ($op === 'csat_pqrs_corr') {
    fechas($ini,$fin);
    $periodo = param('period','M');
    [$grp1,$label1] = periodo_expr($periodo, 'rc.fechaRespuesta');
    [$grp2,$label2] = periodo_expr($periodo, 'p.fechaCreacion');

    $sql1 = "
      SELECT $label1 AS periodo,
             IFNULL(ROUND(
               SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico BETWEEN 4 AND 5 THEN 1 ELSE 0 END) * 100.0 /
               NULLIF(SUM(CASE WHEN p.esNps=0 AND p.esCes=0 AND rc.valorNumerico IS NOT NULL THEN 1 ELSE 0 END),0)
             ,2),0) AS csat
      FROM respuestascliente rc
      JOIN preguntas p ON p.idPregunta = rc.idPregunta
      WHERE DATE(rc.fechaRespuesta) BETWEEN ? AND ?
      GROUP BY $grp1
    ";
    $s1 = prep($con, $sql1, 'ss', [$ini,$fin]);
    $csat = [];
    foreach ($s1->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $csat[$r['periodo']] = $r['csat'];

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

  // -------------------------------------------------------
  // PQRs por CATEGORÍA y por CATEGORÍA PADRE
  // -------------------------------------------------------

  else if ($op === 'pqrs_por_categoria') {
    fechas($ini,$fin);
    $sql = "
      SELECT COALESCE(concat(c.nombre,' - ', c.descripcion),'(Sin categoría)') AS categoria,
             COUNT(*) AS total
      FROM pqrs p
      LEFT JOIN categoriaspqrs c ON c.idCategoria = p.idCategoria
      WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
      GROUP BY COALESCE(c.nombre,'(Sin categoría)')
      ORDER BY total DESC
    ";
    echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  else if ($op === 'pqrs_por_categoria_padre') {
    fechas($ini,$fin);
    $sql = "
      SELECT COALESCE(concat(cp.nombre,' - ', cp.descripcion),'(Sin categoría padre)') AS categoria_padre,
             COUNT(*) AS total
      FROM pqrs p
      LEFT JOIN categoriaspqrs c   ON c.idCategoria = p.idCategoria
      LEFT JOIN categoriaspadre cp  ON cp.idCategoriaPadre = c.idCategoriaPadre
      WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
      GROUP BY COALESCE(cp.nombre,'(Sin categoría padre)')
      ORDER BY total DESC
    ";
    echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

  // (Opcional) PQRs por tipo - sólo si existe 'tipospqrs'
  else if ($op === 'pqrs_por_tipo') {
    if (!table_exists($con,'tipospqrs')) {
      echo json_encode(['error'=>"La tabla 'tipospqrs' no existe en la BD. Elimina este endpoint si no la usarás."]);
      exit;
    }
    fechas($ini,$fin);
    $sql = "
      SELECT t.nombre AS tipo, COUNT(*) AS total
      FROM pqrs p
      JOIN tipospqrs t ON t.idTipo = p.idTipo
      WHERE DATE(p.fechaCreacion) BETWEEN ? AND ?
      GROUP BY t.nombre
      ORDER BY total DESC
    ";
    echo json_encode(prep($con,$sql,'ss',[$ini,$fin])->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
  }

// ... arriba del cierre del switch/if grande

else if ($op === 'encuestas_matriz') {
  fechas($ini, $fin);

  $sql = "
    SELECT
      e.idEncuesta,
      e.nombre              AS encuesta,
      c.nombre              AS canal,
      ag.nombre             AS agencia,
      ep.estadoEnvio,
      COUNT(DISTINCT ep.idProgEncuesta) AS programadas,
      COUNT(DISTINCT CASE WHEN ep.estado = 1 THEN ep.idProgEncuesta END) AS enviadas,
      COUNT(DISTINCT rc.idProgEncuesta) AS respondidas,
      ROUND(
        COUNT(DISTINCT rc.idProgEncuesta) * 100.0 /
        NULLIF(COUNT(DISTINCT CASE WHEN ep.estado = 1 THEN ep.idProgEncuesta END), 0)
      , 2) AS tasa_pct
    FROM encuestasprogramadas ep
    JOIN encuestas  e  ON e.idEncuesta  = ep.idEncuesta
    JOIN canales    c  ON c.idCanal     = e.idCanal
    JOIN atenciones a  ON a.idAtencion  = ep.idAtencion
    JOIN agencias   ag ON ag.idAgencia  = a.idAgencia
    LEFT JOIN respuestascliente rc ON rc.idProgEncuesta = ep.idProgEncuesta
    WHERE DATE(ep.fechaProgramadaInicial) BETWEEN ? AND ?
    GROUP BY
      e.idEncuesta, e.nombre, c.nombre, ag.nombre, ep.estadoEnvio
    ORDER BY
      e.idEncuesta, e.nombre, c.nombre, ag.nombre, ep.estadoEnvio
  ";

  $res = prep($con, $sql, 'ss', [$ini, $fin])->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'idEncuesta'  => intval($r['idEncuesta']),
      'encuesta'    => $r['encuesta'] ?? '',
      'canal'       => $r['canal'] ?? '',
      'agencia'     => $r['agencia'] ?? '',
      'estadoEnvio' => $r['estadoEnvio'] ?? '',
      'programadas' => intval($r['programadas'] ?? 0),
      'enviadas'    => intval($r['enviadas'] ?? 0),
      'respondidas' => intval($r['respondidas'] ?? 0),
      'tasa_pct'    => floatval($r['tasa_pct'] ?? 0),
    ];
  }
  echo json_encode($out);
  exit;
}

  // Fallback
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


// Endpoints (resumen rápido)

// KPIs: ?op=kpis&fechaInicio=YYYY-MM-DD&fechaFin=YYYY-MM-DD

// PQRs por estado: ?op=pqrs_estado&...

// Encuestas por estado: ?op=encuestas_estado&...

// Tarjetas overview: ?op=cards_overview&...

// Tendencia sat+PQRs: ?op=tendencia_satisfaccion_pqrs&...

// Tasa de respuesta: ?op=tasa_respuesta&...

// Resúmenes: ?op=pqrs_resumen&..., ?op=encuestas_resumen&...

// Distribución calificaciones: ?op=distribucion_calificaciones&...

// Conversión encuestas→PQRs: ?op=encuestas_conversion&...

// Segmentos:

// CSAT: ?op=csat_segment&segment=canal|agencia&...

// NPS: ?op=nps_segment&segment=canal|agencia&...

// CES: ?op=ces_segment&segment=canal|agencia&...

// Series:

// CSAT: ?op=csat_series&period=M|W|Q|Y&...

// NPS: ?op=nps_series&period=M|W|Q|Y&...

// CES: ?op=ces_series&period=M|W|Q|Y&...

// PQRs: ?op=pqrs_series&period=M|W|Q|Y&...

// Correlación: ?op=csat_pqrs_corr&period=M|W|Q|Y&...

// Categorías:

// Por categoría: ?op=pqrs_por_categoria&...

// Por categoría padre: ?op=pqrs_por_categoria_padre&...

// (Opc.) Por tipo: ?op=pqrs_por_tipo&...