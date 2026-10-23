<?php
// Envío de correo por SMTP (SSL en 465 o STARTTLS en 587) sin librerías externas.

function cargarConfigServidor(): array {
  $f = getenv('VAZSE_CONFIG_SERVIDOR') ?: __DIR__ . '/config.servidor.php';
  if (!is_file($f)) throw new RuntimeException('Falta db/config.servidor.php (copia config.servidor.ejemplo.php y llénalo)');
  $cfg = require $f;
  if (!is_array($cfg)) throw new RuntimeException('db/config.servidor.php debe regresar un arreglo');
  return $cfg;
}

function listaCorreos(string $s): array {
  return array_values(array_filter(array_map('trim', preg_split('/[;,]+/', $s)),
    fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
}

function enviarCorreo(array $cfg, array $para, string $asunto, string $html): void {
  if (!$para) throw new RuntimeException('No hay destinatarios válidos');
  if (($cfg['smtp_password'] ?? '') === '') throw new RuntimeException('Falta smtp_password en db/config.servidor.php');

  $host = $cfg['smtp_host'];
  $puerto = (int)$cfg['smtp_puerto'];
  $verificar = $cfg['smtp_verificar_certificado'] ?? true;
  $ssl = ['verify_peer' => $verificar, 'verify_peer_name' => $verificar, 'peer_name' => $host];
  // PHP en Windows no usa los certificados del sistema: usar el paquete de CAs de XAMPP
  if (is_file('C:/xampp/apache/bin/curl-ca-bundle.crt')) $ssl['cafile'] = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
  $ctx = stream_context_create(['ssl' => $ssl]);
  // 'ssl' = TLS desde que conecta (465) · 'tls' = STARTTLS (587). Por defecto según el puerto.
  $sslDirecto = ($cfg['smtp_seguridad'] ?? ($puerto === 465 ? 'ssl' : 'tls')) === 'ssl';
  $s = @stream_socket_client(($sslDirecto ? 'ssl://' : 'tcp://') . "$host:$puerto", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) throw new RuntimeException("No se pudo conectar a $host:$puerto ($errstr)");
  stream_set_timeout($s, 30);

  $leer = function () use ($s): array {
    $txt = '';
    while (($l = fgets($s, 2048)) !== false) {
      $txt .= $l;
      if (strlen($l) < 4 || $l[3] === ' ') break;   // última línea: "250 ..." (no "250-...")
    }
    return [(int)substr($txt, 0, 3), trim($txt)];
  };
  $cmd = function (string $c, array $esperado, string $mostrar = null) use ($s, $leer): string {
    fwrite($s, $c . "\r\n");
    [$code, $txt] = $leer();
    if (!in_array($code, $esperado, true)) throw new RuntimeException('SMTP rechazó "' . ($mostrar ?? $c) . "\": $txt");
    return $txt;
  };

  try {
    [$code, $txt] = $leer();
    if ($code !== 220) throw new RuntimeException("SMTP no respondió el saludo: $txt");
    $yo = gethostname() ?: 'localhost';
    $ehlo = $cmd("EHLO $yo", [250]);
    if (!$sslDirecto) {
      if (stripos($ehlo, 'STARTTLS') === false) throw new RuntimeException('El servidor no ofrece STARTTLS en el puerto ' . $puerto);
      $cmd('STARTTLS', [220]);
      if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
        throw new RuntimeException('No se pudo iniciar TLS');
      }
      $cmd("EHLO $yo", [250]);
    }
    $cmd('AUTH LOGIN', [334]);
    $cmd(base64_encode($cfg['smtp_usuario']), [334], '(usuario)');
    $cmd(base64_encode($cfg['smtp_password']), [235], '(contraseña)');
    $cmd('MAIL FROM:<' . $cfg['remitente'] . '>', [250]);
    foreach ($para as $p) $cmd("RCPT TO:<$p>", [250, 251]);
    $cmd('DATA', [354]);

    $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
    $dominio = substr(strrchr($cfg['remitente'], '@'), 1) ?: 'localhost';
    $msg = implode("\r\n", [
      'Date: ' . date('r'),
      'From: ' . $enc($cfg['remitente_nombre'] ?? $cfg['remitente']) . ' <' . $cfg['remitente'] . '>',
      'To: ' . implode(', ', $para),
      'Subject: ' . $enc($asunto),
      'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'Content-Transfer-Encoding: base64',
      '',
      rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
    ]);
    // base64 nunca produce una línea con "." sola, así que no hace falta "dot-stuffing"
    $cmd($msg . "\r\n.", [250], '(mensaje)');
    $cmd('QUIT', [221, 250]);
  } finally {
    fclose($s);
  }
}
