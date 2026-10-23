<?php
/**
 * API Bitácora VAZSE — reemplazo en PHP/MySQL del Apps Script v10.
 *
 * Mantiene el mismo contrato que usaba la app con Google Sheets:
 *   GET api.php?v=1                        → cb({registros:[...], cobranzas:[...], ...})
 *   GET api.php?action=write&data={...}&cb=X → X({ok:true, ...})
 *   POST api.php  (body JSON)               → {ok:true, ...}
 *
 * Las filas se devuelven como arrays en el mismo orden de columnas del
 * Sheet, y las fechas como medianoche de México en ISO UTC, para que
 * normalizarDatosV30() del frontend funcione sin cambios.
 */

require __DIR__ . '/db/sesion.php';

const TZ_MX = 'America/Mexico_City';

header('Cache-Control: no-store');

// ══════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════

// Como parseFloat() de JS: "12abc" → 12, "" → 0
function num($v): float {
  if (is_int($v) || is_float($v)) return (float)$v;
  return is_string($v) ? floatval(trim($v)) : 0.0;
}
function txt($v): string {
  if ($v === null) return '';
  if (is_bool($v)) return $v ? 'true' : 'false';
  if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
  return (string)$v;
}
// Texto JSON para columnas JSON (igual que v10: '' → '[]').
function jsonCol($v): string {
  if ($v === null || $v === '' || $v === false) return '[]';
  if (is_string($v)) {
    json_decode($v);
    return json_last_error() === JSON_ERROR_NONE ? $v : json_encode([$v], JSON_UNESCAPED_UNICODE);
  }
  return json_encode($v, JSON_UNESCAPED_UNICODE);
}
// Entrada → 'YYYY-MM-DD' (fecha local México) o null. Equivale a normFecha() de v10.
function fechaIn($v): ?string {
  if ($v === null || $v === '') return null;
  $s = trim((string)$v);
  if (preg_match('/^\d{4}-\d\d-\d\d$/', $s)) return $s;
  try {
    return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone(TZ_MX))->format('Y-m-d');
  } catch (Exception $e) {
    return null;
  }
}
// 'YYYY-MM-DD' → '2026-03-25T06:00:00.000Z' (como lo devolvía Google Sheets)
function fechaOut(?string $d): string {
  if (!$d) return '';
  $dt = new DateTimeImmutable($d . ' 00:00:00', new DateTimeZone(TZ_MX));
  return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
}
// Número de BD → int si es entero (Sheets devolvía 928, no "928.00")
function n($v) {
  $f = (float)$v;
  return floor($f) == $f && abs($f) < 1e15 ? (int)$f : $f;
}
// Igual que Sheets: un valor puramente numérico se devuelve como número.
function celda(string $v) {
  return preg_match('/^-?\d{1,15}$/', $v) ? (int)$v : $v;
}

function q(string $sql, array $params = []): PDOStatement {
  $st = db()->prepare($sql);
  $st->execute($params);
  return $st;
}

function responder($data, ?string $callback): void {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($callback !== null) {
    header('Content-Type: text/javascript; charset=utf-8');
    echo $callback . '(' . $json . ')';
  } else {
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
  }
  exit;
}

// ══════════════════════════════════════════════════════════════════
//  LECTURA — mismas columnas que las hojas del Sheet
// ══════════════════════════════════════════════════════════════════

