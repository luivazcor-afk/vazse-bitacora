<?php
/**
 * Trae del otro sistema de captura: referencia, cliente, contenedor, peso,
 * bultos, mercancía y BL, y los guarda en la tabla referencias_externas.
 *
 * Dos formas de leer (db/config.servidor.php → 'externo' → 'tipo'):
 *   'api' — GET a la API del otro sistema con una clave (token); responde
 *           {"filas":[{referencia, cliente, contenedor, peso, bultos, mercancia, bl}, ...]}
 *   'bd'  — consulta directa a su MariaDB con un usuario de solo lectura.
 * Solo LEE del otro sistema. Agrega y actualiza; no borra lo que ya se había traído.
 *
 * Uso: php tareas/sincronizar_externo.php [--prueba]
 *   --prueba  se conecta, ejecuta la consulta y muestra 5 filas, sin guardar nada
 */

require __DIR__ . '/comun.php';

$T = 'externo';
const COLUMNAS = ['referencia', 'cliente', 'contenedor', 'peso', 'bultos', 'mercancia', 'bl'];
const LIMITES = ['referencia' => 60, 'cliente' => 150, 'contenedor' => 30, 'peso' => 30, 'bultos' => 30, 'mercancia' => 500, 'bl' => 60];

// Referencias y contenedores se comparan sin espacios y en mayúsculas
function normalizarClave($v): string { return mb_strtoupper(preg_replace('/\s+/u', '', (string)$v)); }
function limpiar($v, int $max): string {
  if (!is_scalar($v)) return '';   // de una API puede llegar null, arreglo, etc.
  return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max);
}

// API del otro sistema: GET url con "Authorization: Bearer <token>"
function leerDeApi(array $ext): array {
  $url = (string)($ext['url'] ?? '');
  if (!preg_match('#^https?://#i', $url)) throw new RuntimeException("Falta 'url' de la API en la configuración 'externo'");
  if (($ext['token'] ?? '') === '') throw new RuntimeException("Falta 'token' de la API en la configuración 'externo'");
  if (preg_match('#^http://#i', $url) && !preg_match('#^http://(127\.0\.0\.1|localhost)[:/]#i', $url)) {
    throw new RuntimeException('Por seguridad la API debe usar https:// (la clave no debe viajar sin cifrar por la red)');
  }
  $verificar = $ext['verificar_certificado'] ?? true;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $ext['token'], 'Accept: application/json'],
    // El token no debe viajar sin cifrar fuera de esta PC
    CURLOPT_PROTOCOLS => preg_match('#^http://(127\.0\.0\.1|localhost)[:/]#i', $url) ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS,
    CURLOPT_SSL_VERIFYPEER => $verificar, CURLOPT_SSL_VERIFYHOST => $verificar ? 2 : 0,
  ]);
  if (is_file('C:/xampp/apache/bin/curl-ca-bundle.crt')) curl_setopt($ch, CURLOPT_CAINFO, 'C:/xampp/apache/bin/curl-ca-bundle.crt');
  $txt = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  if ($txt === false) throw new RuntimeException("No se pudo conectar a la API: $err");
  if ($http === 401 || $http === 403) throw new RuntimeException("La API rechazó la clave (HTTP $http): revisa 'token'");
  if ($http !== 200) throw new RuntimeException("La API respondió HTTP $http");
  $r = json_decode($txt, true);
  if (!is_array($r) || !isset($r['filas']) || !is_array($r['filas'])) throw new RuntimeException('La API no respondió {"filas":[...]}');
  return $r['filas'];
}

