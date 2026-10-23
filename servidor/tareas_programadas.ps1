# Programa las tareas de la Bitácora en el Programador de tareas de Windows
# (carpeta VAZSE). Lo llama instalar_servicios.bat con permisos de administrador.
# Corren como SYSTEM (aunque nadie haya iniciado sesión) y, si la PC estaba
# apagada a la hora programada, se ejecutan en cuanto se prenda.

$ErrorActionPreference = 'Stop'
$php = 'C:\xampp\php\php.exe'
$app = 'C:\vazse-bitacora'

$tareas = @(
  @{ Nombre = 'Bitacora - Respaldo';         Script = 'respaldo.php'; Trigger = New-ScheduledTaskTrigger -Daily -At '02:00' }
  @{ Nombre = 'Bitacora - Diario';           Script = 'diario.php';   Trigger = New-ScheduledTaskTrigger -Daily -At '08:00' }
  @{ Nombre = 'Bitacora - Resumen semanal';  Script = 'semanal.php';  Trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday -At '08:00' }
  # Trae cliente, contenedor, peso, bultos, mercancía y BL del otro sistema de captura.
  # Si 'externo' no está configurado en db/config.servidor.php, no hace nada.
  @{ Nombre = 'Bitacora - Sincronizar captura'; Script = 'sincronizar_externo.php'
     Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 15) }
)

$ajustes = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
  -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$cuenta = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

foreach ($t in $tareas) {
  $accion = New-ScheduledTaskAction -Execute $php -Argument "`"$app\tareas\$($t.Script)`"" -WorkingDirectory $app
  Register-ScheduledTask -TaskPath '\VAZSE\' -TaskName $t.Nombre -Action $accion -Trigger $t.Trigger `
    -Settings $ajustes -Principal $cuenta -Force | Out-Null
  $info = Get-ScheduledTask -TaskPath '\VAZSE\' -TaskName $t.Nombre | Get-ScheduledTaskInfo
  Write-Host (" {0,-28} próxima ejecución: {1}" -f $t.Nombre, $info.NextRunTime)
}
