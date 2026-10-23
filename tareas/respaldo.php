<?php
/**
 * Respaldo diario (2:00 a.m.; si la PC estaba apagada, en cuanto se prenda).
 * Equivale a backupDiario() del Apps Script.
 *
 *   1. Respaldo de la base vazse_bitacora (mysqldump comprimido) → C:\vazse-respaldos\diarios\
 *   2. Copia de los archivos subidos nuevos                       → C:\vazse-respaldos\archivos\
 *   3. Opcional: lo mismo en una segunda carpeta (disco externo, red o Google Drive
 *      para escritorio) si db/config.servidor.php define 'respaldo_copia_dir'.
 *   4. Borra respaldos de la base con más de N días (por defecto 30).
 *
 * Si falla y el correo está configurado, avisa por correo.
 * Uso: php tareas/respaldo.php
 */

require __DIR__ . '/comun.php';

$T = 'respaldo';
$MYSQLDUMP = 'C:/xampp/mysql/bin/mysqldump.exe';
$ARCHIVOS = rtrim(getenv('VAZSE_ARCHIVOS_DIR') ?: 'C:/vazse-bitacora-archivos', '/\\');

// El correo es opcional para el respaldo: sin config.servidor.php se usan los valores por defecto
try { $cfg = cargarConfigServidor(); } catch (Throwable $e) { $cfg = []; }
$destino = rtrim($cfg['respaldo_dir'] ?? 'C:/vazse-respaldos', '/\\');
$copia = rtrim($cfg['respaldo_copia_dir'] ?? '', '/\\');
$retener = (int)($cfg['respaldo_dias'] ?? 30);

function asegurarDir(string $d): void {
  if (!is_dir($d) && !mkdir($d, 0775, true)) throw new RuntimeException("No se pudo crear la carpeta $d");
}

// Copia los archivos que aún no estén en $hacia (los subidos nunca se modifican)
function copiarNuevos(string $desde, string $hacia): int {
  if (!is_dir($desde)) return 0;
  $n = 0;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($desde, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = substr($f->getPathname(), strlen($desde) + 1);
    $dst = "$hacia/$rel";
    if (is_file($dst) && filesize($dst) === $f->getSize()) continue;
    asegurarDir(dirname($dst));
    if (!copy($f->getPathname(), $dst)) throw new RuntimeException("No se pudo copiar $rel");
    $n++;
  }
  return $n;
}

function borrarViejos(string $dir, int $dias): int {
  $n = 0;
  foreach (glob("$dir/bitacora_*.sql.gz") ?: [] as $f) {
    if (filemtime($f) < time() - $dias * 86400 && unlink($f)) $n++;
  }
  return $n;
}

try {
  // 1. Base de datos
  $dirDb = "$destino/diarios";
  asegurarDir($dirDb);
  $base = "$dirDb/bitacora_" . date('Y-m-d_Hi');
  $sql = "$base.sql";
  $cmd = escapeshellarg($MYSQLDUMP) . ' -h 127.0.0.1 -u root --single-transaction --routines --triggers --events'
       . ' --databases vazse_bitacora --result-file=' . escapeshellarg($sql) . ' 2>&1';
  exec($cmd, $out, $code);
  // Un dump completo siempre termina con "-- Dump completed"
  $cola = is_file($sql) ? file_get_contents($sql, false, null, max(0, filesize($sql) - 200)) : '';
  if ($code !== 0 || !str_contains($cola, 'Dump completed')) {
    @unlink($sql);
    throw new RuntimeException('mysqldump falló: ' . trim(implode(' ', $out)));
  }
  $gz = "$sql.gz";
  $in = fopen($sql, 'rb'); $outGz = gzopen($gz, 'wb6');
  while (!feof($in)) gzwrite($outGz, fread($in, 1 << 20));
  fclose($in); gzclose($outGz);
  unlink($sql);
  logTarea($T, sprintf('Base respaldada: %s (%s KB)', $gz, number_format(filesize($gz) / 1024)));

  // 2. Archivos subidos
  $n = copiarNuevos($ARCHIVOS, "$destino/archivos");
  logTarea($T, "Archivos nuevos copiados: $n");

  // 3. Segunda copia fuera de este disco
  if ($copia !== '') {
    if (!is_dir($copia)) throw new RuntimeException("La carpeta de copia '$copia' no existe (¿disco desconectado?). El respaldo local sí se hizo.");
    asegurarDir("$copia/diarios");
    if (!copy($gz, "$copia/diarios/" . basename($gz))) throw new RuntimeException("No se pudo copiar el respaldo a $copia");
    $n2 = copiarNuevos($ARCHIVOS, "$copia/archivos");
    borrarViejos("$copia/diarios", $retener);
    logTarea($T, "Copia en $copia: base + $n2 archivo(s) nuevos");
  }

  // 4. Retención
  $b = borrarViejos($dirDb, $retener);
  if ($b) logTarea($T, "Respaldos con más de $retener días borrados: $b");
} catch (Throwable $e) {
  logTarea($T, 'ERROR: ' . $e->getMessage());
  if (!empty($cfg['smtp_password'])) {
    try {
      enviarCorreo($cfg, listaCorreos($cfg['destinatarios'] ?? ''), '⚠️ Falló el respaldo de la Bitácora',
        plantillaCorreo($cfg['empresa_nombre'] ?? 'VAZSE', '⚠️ Falló el respaldo', date('d/m/Y H:i'),
          '<p style="margin:0">El respaldo automático no se completó:</p><p style="color:#C0392B;font-weight:600">' . h($e->getMessage()) .
          '</p><p style="margin:0;font-size:12px;color:#666">Revisa logs\\tareas.log en el servidor.</p>'));
    } catch (Throwable $e2) {
      logTarea($T, 'Tampoco se pudo enviar el aviso por correo: ' . $e2->getMessage());
    }
  }
  exit(1);
}