// Consulta directa a la MariaDB del otro sistema (usuario de solo lectura)
function leerDeBd(array $ext): array {
  $consulta = trim((string)($ext['consulta'] ?? ''));
  if ($consulta === '') throw new RuntimeException("Falta 'consulta' en la configuración 'externo'");
  if (!preg_match('/^\s*(SELECT|WITH)\b/i', $consulta) || preg_match('/;\s*\S/', $consulta)) {
    throw new RuntimeException('La consulta debe ser un solo SELECT');
  }

  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $ext['host'], (int)($ext['puerto'] ?? 3306), $ext['base'] ?? '');
  $origen = new PDO($dsn, $ext['usuario'] ?? '', $ext['password'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 15,
  ]);
  // Segunda protección además del usuario de solo lectura
  $origen->exec('SET SESSION TRANSACTION READ ONLY');

  return $origen->query($consulta)->fetchAll();
}

try {
  $cfg = cargarConfigServidor();
  $ext = $cfg['externo'] ?? [];
  $tipo = $ext['tipo'] ?? (empty($ext['url']) ? 'bd' : 'api');
  if (empty($ext['url']) && empty($ext['host'])) { logTarea($T, 'Sin configurar (externo.url / externo.host vacíos) — no se hace nada'); exit(0); }

  $filas = array_values($tipo === 'api' ? leerDeApi($ext) : leerDeBd($ext));
  if ($filas) {
    if (!is_array($filas[0])) throw new RuntimeException('Cada fila debe ser un objeto con las 7 columnas');
    $faltan = array_diff(COLUMNAS, array_keys($filas[0]));
    if ($faltan) throw new RuntimeException('Faltan las columnas: ' . implode(', ', $faltan) . ' (deben llamarse referencia, cliente, contenedor, peso, bultos, mercancia, bl)');
  }

  $datos = [];
  $sinRef = 0;
  foreach ($filas as $f) {
    if (!is_array($f)) { $sinRef++; continue; }
    $ref = normalizarClave(limpiar($f['referencia'] ?? '', 200));
    if ($ref === '') { $sinRef++; continue; }
    $d = ['referencia' => mb_substr($ref, 0, 60), 'contenedor' => mb_substr(normalizarClave(limpiar($f['contenedor'] ?? '', 200)), 0, 30)];
    foreach (['cliente', 'peso', 'bultos', 'mercancia', 'bl'] as $c) $d[$c] = limpiar($f[$c] ?? '', LIMITES[$c]);
    $datos[$d['referencia'] . '|' . $d['contenedor']] = $d;   // si viene repetida, gana la última
  }

  if (modoPrueba()) {
    logTarea($T, sprintf('[prueba] Conexión OK (%s). Devolvió %d fila(s) (%d sin referencia). Primeras 5:', $tipo === 'api' ? 'API' : 'base de datos', count($filas), $sinRef));
    foreach (array_slice(array_values($datos), 0, 5) as $d) echo '   ' . json_encode($d, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
  }

  $st = db()->prepare('INSERT INTO referencias_externas (referencia, contenedor, cliente, peso, bultos, mercancia, bl)
                       VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE cliente = VALUES(cliente), peso = VALUES(peso), bultos = VALUES(bultos),
                         mercancia = VALUES(mercancia), bl = VALUES(bl)');
  db()->beginTransaction();
  $nuevas = $cambiadas = 0;
  foreach ($datos as $d) {
    $st->execute([$d['referencia'], $d['contenedor'], $d['cliente'], $d['peso'], $d['bultos'], $d['mercancia'], $d['bl']]);
    // rowCount: 1 = insertada, 2 = actualizada, 0 = sin cambios
    if ($st->rowCount() === 1) $nuevas++; elseif ($st->rowCount() === 2) $cambiadas++;
  }
  db()->commit();
  logTarea($T, sprintf('%d fila(s) leídas: %d nuevas, %d actualizadas, %d sin cambios%s',
    count($datos), $nuevas, $cambiadas, count($datos) - $nuevas - $cambiadas, $sinRef ? " ($sinRef sin referencia, ignoradas)" : ''));
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  logTarea($T, 'ERROR: ' . $e->getMessage());
  exit(1);
}
