<?php
// controllers/security_link.php
// Requiere: define('APP_HMAC_SECRET', '...') en tu config segura

// Base64 URL-safe
function b64u_enc(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64u_dec(string $txt): string {
  $pad = strlen($txt) % 4;
  if ($pad) $txt .= str_repeat('=', 4 - $pad);
  return base64_decode(strtr($txt, '-_', '+/'));
}

/**
 * Genera token firmado con HMAC (sin librerías externas)
 * payload: ['pid'=>int, 'em'=>hash email, 'iat'=>time(), 'exp'=>time()+...]
 */
function make_survey_token(int $idProgEncuesta, string $email, int $ttlSeconds = 7*24*3600): string {
  $now = time();
  $payload = [
    'pid' => $idProgEncuesta,                // programacion_encuesta.id
    'em'  => hash('sha256', strtolower(trim($email))), // no exponer email
    'iat' => $now,
    'exp' => $now + $ttlSeconds
  ];
  $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
  $p = b64u_enc($payloadJson);
  $sig = hash_hmac('sha256', $p, APP_HMAC_SECRET, true);
  $s = b64u_enc($sig);
  return $p.'.'.$s; // formato: payload.signature (ambos base64url)
}

/** Valida token y devuelve payload (array) o false */
function verify_survey_token(string $token, ?string $emailParaVerificacion = null) {
  $parts = explode('.', $token, 2);
  if (count($parts) !== 2) return false;
  [$p, $s] = $parts;

  $sigOk = hash_equals(
    b64u_dec($s),
    hash_hmac('sha256', $p, APP_HMAC_SECRET, true)
  );
  if (!$sigOk) return false;

  $payload = json_decode(b64u_dec($p), true);
  if (!is_array($payload)) return false;
  if (empty($payload['pid']) || empty($payload['exp'])) return false;
  if ($payload['exp'] < time()) return false;

  // (opcional) si quieres “atarlo” al email del cliente:
  if ($emailParaVerificacion) {
    $hashEsperado = hash('sha256', strtolower(trim($emailParaVerificacion)));
    if (!hash_equals($hashEsperado, (string)$payload['em'])) return false;
  }
  return $payload;
}

/** Construye el link final del frontend con token */
function build_survey_link(string $token): string {
  // Si tu Angular está en el mismo host/puerto:
  $frontend = baseUrlFrontend(); // la que ya usas; p.ej. http://localhost:4200
  return rtrim($frontend, '/').'/encuesta-cliente/enc/'.rawurlencode($token);
}


// security_link.php
function build_consent_token(int $idCliente, int $idProgEncuesta, string $email=''): string {
  $secret = getenv('APP_SECRET') ?: 'cambia-esto-en-produccion';
  $payload = [
    'cid' => $idCliente,          // cliente
    'pid' => $idProgEncuesta,     // programación
    'em'  => hash('sha256', strtolower(trim($email))), // huella (no el email en claro)
    'iat' => time()
    // sin exp: la “firma” no expira, a diferencia del link
  ];
  $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
  $sig  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
  return $body.'.'.$sig;
}
