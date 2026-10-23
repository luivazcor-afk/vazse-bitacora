<?php
/**
 * Archivos de la Bitácora (facturas, evidencias, EIR) — reemplaza a Google Drive.
 *
 *   POST archivos.php  (multipart, campo "archivo") → {ok, url}
 *   GET  archivos.php?id=<32 hex>[&n=nombre]        → el archivo (inline)
 *
 * Ambos requieren sesión. Los archivos se guardan fuera de la carpeta web,
 * en VAZSE_ARCHIVOS_DIR (por defecto C:\vazse-bitacora-archivos), con un
 * nombre aleatorio; el nombre original solo se guarda en la tabla archivos.
 */

require __DIR__ . '/db/sesion.php';

const MAX_BYTES = 50 * 1024 * 1024;
// Solo documentos e imágenes, validados por contenido (no por la extensión que manda el navegador)
const TIPOS = [
  'application/pdf' => 'pdf',
  'image/jpeg'      => 'jpg',
  'image/png'       => 'png',
  'image/gif'       => 'gif',
  'image/webp'      => 'webp',
];

function dirArchivos(): string {
  return rtrim(getenv('VAZSE_ARCHIVOS_DIR') ?: 'C:/vazse-bitacora-archivos', '/\\');
}

function salirJson(array $r, int $http = 200): void {
  http_response_code($http);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($r, JSON_UNESCAPED_UNICODE);
  exit;
}

header('Cache-Control: private, no-store');
$yo = usuarioSesion();

// ─────────── SUBIR ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$yo) salirJson(['ok' => false, 'auth' => false, 'error' => 'Tu sesión expiró. Vuelve a iniciar sesión.'], 401);
  if ($yo['debe_cambiar']) salirJson(['ok' => false, 'auth' => false, 'cambiarPassword' => true, 'error' => 'Debes cambiar tu contraseña antes de continuar.'], 401);

  // Si el archivo excede post_max_size PHP descarta todo el cuerpo y $_FILES llega vacío
  $f = $_FILES['archivo'] ?? null;
  if (!$f) salirJson(['ok' => false, 'error' => 'No llegó ningún archivo (¿pesa más de 50 MB?)']);
  if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > MAX_BYTES) {
    salirJson(['ok' => false, 'error' => 'El archivo supera el límite de 50 MB']);
  }
  if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    salirJson(['ok' => false, 'error' => 'No se pudo recibir el archivo (código ' . $f['error'] . ')']);
  }

  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
  if (!isset(TIPOS[$mime])) salirJson(['ok' => false, 'error' => 'Solo se permiten PDF o imágenes (JPG, PNG, GIF, WEBP)']);

  $id = bin2hex(random_bytes(16));
  $sub = date('Y/m');
  $ruta = "$sub/$id." . TIPOS[$mime];
  $destino = dirArchivos() . '/' . $ruta;
  if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0775, true)) {
    error_log('[archivos.php] No se pudo crear ' . dirname($destino));
    salirJson(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor'], 500);
  }
  if (!move_uploaded_file($f['tmp_name'], $destino)) {
    salirJson(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor'], 500);
  }

  // Nombre original limpio: sin rutas ni caracteres de control
  $nombre = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', basename((string)$f['name'])));
  if ($nombre === '') $nombre = "archivo." . TIPOS[$mime];
  $nombre = mb_substr($nombre, 0, 200);

  $st = db()->prepare('INSERT INTO archivos (id, nombre, mime, tamano, ruta, subido_por) VALUES (?, ?, ?, ?, ?, ?)');
  $st->execute([$id, $nombre, $mime, (int)$f['size'], $ruta, $yo['nombre']]);

  // El nombre va en la URL para que la app reconozca imágenes por la extensión (verDoc)
  salirJson(['ok' => true, 'url' => 'archivos.php?id=' . $id . '&n=' . rawurlencode($nombre), 'nombre' => $nombre]);
}

// ─────────── DESCARGAR / VER ───────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') salirJson(['ok' => false, 'error' => 'Método no permitido'], 405);
if (!$yo) {
  http_response_code(401);
  header('Content-Type: text/plain; charset=utf-8');
  exit('Inicia sesión en la Bitácora para ver este archivo.');
}

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $id)) { http_response_code(404); exit('Archivo no encontrado'); }
$st = db()->prepare('SELECT * FROM archivos WHERE id = ?');
$st->execute([$id]);
$a = $st->fetch();
$path = $a ? dirArchivos() . '/' . $a['ruta'] : '';
if (!$a || !is_file($path)) { http_response_code(404); exit('Archivo no encontrado'); }

header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
// Imágenes: que el navegador no ejecute nada aunque se abran solas. (A los PDF no se
// les pone "sandbox" porque el visor de PDF de Chrome no funciona en un documento aislado.)
if ($a['mime'] !== 'application/pdf') header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
$ascii = preg_replace('/[^\x20-\x7E]|"/', '_', $a['nombre']);
header('Content-Disposition: ' . (isset($_GET['descargar']) ? 'attachment' : 'inline') .
       '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($a['nombre']));
readfile($path);
