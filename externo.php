<?php
/**
 * Consulta los datos traídos del otro sistema de captura (tabla referencias_externas).
 *   GET externo.php?ref=1626GV0013   → {ok, filas:[{referencia, contenedor, cliente, peso, bultos, mercancia, bl,
 *                                                  naviera, tipo_contenedor, regimen}]}
 * Una referencia puede tener varios contenedores (una fila por cada uno). Requiere sesión.
 */

require __DIR__ . '/db/sesion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$yo = usuarioSesion();
if (!$yo || $yo['debe_cambiar']) {
  echo json_encode(['ok' => false, 'auth' => false, 'error' => 'Tu sesión expiró. Vuelve a iniciar sesión.']);
  exit;
}

// Misma normalización que tareas/sincronizar_externo.php
$ref = mb_strtoupper(preg_replace('/\s+/u', '', (string)($_GET['ref'] ?? '')));
if ($ref === '' || mb_strlen($ref) > 60) { echo json_encode(['ok' => true, 'filas' => []]); exit; }

$st = db()->prepare('SELECT referencia, contenedor, cliente, peso, bultos, mercancia, bl,
                            naviera, tipo_contenedor, regimen, sincronizado_en
                     FROM referencias_externas WHERE referencia = ? ORDER BY contenedor');
$st->execute([$ref]);
echo json_encode(['ok' => true, 'filas' => $st->fetchAll()], JSON_UNESCAPED_UNICODE);
