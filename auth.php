<?php
/**
 * Autenticación de la Bitácora VAZSE.
 *
 * Solo POST con cuerpo JSON {action, ...}. Responde JSON {ok, ...}.
 *   estado                 → ¿hay sesión? ¿falta crear el primer administrador?
 *   setup                  → crea el primer administrador (solo desde esta PC y si no hay usuarios)
 *   login / logout
 *   cambiar_password       → el usuario cambia la suya
 *   usuarios_listar, usuario_crear, usuario_actualizar, usuario_reset_password  (solo admin)
 */

require __DIR__ . '/db/sesion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MAX_FALLOS_USUARIO = 5;    // por usuario en la ventana
const MAX_FALLOS_IP      = 20;   // por IP en la ventana
const VENTANA_MIN        = 15;

function salir(array $r, int $http = 200): void {
  http_response_code($http);
  echo json_encode($r, JSON_UNESCAPED_UNICODE);
  exit;
}
function error(string $msg, int $http = 200): void { salir(['ok' => false, 'error' => $msg], $http); }
function q(string $sql, array $params = []): PDOStatement {
  $st = db()->prepare($sql);
  $st->execute($params);
  return $st;
}

function validarUsuario($v): string {
  $v = strtolower(trim((string)$v));
  if (!preg_match('/^[a-z0-9._-]{3,40}$/', $v)) error('El usuario debe tener de 3 a 40 caracteres: letras, números, punto, guion o guion bajo, sin espacios');
  return $v;
}
function validarNombre($v): string {
  $v = trim(preg_replace('/\s+/', ' ', (string)$v));
  if ($v === '' || mb_strlen($v) > 60) error('Escribe el nombre (máximo 60 caracteres)');
  return $v;
}
function validarPassword($v): string {
  $v = (string)$v;
  if ($v === '') error('Escribe una contraseña');
  if (strlen($v) > 200) error('La contraseña es demasiado larga');
  return $v;
}
function hashPassword(string $p): string { return password_hash($p, PASSWORD_DEFAULT); }

function requiereAdmin(): array {
  $u = usuarioSesion();
  if (!$u) error('Tu sesión expiró. Vuelve a iniciar sesión.', 401);
  if ($u['rol'] !== 'admin') error('Solo un administrador puede hacer esto', 403);
  return $u;
}
function adminsActivos(?int $excepto = null): int {
  return (int)q('SELECT COUNT(*) FROM usuarios WHERE rol = "admin" AND activo = 1 AND id <> ?', [$excepto ?? 0])->fetchColumn();
}
function hayUsuarios(): bool { return (bool)q('SELECT 1 FROM usuarios LIMIT 1')->fetchColumn(); }

function entrar(array $u): void {
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  $_SESSION['ultimo'] = time();
  q('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?', [$u['id']]);
}

// ══════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('Método no permitido', 405);
// Exigir JSON: un formulario de otro sitio no puede mandar este Content-Type sin preflight CORS
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) error('Se esperaba JSON', 415);

try {
  $p = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
  error('JSON inválido', 400);
}
if (!is_array($p)) error('JSON inválido', 400);

iniciarSesion();
$action = $p['action'] ?? '';

