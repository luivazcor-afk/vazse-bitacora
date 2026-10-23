<?php
// Sesión PHP compartida por auth.php y api.php.
require_once __DIR__ . '/conexion.php';

const SESION_HORAS = 8;   // inactividad máxima antes de pedir login otra vez

function esHttps(): bool {
  return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function esLocal(): bool {
  return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function iniciarSesion(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  session_name('VAZSESESS');
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/vazse-bitacora/',
    'secure'   => esHttps(),
    'httponly' => true,
    // Strict: el navegador no manda la cookie en peticiones que vienen de
    // otros sitios. Protege las escrituras por GET (JSONP) contra CSRF.
    'samesite' => 'Strict',
  ]);
  ini_set('session.use_strict_mode', '1');
  ini_set('session.gc_maxlifetime', (string)(SESION_HORAS * 3600));
  session_start();

  // Expira por inactividad
  if (isset($_SESSION['uid'], $_SESSION['ultimo']) && time() - $_SESSION['ultimo'] > SESION_HORAS * 3600) {
    cerrarSesionActual();
    session_start();
  }
  if (isset($_SESSION['uid'])) $_SESSION['ultimo'] = time();
}

function cerrarSesionActual(): void {
  $_SESSION = [];
  $p = session_get_cookie_params();
  setcookie(session_name(), '', ['expires' => time() - 3600] + array_intersect_key($p, array_flip(['path', 'secure', 'httponly', 'samesite'])));
  session_destroy();
}

// Usuario de la sesión, revalidado contra la BD (si lo desactivan, pierde acceso
// en su siguiente petición). null si no hay sesión válida.
function usuarioSesion(): ?array {
  iniciarSesion();
  if (empty($_SESSION['uid'])) return null;
  static $u = false;
  if ($u === false) {
    $st = db()->prepare('SELECT id, usuario, nombre, rol, activo, debe_cambiar FROM usuarios WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch() ?: null;
    if ($u && !$u['activo']) $u = null;
    if (!$u) cerrarSesionActual();
  }
  return $u;
}

// Datos públicos del usuario para el frontend
function usuarioPublico(array $u): array {
  return [
    'usuario' => $u['usuario'], 'nombre' => $u['nombre'], 'rol' => $u['rol'],
    'debeCambiar' => (bool)$u['debe_cambiar'],
  ];
}
