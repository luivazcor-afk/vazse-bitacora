<?php
/**
 * Importa a MySQL los datos descargados del Apps Script (Google Sheets).
 *
 * Uso (CLI):
 *   php db/importar_sheets.php ruta/sheet_data.json [--vaciar]
 *
 * --vaciar  borra los datos actuales de las tablas antes de importar.
 * Sin esa opción, aborta si alguna tabla ya tiene datos.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }

$archivo = $argv[1] ?? null;
$vaciar  = in_array('--vaciar', $argv, true);
if (!$archivo || !is_file($archivo)) exit("Uso: php importar_sheets.php sheet_data.json [--vaciar]\n");

$d = json_decode(file_get_contents($archivo), true, 512, JSON_THROW_ON_ERROR);

$pdo = new PDO('mysql:host=localhost;dbname=vazse_bitacora;charset=utf8mb4', 'root', '', [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$TZ = new DateTimeZone('America/Mexico_City');

// "2026-03-25T06:00:00.000Z" -> "2026-03-25" (fecha local México). '' -> null
function fecha($v) {
  global $TZ;
  if ($v === null || $v === '') return null;
  if (preg_match('/^\d{4}-\d\d-\d\d$/', $v)) return $v;
  return (new DateTimeImmutable($v))->setTimezone($TZ)->format('Y-m-d');
}
function num($v)  { return ($v === '' || $v === null) ? 0 : (float)$v; }
function txt($v)  { return $v === null ? '' : (string)$v; }
// Texto JSON de la celda; '' -> null. Falla si no es JSON válido.
function js($v) {
  if ($v === null || $v === '') return null;
  if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
  json_decode($v, false, 512, JSON_THROW_ON_ERROR);
  return $v;
}

$tablas = ['viajes', 'cobranza', 'pagos', 'clientes', 'proveedores_cat', 'servicios_cat', 'cotizaciones', 'config'];
foreach ($tablas as $t) {
  $n = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
  if ($n && !$vaciar) exit("La tabla $t ya tiene $n filas. Usa --vaciar para reemplazar.\n");
}

$mapas = [
  'viajes' => ['registros', fn($a) => [
    'fecha' => fecha($a[0]), 'tipo' => txt($a[1]), 'cliente' => txt($a[2]), 'unidad' => txt($a[3]),
    'origen' => txt($a[4]), 'destino' => txt($a[5]), 'costo' => num($a[6]), 'cobrado' => num($a[7]),
    'utilidad' => num($a[8]), 'referencia' => txt($a[9]), 'notas' => txt($a[10]), 'contenedor' => txt($a[11]),
    'patio' => txt($a[12]), 'maniobras' => num($a[13]), 'maniobras_cobrado' => num($a[14]),
    'maniobras_desc' => txt($a[15]), 'url_eir' => txt($a[16]), 'estado_flete' => txt($a[17]),
    'usuario' => txt($a[18] ?? ''),
  ]],
  'cobranza' => ['cobranzas', fn($a) => [
    'folio' => txt($a[0]), 'cliente' => txt($a[1]), 'monto' => num($a[2]),
    'fecha_emision' => fecha($a[3]), 'fecha_vencimiento' => fecha($a[4]), 'estado' => txt($a[5]),
    'referencia' => txt($a[6]), 'url_factura' => txt($a[7]), 'url_evidencia' => txt($a[8]),
    'notas' => txt($a[9]), 'fecha_cobro' => fecha($a[10]), 'seguimiento' => js($a[11] ?? ''),
    'abonos' => js($a[12] ?? ''), 'usuario' => txt($a[13] ?? ''),
  ]],
  'pagos' => ['pagos', fn($a) => [
    'folio' => txt($a[0]), 'proveedor' => txt($a[1]), 'monto' => num($a[2]), 'fecha' => fecha($a[3]),
    'fecha_pago' => fecha($a[4]), 'estado' => txt($a[5]), 'referencia' => txt($a[6]),
    'concepto' => txt($a[7]), 'notas' => txt($a[8]), 'usuario' => txt($a[9] ?? ''),
  ]],
  'clientes' => ['clientes', fn($a) => [
    'nombre' => txt($a[0]), 'rfc' => txt($a[1]), 'contacto' => txt($a[2]), 'tel' => txt($a[3]),
    'email' => txt($a[4]), 'notas' => txt($a[5]), 'domicilios' => js($a[6] ?? ''),
  ]],
  'proveedores_cat' => ['proveedores', fn($a) => [
    'nombre' => txt($a[0]), 'rfc' => txt($a[1]), 'tipo' => txt($a[2]), 'contacto' => txt($a[3]),
    'tel1' => txt($a[4]), 'tel2' => txt($a[5]), 'email1' => txt($a[6]), 'email2' => txt($a[7]),
    'cuentas' => js($a[8] ?? ''), 'notas' => txt($a[9] ?? ''),
  ]],
  'servicios_cat' => ['servicios', fn($a) => [
    'nombre' => txt($a[0]), 'clave_sat' => txt($a[1]), 'unidad_sat' => txt($a[2]),
    'descripcion' => txt($a[3]), 'retencion' => ($a[4] ?? '') === 'si' ? 'si' : 'no',
  ]],
  'cotizaciones' => ['cotizaciones', fn($a) => [
    'folio' => txt($a[0]), 'fecha' => fecha($a[1]), 'cliente' => txt($a[2]), 'unidad' => txt($a[3]),
    'unidad_nombre' => txt($a[4]), 'origen' => txt($a[5]), 'destino' => txt($a[6]), 'km' => num($a[7]),
    'redondo' => txt($a[8]), 'casetas' => num($a[9]), 'maniobras' => num($a[10]), 'otros' => num($a[11]),
    'costo' => num($a[12]), 'precio' => num($a[13]), 'margen' => num($a[14]), 'estado' => txt($a[15]),
    'vigencia' => (int)num($a[16]), 'notas' => txt($a[17] ?? ''),
  ]],
  'config' => ['config', fn($a) => ['clave' => txt($a[0]), 'valor' => txt($a[1] ?? '')]],
];

$pdo->beginTransaction();
try {
  if ($vaciar) foreach ($tablas as $t) $pdo->exec("DELETE FROM $t");
  foreach ($mapas as $tabla => [$hoja, $mapear]) {
    $filas = $d[$hoja] ?? [];
    $n = 0;
    foreach ($filas as $i => $a) {
      $fila = $mapear($a);
      $cols = array_keys($fila);
      $sql = "INSERT INTO $tabla (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
      try {
        $pdo->prepare($sql)->execute(array_values($fila));
      } catch (Throwable $e) {
        throw new RuntimeException("$tabla fila " . ($i + 1) . ': ' . $e->getMessage(), 0, $e);
      }
      $n++;
    }
    printf("%-16s %3d filas\n", $tabla, $n);
  }
  $pdo->commit();
  echo "Importación completa.\n";
} catch (Throwable $e) {
  $pdo->rollBack();
  fwrite(STDERR, "ERROR — no se importó nada: " . $e->getMessage() . "\n");
  exit(1);
}