function leerTodo(): array {
  $registros = array_map(fn($r) => [
    fechaOut($r['fecha']), $r['tipo'], $r['cliente'], $r['unidad'], $r['origen'], $r['destino'],
    n($r['costo']), n($r['cobrado']), n($r['utilidad']), $r['referencia'], $r['notas'] ?? '',
    $r['contenedor'], $r['patio'], n($r['maniobras']), n($r['maniobras_cobrado']),
    $r['maniobras_desc'], $r['url_eir'], $r['estado_flete'], $r['usuario'],
  ], q('SELECT * FROM viajes ORDER BY id')->fetchAll());

  $cobranzas = array_map(fn($r) => [
    $r['folio'], $r['cliente'], n($r['monto']), fechaOut($r['fecha_emision']),
    fechaOut($r['fecha_vencimiento']), $r['estado'], $r['referencia'], $r['url_factura'],
    $r['url_evidencia'], $r['notas'] ?? '', fechaOut($r['fecha_cobro']),
    $r['seguimiento'] ?? '[]', $r['abonos'] ?? '[]', $r['usuario'],
  ], q('SELECT * FROM cobranza ORDER BY id')->fetchAll());

  $pagos = array_map(fn($r) => [
    $r['folio'], $r['proveedor'], n($r['monto']), fechaOut($r['fecha']), fechaOut($r['fecha_pago']),
    $r['estado'], $r['referencia'], $r['concepto'], $r['notas'] ?? '', $r['usuario'],
  ], q('SELECT * FROM pagos ORDER BY id')->fetchAll());

  $clientes = array_map(fn($r) => [
    $r['nombre'], $r['rfc'], $r['contacto'], celda($r['tel']), $r['email'], $r['notas'] ?? '',
    $r['domicilios'] ?? '[]',
  ], q('SELECT * FROM clientes ORDER BY id')->fetchAll());

  $proveedores = array_map(fn($r) => [
    $r['nombre'], $r['rfc'], $r['tipo'], $r['contacto'], celda($r['tel1']), celda($r['tel2']),
    $r['email1'], $r['email2'], $r['cuentas'] ?? '[]', $r['notas'] ?? '',
  ], q('SELECT * FROM proveedores_cat ORDER BY id')->fetchAll());

  $servicios = array_map(fn($r) => [
    $r['nombre'], celda($r['clave_sat']), $r['unidad_sat'], $r['descripcion'], $r['retencion'],
  ], q('SELECT * FROM servicios_cat ORDER BY id')->fetchAll());

  $cotizaciones = array_map(fn($r) => [
    $r['folio'], fechaOut($r['fecha']), $r['cliente'], $r['unidad'], $r['unidad_nombre'],
    $r['origen'], $r['destino'], n($r['km']), $r['redondo'], n($r['casetas']), n($r['maniobras']),
    n($r['otros']), n($r['costo']), n($r['precio']), n($r['margen']), $r['estado'],
    (int)$r['vigencia'], $r['notas'] ?? '',
  ], q('SELECT * FROM cotizaciones ORDER BY id')->fetchAll());

  $config = array_map(fn($r) => [$r['clave'], $r['valor'] ?? ''],
    q('SELECT clave, valor FROM config ORDER BY id')->fetchAll());

  return compact('registros', 'cobranzas', 'pagos', 'clientes', 'proveedores',
                 'servicios', 'cotizaciones', 'config');
}

// ══════════════════════════════════════════════════════════════════
//  CONSTRUCTORES DE FILA (equivalen a filaBitacora(), filaCobranza(), ...)
// ══════════════════════════════════════════════════════════════════

function filaViaje(array $r): array {
  $costo = num($r['costo'] ?? 0);
  $cobrado = num($r['cobrado'] ?? 0);
  $utilidad = (isset($r['utilidad']) && $r['utilidad'] !== '') ? num($r['utilidad']) : $cobrado - $costo;
  return [
    'fecha' => fechaIn($r['fecha'] ?? ''), 'tipo' => txt($r['tipo'] ?? ''),
    'cliente' => txt($r['cliente'] ?? ''), 'unidad' => txt($r['unidad'] ?? ''),
    'origen' => txt($r['origen'] ?? ''), 'destino' => txt($r['destino'] ?? ''),
    'costo' => $costo, 'cobrado' => $cobrado, 'utilidad' => $utilidad,
    'referencia' => txt($r['referencia'] ?? ''), 'notas' => txt($r['notas'] ?? ''),
    'contenedor' => txt($r['contenedor'] ?? ''), 'patio' => txt($r['patio'] ?? ''),
    'maniobras' => num($r['maniobras'] ?? 0), 'maniobras_cobrado' => num($r['maniobrasCobrado'] ?? 0),
    'maniobras_desc' => txt($r['maniobrasDesc'] ?? ''), 'url_eir' => txt($r['urlEir'] ?? ''),
    'estado_flete' => txt($r['estadoFlete'] ?? ''), 'usuario' => txt($r['usuario'] ?? ''),
  ];
}

