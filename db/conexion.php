<?php
// Conexión PDO a MySQL/MariaDB. Valores por defecto = XAMPP local;
// en otro servidor se definen con variables de entorno.
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  // 127.0.0.1 y no "localhost": MySQL solo escucha en IPv4 y "localhost"
  // intenta primero ::1, lo que agrega ~2 s a cada conexión en Windows.
  $host = getenv('VAZSE_DB_HOST') ?: '127.0.0.1';
  $name = getenv('VAZSE_DB_NAME') ?: 'vazse_bitacora';
  $user = getenv('VAZSE_DB_USER') ?: 'root';
  $pass = getenv('VAZSE_DB_PASS') ?: '';
  $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
  return $pdo;
}
