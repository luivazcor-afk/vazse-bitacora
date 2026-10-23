<?php
// Envía un correo de prueba con la configuración de db/config.servidor.php.
// Uso: C:\xampp\php\php.exe C:\vazse-bitacora\tareas\probar_correo.php

require __DIR__ . '/comun.php';

try {
  $cfg = cargarConfigServidor();
  $para = listaCorreos($cfg['destinatarios'] ?? '');
  echo 'Enviando prueba a ' . implode(', ', $para) . ' por ' . $cfg['smtp_host'] . ':' . $cfg['smtp_puerto'] . " ...\n";
  enviarCorreo($cfg, $para, '✅ Prueba de correo — Bitácora VAZSE',
    plantillaCorreo($cfg['empresa_nombre'] ?? 'VAZSE', '✅ Prueba de correo', date('d/m/Y H:i'),
      '<p style="margin:0">Si lees esto, las alertas automáticas de la Bitácora ya pueden enviarse.</p>'));
  echo "Listo: correo enviado. Revisa la bandeja de entrada (y spam).\n";
} catch (Throwable $e) {
  echo 'ERROR: ' . $e->getMessage() . "\n";
  exit(1);
}
