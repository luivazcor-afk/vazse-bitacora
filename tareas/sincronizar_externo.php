<?php
/**
 * Trae del otro sistema de captura: referencia, cliente, contenedor, peso,
 * bultos, mercancía y BL, y los guarda en la tabla referencias_externas.
 *
 * Solo LEE del otro sistema (usuario de solo lectura + sesión READ ONLY).
 * Agrega y actualiza; no borra lo que ya se había traído.
 * La conexión y la consulta se configuran en db/config.servidor.php → 'externo'.
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
function limpiar($v, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max); }

try {
  $cfg = cargarConfigServidor();
  $ext = $cfg['externo'] ?? [];
  if (empty($ext['host'])) { logTarea($T, 'Sin configurar (externo.host vacío) — no se hace nada'); exit(0); }

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

  $filas = $origen->query($consulta)->fetchAll();
  if ($filas) {
    $faltan = array_diff(COLUMNAS, array_keys($filas[0]));
    if ($faltan) throw new RuntimeException('A la consulta le faltan las columnas: ' . implode(', ', $faltan) . ' (usa alias: AS referencia, AS cliente, ...)');
  }

  $datos = [];
  $sinRef = 0;
  foreach ($filas as $f) {
    $ref = normalizarClave($f['referencia']);
    if ($ref === '') { $sinRef++; continue; }
    $d = ['referencia' => mb_substr($ref, 0, 60), 'contenedor' => mb_substr(normalizarClave($f['contenedor']), 0, 30)];
    foreach (['cliente', 'peso', 'bultos', 'mercancia', 'bl'] as $c) $d[$c] = limpiar($f[$c], LIMITES[$c]);
    $datos[$d['referencia'] . '|' . $d['contenedor']] = $d;   // si viene repetida, gana la última
  }

  if (modoPrueba()) {
    logTarea($T, sprintf('[prueba] Conexión OK. La consulta devolvió %d fila(s) (%d sin referencia). Primeras 5:', count($filas), $sinRef));
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
