<?php
// Utilidades de las tareas programadas (se ejecutan con php.exe desde el
// Programador de tareas de Windows, nunca desde el navegador).

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../db/conexion.php';
require_once __DIR__ . '/../db/correo.php';

date_default_timezone_set('America/Mexico_City');

// --prueba: no envía correos ni modifica datos; imprime lo que haría
const MODO_PRUEBA_FLAG = '--prueba';
function modoPrueba(): bool { global $argv; return in_array(MODO_PRUEBA_FLAG, $argv ?? [], true); }

function logTarea(string $tarea, string $msg): void {
  $linea = date('Y-m-d H:i:s') . " [$tarea] $msg" . PHP_EOL;
  echo $linea;
  $dir = __DIR__ . '/../logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents("$dir/tareas.log", $linea, FILE_APPEND);
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoneda($n): string { return '$' . number_format((float)$n, 2, '.', ','); }
function fmtFecha(?string $ymd): string { return $ymd ? date('d/m/Y', strtotime($ymd)) : '—'; }

// Marco común de los correos (mismo diseño que los del Apps Script)
function plantillaCorreo(string $empresa, string $titulo, string $subtitulo, string $cuerpo): string {
  return '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto">' .
    '<div style="background:#185FA5;color:#fff;padding:20px 24px;border-radius:8px 8px 0 0">' .
    '<h2 style="margin:0;font-size:18px">' . h($titulo) . ' — ' . h($empresa) . '</h2>' .
    '<p style="margin:4px 0 0;font-size:13px;opacity:.85">' . h($subtitulo) . '</p></div>' .
    '<div style="background:#fff;border:1px solid #e0e0e0;border-top:none;padding:20px 24px;border-radius:0 0 8px 8px">' .
    $cuerpo . '</div>' .
    '<p style="font-size:11px;color:#999;text-align:center;margin-top:8px">Mensaje automático de ' . h($empresa) .
    ' · Sistema Bitácora · Generado ' . date('d/m/Y H:i') . '</p></div>';
}

// Envía (o en --prueba solo imprime) un correo a los destinatarios configurados
function mandarCorreoTarea(string $tarea, array $cfg, string $asunto, string $html): void {
  $para = listaCorreos($cfg['destinatarios'] ?? '');
  if (modoPrueba()) {
    logTarea($tarea, "[prueba] NO se envió. Para: " . implode(', ', $para) . " | Asunto: $asunto");
    $f = __DIR__ . "/../logs/ultimo_$tarea.html";
    @file_put_contents($f, $html);
    logTarea($tarea, "[prueba] Vista previa guardada en " . realpath($f));
    return;
  }
  enviarCorreo($cfg, $para, $asunto, $html);
  logTarea($tarea, 'Correo enviado a ' . implode(', ', $para) . " | $asunto");
}