function filaCobranza(array $c): array {
  return [
    'folio' => txt($c['folio'] ?? ''), 'cliente' => txt($c['cliente'] ?? ''),
    'monto' => num($c['monto'] ?? 0), 'fecha_emision' => fechaIn($c['fechaEmision'] ?? ''),
    'fecha_vencimiento' => fechaIn($c['fechaVencimiento'] ?? ''),
    'estado' => txt(($c['estado'] ?? '') ?: 'pendiente'), 'referencia' => txt($c['referencia'] ?? ''),
    'url_factura' => txt($c['urlFactura'] ?? ''), 'url_evidencia' => txt($c['urlEvidencia'] ?? ''),
    'notas' => txt($c['notas'] ?? ''), 'fecha_cobro' => fechaIn($c['fechaCobro'] ?? ''),
    'seguimiento' => jsonCol($c['seguimiento'] ?? ''), 'abonos' => jsonCol($c['abonos'] ?? ''),
    'usuario' => txt($c['usuario'] ?? ''),
  ];
}

function filaPago(array $p): array {
  return [
    'folio' => txt($p['folio'] ?? ''), 'proveedor' => txt($p['proveedor'] ?? ''),
    'monto' => num($p['monto'] ?? 0), 'fecha' => fechaIn($p['fecha'] ?? ''),
    'fecha_pago' => fechaIn($p['fechaPago'] ?? ''), 'estado' => txt(($p['estado'] ?? '') ?: 'pendiente'),
    'referencia' => txt($p['referencia'] ?? ''), 'concepto' => txt($p['concepto'] ?? ''),
    'notas' => txt($p['notas'] ?? ''), 'usuario' => txt($p['usuario'] ?? ''),
  ];
}

function filaCotizacion(array $c): array {
  return [
    'folio' => txt($c['folio'] ?? ''), 'fecha' => fechaIn($c['fecha'] ?? ''),
    'cliente' => txt($c['cliente'] ?? ''), 'unidad' => txt($c['unidad'] ?? ''),
    'unidad_nombre' => txt($c['unidadNombre'] ?? ''), 'origen' => txt($c['origen'] ?? ''),
    'destino' => txt($c['destino'] ?? ''), 'km' => num($c['km'] ?? 0),
    'redondo' => txt(($c['redondo'] ?? '') ?: 'sencillo'), 'casetas' => num($c['casetas'] ?? 0),
    'maniobras' => num($c['maniobras'] ?? 0), 'otros' => num($c['otros'] ?? 0),
    'costo' => num($c['costo'] ?? 0), 'precio' => num($c['precio'] ?? 0),
    'margen' => num($c['margen'] ?? 0), 'estado' => txt(($c['estado'] ?? '') ?: 'borrador'),
    'vigencia' => (int)(num($c['vigencia'] ?? 0) ?: 15), 'notas' => txt($c['notas'] ?? ''),
  ];
}

// Catálogo → [tabla, fila]
function filaCatalogo(?string $catalogo, array $e): ?array {
  if ($catalogo === 'clientes') return ['clientes', [
    'nombre' => txt($e['nombre'] ?? ''), 'rfc' => txt($e['rfc'] ?? ''),
    'contacto' => txt($e['contacto'] ?? ''), 'tel' => txt($e['tel'] ?? ''),
    'email' => txt($e['email'] ?? ''), 'notas' => txt($e['notas'] ?? ''),
    'domicilios' => jsonCol($e['domicilios'] ?? ''),
  ]];
  if ($catalogo === 'proveedores' || $catalogo === 'proveedores-cat') return ['proveedores_cat', [
    'nombre' => txt($e['nombre'] ?? ''), 'rfc' => txt($e['rfc'] ?? ''), 'tipo' => txt($e['tipo'] ?? ''),
    'contacto' => txt($e['contacto'] ?? ''), 'tel1' => txt($e['tel1'] ?? ''), 'tel2' => txt($e['tel2'] ?? ''),
    'email1' => txt($e['email1'] ?? ''), 'email2' => txt($e['email2'] ?? ''),
    'cuentas' => jsonCol($e['cuentas'] ?? ''), 'notas' => txt($e['notas'] ?? ''),
  ]];
  if ($catalogo === 'servicios') return ['servicios_cat', [
    'nombre' => txt($e['nombre'] ?? ''), 'clave_sat' => txt($e['clave'] ?? ''),
    'unidad_sat' => txt($e['unidad'] ?? ''), 'descripcion' => txt($e['desc'] ?? ''),
    'retencion' => ($e['retencion'] ?? 'no') === 'si' ? 'si' : 'no',
  ]];
  return null;
}

