<?php
/**
 * Resumen semanal (lunes 8:00 a.m.) — equivale a resumenSemanal() del Apps Script.
 * Viajes, cobrado, costo y utilidad de la semana anterior (lunes a domingo),
 * top 3 clientes por utilidad y estado de la cobranza.
 *
 * Uso: php tareas/semanal.php [--prueba]
 */

require __DIR__ . '/comun.php';

$T = 'semanal';

try {
  $cfg = cargarConfigServidor();
  $empresa = $cfg['empresa_nombre'] ?? 'VAZSE';

  // Semana anterior completa: lunes a domingo
  $lunes = date('Y-m-d', strtotime('monday last week'));
  $domingo = date('Y-m-d', strtotime("$lunes +6 days"));

  $st = db()->prepare('SELECT COUNT(*) AS viajes, COALESCE(SUM(cobrado),0) AS cobrado, COALESCE(SUM(costo),0) AS costo
                       FROM viajes WHERE fecha BETWEEN ? AND ?');
  $st->execute([$lunes, $domingo]);
  $sem = $st->fetch();
  $utilidad = $sem['cobrado'] - $sem['costo'];
  $margen = $sem['cobrado'] > 0 ? $utilidad / $sem['cobrado'] * 100 : 0;

  $st = db()->prepare("SELECT COALESCE(NULLIF(cliente,''),'(sin cliente)') AS cliente, SUM(cobrado - costo) AS utilidad
                       FROM viajes WHERE fecha BETWEEN ? AND ? GROUP BY 1 ORDER BY utilidad DESC LIMIT 3");
  $st->execute([$lunes, $domingo]);
  $top = $st->fetchAll();

  $cob = db()->query("SELECT
      COALESCE(SUM(CASE WHEN LOWER(TRIM(estado)) IN ('pendiente','enviada') THEN monto END),0) AS pendiente,
      COALESCE(SUM(CASE WHEN LOWER(TRIM(estado)) IN ('vencida','vencido') THEN monto END),0) AS vencida,
      COALESCE(SUM(CASE WHEN LOWER(TRIM(estado)) IN ('pagada','pagado') AND fecha_cobro >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN monto END),0) AS cobrado_mes
    FROM cobranza")->fetch();

  $kpi = fn($valor, $etiqueta, $bg, $color, $grande = false) =>
    "<td style=\"padding:10px;background:$bg;border-radius:6px;text-align:center;width:25%\">" .
    "<div style=\"font-size:" . ($grande ? '22' : '18') . "px;font-weight:700;color:$color\">$valor</div>" .
    "<div style=\"font-size:11px;color:#666\">$etiqueta</div></td>";
  $sep = '<td style="width:4%"></td>';
  $icono = $margen >= 20 ? '🟢' : ($margen >= 10 ? '🟡' : '🔴');

  $cuerpo = '<h3 style="margin-top:0;color:#185FA5;font-size:15px">🚚 Viajes de la semana</h3>' .
    '<table style="width:100%;border-collapse:collapse;margin-bottom:20px"><tr>' .
    $kpi((int)$sem['viajes'], 'Viajes', '#F0F4FA', '#185FA5', true) . $sep .
    $kpi(fmtMoneda($sem['cobrado']), 'Cobrado', '#E8F5EE', '#0F6E56') . $sep .
    $kpi(fmtMoneda($sem['costo']), 'Costo', '#FEF3E8', '#854F0B') . $sep .
    $kpi(fmtMoneda($utilidad), "$icono Utilidad " . number_format($margen, 1) . '%', '#E8F5EE', '#0F6E56') .
    '</tr></table>';

  if ($top) {
    $medallas = ['🥇', '🥈', '🥉'];
    $cuerpo .= '<h3 style="color:#185FA5;font-size:15px">🏆 Top clientes por utilidad</h3><table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px">';
    foreach ($top as $i => $r) {
      $cuerpo .= '<tr style="border-bottom:1px solid #eee"><td style="padding:8px 10px">' . $medallas[$i] . ' ' . h($r['cliente']) .
        '</td><td style="padding:8px 10px;text-align:right;font-weight:600;color:#0F6E56">' . fmtMoneda($r['utilidad']) . '</td></tr>';
    }
    $cuerpo .= '</table>';
  }

  $fila = fn($etq, $monto, $color, $bg = '') => "<tr style=\"border-bottom:1px solid #eee" . ($bg ? ";background:$bg" : '') . "\">" .
    "<td style=\"padding:8px 10px\">$etq</td><td style=\"padding:8px 10px;text-align:right;font-weight:600;color:$color\">" . fmtMoneda($monto) . '</td></tr>';
  $cuerpo .= '<h3 style="color:#185FA5;font-size:15px">💰 Estado de Cobranza</h3>' .
    '<table style="width:100%;border-collapse:collapse;font-size:13px">' .
    '<tr style="background:#F5F5F5"><th style="padding:8px 10px;text-align:left;border-bottom:2px solid #ddd">Concepto</th>' .
    '<th style="padding:8px 10px;text-align:right;border-bottom:2px solid #ddd">Monto</th></tr>' .
    $fila('🟡 Pendiente de cobrar', $cob['pendiente'], '#854F0B') .
    $fila('🔴 Cartera vencida', $cob['vencida'], '#C0392B', '#FFF5F5') .
    $fila('✅ Cobrado este mes', $cob['cobrado_mes'], '#0F6E56', '#F0FAF5') .
    '</table>';

  $semanaStr = fmtFecha($lunes) . ' – ' . fmtFecha($domingo);
  $asunto = "📊 Resumen semanal $semanaStr — " . (int)$sem['viajes'] . ' viajes · Utilidad ' . fmtMoneda($utilidad);
  mandarCorreoTarea($T, $cfg, $asunto, plantillaCorreo($empresa, '📊 Resumen Semanal', $semanaStr, $cuerpo));
} catch (Throwable $e) {
  logTarea($T, 'ERROR: ' . $e->getMessage());
  exit(1);
}
