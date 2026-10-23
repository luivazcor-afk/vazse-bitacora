<?php
// Configuración del servidor para las tareas automáticas (correos).
//
// 1. Copia este archivo como  db/config.servidor.php  (ese NO se sube a GitHub).
// 2. Llena los datos de la cuenta de correo que enviará las alertas.
// 3. Prueba con:  C:\xampp\php\php.exe C:\vazse-bitacora\tareas\probar_correo.php

return [
  // Servidor SMTP de grupovazse.com.mx
  'smtp_host'     => 'mail.grupovazse.com.mx',
  'smtp_puerto'   => 465,          // 465 = SSL directo · 587 = STARTTLS
  'smtp_usuario'  => 'alertas@grupovazse.com.mx',
  'smtp_password' => '',           // ← escribe aquí la contraseña de esa cuenta
  'smtp_verificar_certificado' => true,

  'remitente'        => 'alertas@grupovazse.com.mx',
  'remitente_nombre' => 'Resolución Estratégica VAZSE',

  // A quién le llegan las alertas y el resumen (varios: separados por ; o ,)
  'destinatarios' => 'ejecutivo2@grupovazse.com.mx',

  'empresa_nombre' => 'Resolución Estratégica VAZSE',
  'dias_aviso'     => 3,           // alertar facturas que vencen en los próximos N días

  // Respaldo diario (tareas/respaldo.php)
  'respaldo_dir'       => 'C:/vazse-respaldos',
  'respaldo_dias'      => 30,      // cuántos días de respaldos de la base se conservan
  // Segunda copia FUERA de este disco (recomendado): disco externo, carpeta de red
  // o la carpeta de Google Drive para escritorio. Vacío = no hacer segunda copia.
  // Ej.: 'E:/Respaldos Bitacora'  o  'G:/Mi unidad/Respaldos Bitacora'
  'respaldo_copia_dir' => '',

  // Otro sistema de captura (tareas/sincronizar_externo.php). Se conecta a su
  // MariaDB con un usuario de SOLO LECTURA. Vacío/sin 'host' = desactivado.
  'externo' => [
    'host'     => '',              // IP de esa computadora (ej. su IP de Tailscale)
    'puerto'   => 3306,
    'base'     => '',              // nombre de su base de datos
    'usuario'  => 'bitacora_lectura',
    'password' => '',
    // Consulta que devuelve EXACTAMENTE estas columnas (con estos alias):
    // referencia, cliente, contenedor, peso, bultos, mercancia, bl
    'consulta' => '',
  ],
];
