<?php
/**
 * Tarea diaria (8:00 a.m.) — equivale a triggerDiario() del Apps Script:
 *   1. Marca como "vencida" la cobranza con fecha de vencimiento pasada.
 *   2. Envía por correo las facturas que vencen hoy o en los próximos N días.
 *
 * Uso: php tareas/diario.php [--prueba]
 */

require __DIR__ . '/comun.php';

$T = 'diario';
// Estados que ya no se marcan ni se alertan (mismos que el Apps Script v10)
const ESTADOS_CERRADOS = ['pagada', 'pagado', 'vencida', 'vencido', 'cancelada'];
$enCerrados = "LOWER(TRIM(estado)) IN ('" . implode("','", ESTADOS_CERRADOS) . "')";

try {
  $cfg = cargarConfigServidor();
  $empresa = $cfg['empresa_nombre'] ?? 'VAZSE';
  $diasAviso = (int)($cfg['dias_aviso'] ?? 3);

  // 1. Marcar vencidas
  $sqlVencidas = "FROM cobranza WHERE fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE() AND NOT $enCerrados";
  if (modoPrueba()) {
    $n = (int)db()->query("SELECT COUNT(*) $sqlVencidas")->fetchColumn();
    logTarea($T, "[prueba] Se marcarían $n factura(s) como vencidas");
  } else {
    $n = db()->exec("UPDATE cobranza SET estado = 'vencida' WHERE fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE() AND NOT $enCerrados");
    logTarea($T, "$n factura(s) marcadas como vencidas");
  }

  // 2. Alertas de vencimiento
  $st = db()->prepare("SELECT folio, cliente, monto, fecha_vencimiento, DATEDIFF(fecha_vencimiento, CURDATE()) AS dias
                       FROM cobranza
                       WHERE fecha_vencimiento BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY AND NOT $enCerrados
                       ORDER BY fecha_vencimiento, folio");
  $st->execute([$diasAviso]);
  $filas = $st->fetchAll();
  $hoy = array_values(array_filter($filas, fn($r) => (int)$r['dias'] === 0));
  $prox = array_values(array_filter($filas, fn($r) => (int)$r['dias'] > 0));

  if (!$filas) {
    logTarea($T, 'Sin facturas por vencer — no se envía correo');
    exit(0);
  }

  $tabla = function (array $rows, string $bg, bool $dias): string {
    $th = 'padding:8px 10px;border-bottom:2px solid #ddd';
    $td = 'padding:8px 10px;border-bottom:1px solid #eee';
    $t = '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px"><thead><tr style="background:#F5F5F5">' .
      "<th style=\"$th;text-align:left\">Folio</th><th style=\"$th;text-align:left\">Cliente</th>" .
      "<th style=\"$th;text-align:right\">Monto</th><th style=\"$th;text-align:center\">Vencimiento</th>" .
      ($dias ? "<th style=\"$th;text-align:center\">Días</th>" : '') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
      $t .= "<tr style=\"background:$bg\"><td style=\"$td;font-weight:600\">" . h($r['folio']) . "</td>" .
        "<td style=\"$td\">" . h($r['cliente']) . "</td>" .
        "<td style=\"$td;text-align:right;font-weight:600\">" . fmtMoneda($r['monto']) . "</td>" .
        "<td style=\"$td;text-align:center\">" . fmtFecha($r['fecha_vencimiento']) . "</td>" .
        ($dias ? "<td style=\"$td;text-align:center;font-weight:600;color:#854F0B\">" . (int)$r['dias'] . "d</td>" : '') . '</tr>';
    }
    return $t . '</tbody></table>';
  };

  $cuerpo = '';
  if ($hoy) $cuerpo .= '<h3 style="color:#C0392B;margin-top:0">🔴 Vencen HOY (' . count($hoy) . ')</h3>' . $tabla($hoy, '#FFF5F5', false);
  if ($prox) $cuerpo .= '<h3 style="color:#854F0B;margin-top:' . ($hoy ? '20px' : '0') . "\">🟡 Próximas a vencer — $diasAviso días (" . count($prox) . ')</h3>' . $tabla($prox, '#FFFBF0', true);

  $total = array_sum(array_map(fn($r) => (float)$r['monto'], $filas));
  $asunto = '⏰ Alertas de cobranza — ' . count($hoy) . ' hoy, ' . count($prox) . " en $diasAviso días (" . fmtMoneda($total) . ')';
  mandarCorreoTarea($T, $cfg, $asunto, plantillaCorreo($empresa, '⏰ Alertas de Cobranza', date('d/m/Y'), $cuerpo));
} catch (Throwable $e) {
  logTarea($T, 'ERROR: ' . $e->getMessage());
  exit(1);
}