try {
  switch ($action) {

    case 'estado': {
      $u = usuarioSesion();
      $setup = !hayUsuarios();
      salir(['ok' => true, 'autenticado' => (bool)$u, 'usuario' => $u ? usuarioPublico($u) : null,
             'setup' => $setup, 'setupPermitido' => $setup && esLocal()]);
    }

    case 'setup': {
      if (!esLocal()) error('El primer administrador solo se puede crear desde la computadora del servidor', 403);
      $usuario = validarUsuario($p['usuario'] ?? '');
      $nombre = validarNombre($p['nombre'] ?? '');
      $pass = validarPassword($p['password'] ?? '');
      db()->beginTransaction();
      // Bloqueo para que dos peticiones simultáneas no creen dos administradores
      if (q('SELECT COUNT(*) FROM usuarios FOR UPDATE')->fetchColumn() > 0) {
        db()->rollBack();
        error('Ya existe un administrador. Inicia sesión.');
      }
      q('INSERT INTO usuarios (usuario, nombre, password_hash, rol) VALUES (?, ?, ?, "admin")', [$usuario, $nombre, hashPassword($pass)]);
      $id = (int)db()->lastInsertId();
      db()->commit();
      $u = q('SELECT * FROM usuarios WHERE id = ?', [$id])->fetch();
      entrar($u);
      salir(['ok' => true, 'usuario' => usuarioPublico($u)]);
    }

    case 'login': {
      $usuario = strtolower(trim((string)($p['usuario'] ?? '')));
      $pass = (string)($p['password'] ?? '');
      $ip = $_SERVER['REMOTE_ADDR'] ?? '';
      if ($usuario === '' || $pass === '') error('Escribe usuario y contraseña');

      $fallosU = (int)q('SELECT COUNT(*) FROM login_intentos WHERE usuario = ? AND fecha > NOW() - INTERVAL ? MINUTE', [$usuario, VENTANA_MIN])->fetchColumn();
      $fallosIp = (int)q('SELECT COUNT(*) FROM login_intentos WHERE ip = ? AND fecha > NOW() - INTERVAL ? MINUTE', [$ip, VENTANA_MIN])->fetchColumn();
      if ($fallosU >= MAX_FALLOS_USUARIO || $fallosIp >= MAX_FALLOS_IP) {
        error('Demasiados intentos fallidos. Espera ' . VENTANA_MIN . ' minutos e inténtalo de nuevo.', 429);
      }

      $u = q('SELECT * FROM usuarios WHERE usuario = ?', [$usuario])->fetch();
      // Si el usuario no existe se verifica contra un hash cualquiera para que
      // el tiempo de respuesta no revele qué usuarios existen
      $hash = $u['password_hash'] ?? password_hash('usuario-inexistente', PASSWORD_DEFAULT);
      $okPass = password_verify($pass, $hash);
      if (!$u || !$okPass || !$u['activo']) {
        q('INSERT INTO login_intentos (usuario, ip) VALUES (?, ?)', [$usuario, $ip]);
        usleep(400000);
        error($u && $okPass && !$u['activo'] ? 'Tu cuenta está desactivada. Habla con el administrador.' : 'Usuario o contraseña incorrectos');
      }
      q('DELETE FROM login_intentos WHERE usuario = ?', [$usuario]);
      if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [hashPassword($pass), $u['id']]);
      }
      entrar($u);
      salir(['ok' => true, 'usuario' => usuarioPublico($u)]);
    }

    case 'logout':
      cerrarSesionActual();
      salir(['ok' => true]);

    case 'cambiar_password': {
      $u = usuarioSesion();
      if (!$u) error('Tu sesión expiró. Vuelve a iniciar sesión.', 401);
      $actual = (string)($p['actual'] ?? '');
      $nueva = validarPassword($p['nueva'] ?? '');
      $hash = q('SELECT password_hash FROM usuarios WHERE id = ?', [$u['id']])->fetchColumn();
      if (!password_verify($actual, $hash)) error('La contraseña actual no es correcta');
      if (password_verify($nueva, $hash)) error('La contraseña nueva debe ser distinta a la actual');
      q('UPDATE usuarios SET password_hash = ?, debe_cambiar = 0 WHERE id = ?', [hashPassword($nueva), $u['id']]);
      session_regenerate_id(true);
      salir(['ok' => true]);
    }

    // ─────────── Administración de usuarios ───────────

    case 'usuarios_listar': {
      requiereAdmin();
      $lista = q('SELECT id, usuario, nombre, rol, activo, debe_cambiar, ultimo_acceso FROM usuarios ORDER BY activo DESC, nombre')->fetchAll();
      salir(['ok' => true, 'usuarios' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'usuario' => $r['usuario'], 'nombre' => $r['nombre'], 'rol' => $r['rol'],
        'activo' => (bool)$r['activo'], 'debeCambiar' => (bool)$r['debe_cambiar'], 'ultimoAcceso' => $r['ultimo_acceso'],
      ], $lista)]);
    }

    case 'usuario_crear': {
      requiereAdmin();
      $usuario = validarUsuario($p['usuario'] ?? '');
      $nombre = validarNombre($p['nombre'] ?? '');
      $pass = validarPassword($p['password'] ?? '');
      $rol = ($p['rol'] ?? '') === 'admin' ? 'admin' : 'usuario';
      if (q('SELECT 1 FROM usuarios WHERE usuario = ?', [$usuario])->fetchColumn()) error("Ya existe el usuario \"$usuario\"");
      // Contraseña temporal: se le pide cambiarla al entrar por primera vez
      q('INSERT INTO usuarios (usuario, nombre, password_hash, rol, debe_cambiar) VALUES (?, ?, ?, ?, 1)',
        [$usuario, $nombre, hashPassword($pass), $rol]);
      salir(['ok' => true]);
    }

    case 'usuario_actualizar': {
      $yo = requiereAdmin();
      $id = (int)($p['id'] ?? 0);
      $obj = q('SELECT * FROM usuarios WHERE id = ?', [$id])->fetch();
      if (!$obj) error('Usuario no encontrado');
      $nombre = array_key_exists('nombre', $p) ? validarNombre($p['nombre']) : $obj['nombre'];
      $rol = array_key_exists('rol', $p) ? (($p['rol'] === 'admin') ? 'admin' : 'usuario') : $obj['rol'];
      $activo = array_key_exists('activo', $p) ? (int)(bool)$p['activo'] : (int)$obj['activo'];
      // Siempre debe quedar al menos un administrador activo
      if (($rol !== 'admin' || !$activo) && $obj['rol'] === 'admin' && adminsActivos($id) === 0) {
        error('Debe quedar al menos un administrador activo');
      }
      if ($id === (int)$yo['id'] && !$activo) error('No puedes desactivar tu propia cuenta');
      q('UPDATE usuarios SET nombre = ?, rol = ?, activo = ? WHERE id = ?', [$nombre, $rol, $activo, $id]);
      salir(['ok' => true]);
    }

    case 'usuario_reset_password': {
      requiereAdmin();
      $id = (int)($p['id'] ?? 0);
      $pass = validarPassword($p['password'] ?? '');
      $st = q('UPDATE usuarios SET password_hash = ?, debe_cambiar = 1 WHERE id = ?', [hashPassword($pass), $id]);
      if (!$st->rowCount()) error('Usuario no encontrado');
      $usr = q('SELECT usuario FROM usuarios WHERE id = ?', [$id])->fetchColumn();
      q('DELETE FROM login_intentos WHERE usuario = ?', [$usr]);
      salir(['ok' => true]);
    }

    default:
      error('Acción desconocida: ' . $action, 400);
  }
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  error_log('[auth.php] ' . $e->getMessage());
  error('Error interno del servidor', 500);
}
