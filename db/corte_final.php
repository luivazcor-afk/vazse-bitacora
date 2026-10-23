<?php
/**
 * Corte final: pasa los datos MÁS RECIENTES de Google Sheets a MySQL.
 *
 *   1. Respalda la base actual (mysqldump) en C:\vazse-respaldos\
 *   2. Descarga todos los datos del Apps Script (la URL de config.json)
 *   3. Reemplaza viajes, cobranza, pagos, catálogos, cotizaciones y config.
 *      NO toca usuarios ni archivos subidos.
 *
 * Uso:  C:\xampp\php\php.exe C:\vazse-bitacora\db\corte_final.php [--simular]
 * Pide escribir SI para confirmar. Con --simular hace el respaldo y la
 * descarga pero NO importa (sirve para probar que todo funciona).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('America/Mexico_City');

$APP = dirname(__DIR__);
$RESPALDOS = 'C:/vazse-respaldos';
$PHP = PHP_BINARY;
$MYSQLDUMP = 'C:/xampp/mysql/bin/mysqldump.exe';
$sello = date('Y-m-d_His');
$simular = in_array('--simular', $argv, true);

function paso(string $m): void { echo "\n=== $m\n"; }
function falla(string $m): void { fwrite(STDERR, "\nERROR: $m\nNo se cambió nada en la base de datos.\n"); exit(1); }

$cfg = json_decode(file_get_contents("$APP/config.json"), true);
$url = $cfg['scriptUrl'] ?? '';
if (!preg_match('#^https://script\.google\.com/#', $url)) falla('config.json no tiene la URL del Apps Script (scriptUrl)');

if ($simular) {
  echo "MODO SIMULACIÓN: respalda y descarga, pero NO cambia los datos.\n";
} else {
  echo "Esto REEMPLAZA los datos de la Bitácora en MySQL con los de Google Sheets.\n";
  echo "Lo que se haya capturado en el sistema nuevo y no esté en el Sheet SE PIERDE\n";
  echo "(queda en el respaldo). Usuarios y archivos subidos no se tocan.\n\n";
  echo 'Escribe SI para continuar: ';
  if (strtoupper(trim(fgets(STDIN) ?: '')) !== 'SI') { echo "Cancelado.\n"; exit(0); }
}

// 1. Respaldo
paso('1/3 Respaldando la base actual');
if (!is_dir($RESPALDOS) && !mkdir($RESPALDOS, 0775, true)) falla("No se pudo crear $RESPALDOS");
$respaldo = "$RESPALDOS/antes_del_corte_$sello.sql";
$cmd = escapeshellarg($MYSQLDUMP) . ' -h 127.0.0.1 -u root --single-transaction --routines --triggers --databases vazse_bitacora --result-file=' . escapeshellarg($respaldo);
exec($cmd . ' 2>&1', $out, $code);
if ($code !== 0 || !filesize($respaldo)) falla("mysqldump falló: " . implode(' ', $out));
printf("Respaldo: %s (%s KB)\n", $respaldo, number_format(filesize($respaldo) / 1024));

// 2. Descarga
paso('2/3 Descargando datos de Google Sheets');
$ch = curl_init($url . '?v=1');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120,
  CURLOPT_CAINFO => 'C:/xampp/apache/bin/curl-ca-bundle.crt',
]);
$txt = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
if ($txt === false || $http !== 200) falla('No se pudo descargar del Apps Script: ' . (curl_error($ch) ?: "HTTP $http"));
if (!preg_match('/^\s*[A-Za-z_$][\w$]*\((.*)\)\s*;?\s*$/s', $txt, $m)) falla('El Apps Script no respondió en el formato esperado');
$datos = json_decode($m[1], true);
if (!is_array($datos) || isset($datos['error'])) falla('El Apps Script devolvió un error: ' . ($datos['error'] ?? 'JSON inválido'));
foreach (['registros', 'cobranzas', 'pagos'] as $k) {
  if (!isset($datos[$k]) || !is_array($datos[$k])) falla("Faltan los datos de \"$k\" en la descarga");
}
$json = "$RESPALDOS/sheet_$sello.json";
file_put_contents($json, json_encode($datos, JSON_UNESCAPED_UNICODE));
printf("Descargado: %d viajes, %d cobranzas, %d pagos, %d clientes\n",
  count($datos['registros']), count($datos['cobranzas']), count($datos['pagos']), count($datos['clientes'] ?? []));

if ($simular) {
  echo "\nSimulación terminada: respaldo y descarga OK. No se importó nada.\n";
  exit(0);
}

// 3. Importar (en una transacción: si algo falla no se cambia nada)
paso('3/3 Importando a MySQL');
passthru(escapeshellarg($PHP) . ' ' . escapeshellarg("$APP/db/importar_sheets.php") . ' ' . escapeshellarg($json) . ' --vaciar', $code);
if ($code !== 0) falla('La importación falló (ver mensaje arriba)');

echo "\nCorte terminado. Si algo salió mal, el respaldo para regresar está en:\n  $respaldo\n";
echo "(se restaura con:  C:\\xampp\\mysql\\bin\\mysql.exe -h 127.0.0.1 -u root < \"$respaldo\")\n";