function insertar(string $tabla, array $fila): void {
  $cols = array_keys($fila);
  q("INSERT INTO $tabla (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
    array_values($fila));
}
function actualizar(string $tabla, array $fila, int $id): void {
  $sets = implode(',', array_map(fn($c) => "$c = ?", array_keys($fila)));
  q("UPDATE $tabla SET $sets WHERE id = ?", [...array_values($fila), $id]);
}
function idPorClave(string $tabla, string $col, $valor): ?int {
  if ($valor === null || $valor === '') return null;
  $id = q("SELECT id FROM $tabla WHERE TRIM($col) = ? ORDER BY id LIMIT 1", [trim((string)$valor)])->fetchColumn();
  return $id === false ? null : (int)$id;
}

// COB-2026-0001 … (igual que generarFolio() de v10)
function generarFolio(): string {
  $prefix = 'COB-' . date('Y') . '-';
  $max = (int)q("SELECT MAX(CAST(SUBSTRING(folio, ?) AS UNSIGNED)) FROM cobranza WHERE folio LIKE ?",
    [strlen($prefix) + 1, $prefix . '%'])->fetchColumn();
  return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

// ══════════════════════════════════════════════════════════════════
//  ROUTER DE ESCRITURA — mismas 16 acciones que v10
// ══════════════════════════════════════════════════════════════════

function procesarEscritura(array $p): array {
  $action = $p['action'] ?? '';
  $u = txt($p['usuario'] ?? '');

  switch ($action) {

    // ─────────── VIAJES (Bitácora) ───────────
    case 'append':
      insertar('viajes', filaViaje($p['registro'] ?? []));
      return ['ok' => true, 'action' => $action];

    case 'update_viaje': {
      $r = $p['registro'] ?? [];
      // Se busca por la referencia VIEJA (p.referencia); la nueva va en r.referencia
      $refViejo = trim(txt($p['referencia'] ?? ($r['referencia'] ?? '')));
      if ($refViejo === '') return ['ok' => false, 'error' => 'Referencia vacía — no se puede ubicar el viaje'];
      $matches = q('SELECT id, fecha, tipo FROM viajes WHERE TRIM(referencia) = ? ORDER BY id', [$refViejo])->fetchAll();
      if (!$matches) return ['ok' => false, 'error' => "Viaje no encontrado (ref $refViejo)"];
      $id = (int)$matches[0]['id'];
      if (count($matches) > 1) {
        $f = fechaIn($r['fecha'] ?? '');
        $afinados = array_values(array_filter($matches, fn($m) => $m['fecha'] === $f && $m['tipo'] === txt($r['tipo'] ?? '')));
        if (count($afinados) !== 1) {
          return ['ok' => false, 'error' => 'Hay ' . count($matches) . " viajes con la referencia \"$refViejo\" — corrige los duplicados antes de editar"];
        }
        $id = (int)$afinados[0]['id'];
      }
      actualizar('viajes', filaViaje($r), $id);
      return ['ok' => true, 'action' => $action];
    }

    case 'delete': {
      // Igual que v10: el último viaje que coincida en fecha + tipo + costo
      $id = q('SELECT id FROM viajes WHERE fecha <=> ? AND tipo = ? AND costo = ? ORDER BY id DESC LIMIT 1',
        [fechaIn($p['fecha'] ?? ''), txt($p['tipo'] ?? ''), round(num($p['costo'] ?? 0), 2)])->fetchColumn();
      if ($id === false) return ['ok' => false, 'error' => 'Viaje no encontrado para eliminar'];
      q('DELETE FROM viajes WHERE id = ?', [$id]);
      return ['ok' => true, 'action' => $action];
    }

    // ─────────── COBRANZA ───────────
    case 'cobranza_append': {
      $c = $p['cobranza'] ?? [];
      $folio = trim(txt($c['folio'] ?? '')) ?: generarFolio();
      // La cola offline puede reenviar el mismo registro: no duplicar
      if (idPorClave('cobranza', 'folio', $folio)) return ['ok' => true, 'action' => $action, 'folio' => $folio, 'nota' => 'ya existía'];
      $c['folio'] = $folio;
      insertar('cobranza', filaCobranza($c));
      return ['ok' => true, 'action' => $action, 'folio' => $folio];
    }

    case 'cobranza_update': {
      $id = idPorClave('cobranza', 'folio', $p['folio'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Cobranza no encontrada (folio ' . txt($p['folio'] ?? '') . ')'];
      $c = $p['cobranza'] ?? [];
      $c['folio'] = $p['folio'];
      actualizar('cobranza', filaCobranza($c), $id);
      return ['ok' => true, 'action' => $action];
    }

    case 'cobranza_update_estado': {
      $id = idPorClave('cobranza', 'folio', $p['folio'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Cobranza no encontrada'];
      q('UPDATE cobranza SET estado = ?, usuario = IF(? = "", usuario, ?) WHERE id = ?', [txt($p['estado'] ?? ''), $u, $u, $id]);
      return ['ok' => true, 'action' => $action];
    }

    case 'cobranza_delete': {
      $id = idPorClave('cobranza', 'folio', $p['folio'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Cobranza no encontrada'];
      q('DELETE FROM cobranza WHERE id = ?', [$id]);
      return ['ok' => true, 'action' => $action];
    }

    // ─────────── PAGOS A PROVEEDORES ───────────
    case 'pago_append': {
      $pago = $p['pago'] ?? [];
      $folio = trim(txt($pago['folio'] ?? '')) ?: 'PAG-' . date('Ymd') . '-' . random_int(1000, 9999);
      if (idPorClave('pagos', 'folio', $folio)) return ['ok' => true, 'action' => $action, 'nota' => 'ya existía'];
      $pago['folio'] = $folio;
      insertar('pagos', filaPago($pago));
      return ['ok' => true, 'action' => $action];
    }

    case 'pago_update': {
      $id = idPorClave('pagos', 'folio', $p['folio'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Pago no encontrado (folio ' . txt($p['folio'] ?? '') . ')'];
      $pago = $p['pago'] ?? [];
      $pago['folio'] = $p['folio'];
      actualizar('pagos', filaPago($pago), $id);
      return ['ok' => true, 'action' => $action];
    }

    case 'pago_update_estado': {
      $id = idPorClave('pagos', 'folio', $p['folio'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Pago no encontrado'];
      q('UPDATE pagos SET estado = ?, usuario = IF(? = "", usuario, ?) WHERE id = ?', [txt($p['estado'] ?? ''), $u, $u, $id]);
      return ['ok' => true, 'action' => $action];
    }

    case 'pago_delete': {
      $id = idPorClave('pagos', 'folio', $p['folio'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Pago no encontrado'];
      q('DELETE FROM pagos WHERE id = ?', [$id]);
      return ['ok' => true, 'action' => $action];
    }

    // ─────────── COTIZACIONES ───────────
    case 'cotizacion_append': {
      $c = $p['cotizacion'] ?? [];
      $folio = trim(txt($c['folio'] ?? '')) ?: 'COT-' . date('Ymd') . '-' . random_int(100, 999);
      if (idPorClave('cotizaciones', 'folio', $folio)) return ['ok' => true, 'action' => $action, 'nota' => 'ya existía'];
      $c['folio'] = $folio;
      insertar('cotizaciones', filaCotizacion($c));
      return ['ok' => true, 'action' => $action];
    }

    case 'cotizacion_update_estado': {
      $id = idPorClave('cotizaciones', 'folio', $p['folio'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Cotización no encontrada'];
      q('UPDATE cotizaciones SET estado = ? WHERE id = ?', [txt($p['estado'] ?? ''), $id]);
      return ['ok' => true, 'action' => $action];
    }

    case 'cotizacion_delete': {
      $id = idPorClave('cotizaciones', 'folio', $p['folio'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Cotización no encontrada'];
      q('DELETE FROM cotizaciones WHERE id = ?', [$id]);
      return ['ok' => true, 'action' => $action];
    }

    // ─────────── CONFIG (mis datos y aduanas) ───────────
    case 'config_save': {
      $cfg = $p['config'] ?? [];
      if (!is_array($cfg)) return ['ok' => false, 'error' => 'config inválido'];
      foreach ($cfg as $k => $v) {
        q('INSERT INTO config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [trim((string)$k), txt($v)]);
      }
      return ['ok' => true, 'action' => $action];
    }

    // ─────────── CATÁLOGOS ───────────
    case 'catalogo_append': {
      $cat = filaCatalogo($p['catalogo'] ?? null, $p['entry'] ?? []);
      if (!$cat) return ['ok' => false, 'error' => 'Catálogo desconocido: ' . txt($p['catalogo'] ?? '')];
      [$tabla, $fila] = $cat;
      if (idPorClave($tabla, 'nombre', $fila['nombre'])) return ['ok' => false, 'error' => 'Ya existe: ' . $fila['nombre']];
      insertar($tabla, $fila);
      return ['ok' => true, 'action' => $action];
    }

    case 'catalogo_update': {
      $cat = filaCatalogo($p['catalogo'] ?? null, $p['entry'] ?? []);
      if (!$cat) return ['ok' => false, 'error' => 'Catálogo desconocido: ' . txt($p['catalogo'] ?? '')];
      [$tabla, $fila] = $cat;
      $id = idPorClave($tabla, 'nombre', $p['nombreViejo'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Registro no encontrado: ' . txt($p['nombreViejo'] ?? '')];
      $otro = idPorClave($tabla, 'nombre', $fila['nombre']);
      if ($otro && $otro !== $id) return ['ok' => false, 'error' => 'Ya existe: ' . $fila['nombre']];
      actualizar($tabla, $fila, $id);
      return ['ok' => true, 'action' => $action];
    }

    case 'catalogo_delete': {
      $cat = filaCatalogo($p['catalogo'] ?? null, []);
      if (!$cat) return ['ok' => false, 'error' => 'Catálogo desconocido: ' . txt($p['catalogo'] ?? '')];
      $id = idPorClave($cat[0], 'nombre', $p['nombre'] ?? '');
      if (!$id) return ['ok' => false, 'error' => 'Registro no encontrado: ' . txt($p['nombre'] ?? '')];
      q("DELETE FROM {$cat[0]} WHERE id = ?", [$id]);
      return ['ok' => true, 'action' => $action];
    }

    default:
      return ['ok' => false, 'error' => 'Acción desconocida: ' . $action];
  }
}

// ══════════════════════════════════════════════════════════════════
//  ENTRADA
// ══════════════════════════════════════════════════════════════════

$cb = $_GET['cb'] ?? $_GET['callback'] ?? 'cb';
if (!preg_match('/^[A-Za-z_$][\w$]{0,80}$/', $cb)) $cb = 'cb';
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Toda petición requiere sesión. Se responde con 200 + auth:false (y no 401)
// porque un <script> JSONP con error HTTP no ejecuta el callback y la app
// no podría distinguirlo de un fallo de red.
$yo = usuarioSesion();
if (!$yo) {
  responder(['ok' => false, 'auth' => false, 'error' => 'Tu sesión expiró. Vuelve a iniciar sesión.'], $esPost ? null : $cb);
}
if ($yo['debe_cambiar']) {
  responder(['ok' => false, 'auth' => false, 'cambiarPassword' => true, 'error' => 'Debes cambiar tu contraseña antes de continuar.'], $esPost ? null : $cb);
}

// "Quién capturó" lo define la sesión, no lo que mande el navegador
function etiquetarUsuario(array $p, string $nombre): array {
  foreach (['registro', 'cobranza', 'pago'] as $k) {
    if (isset($p[$k]) && is_array($p[$k])) $p[$k]['usuario'] = $nombre;
  }
  $p['usuario'] = $nombre;
  return $p;
}

try {
  if ($esPost) {
    $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    responder(is_array($payload) ? procesarEscritura(etiquetarUsuario($payload, $yo['nombre'])) : ['ok' => false, 'error' => 'payload inválido'], null);
  }
  if (($_GET['action'] ?? '') === 'write' && isset($_GET['data'])) {
    $payload = json_decode($_GET['data'], true, 512, JSON_THROW_ON_ERROR);
    responder(is_array($payload) ? procesarEscritura(etiquetarUsuario($payload, $yo['nombre'])) : ['ok' => false, 'error' => 'payload inválido'], $cb);
  }
  responder(leerTodo(), $cb);
} catch (Throwable $e) {
  error_log('[api.php] ' . $e->getMessage());
  $esWrite = $esPost || ($_GET['action'] ?? '') === 'write';
  responder($esWrite ? ['ok' => false, 'error' => $e->getMessage()] : ['error' => $e->getMessage()],
    $esPost ? null : $cb);
}
