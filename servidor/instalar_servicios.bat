@echo off
setlocal
chcp 65001 >nul
rem ================================================================
rem  Bitacora VAZSE - instala Apache y MySQL como servicios de Windows
rem  (arrancan solos al prender la PC) y programa las tareas diarias.
rem
rem  Ejecutar UNA vez: clic derecho -> "Ejecutar como administrador".
rem  Se puede volver a correr sin problema: lo que ya existe lo deja igual.
rem ================================================================

net session >nul 2>&1
if errorlevel 1 (
  echo.
  echo  [!] Este script necesita permisos de administrador.
  echo      Clic derecho sobre el archivo ^> "Ejecutar como administrador".
  echo.
  pause
  exit /b 1
)

set XAMPP=C:\xampp
set APP=C:\vazse-bitacora

echo.
echo === 1/4  Deteniendo Apache y MySQL que esten corriendo sin servicio ===
taskkill /F /IM httpd.exe >nul 2>&1
"%XAMPP%\mysql\bin\mysqladmin.exe" -u root shutdown >nul 2>&1
timeout /t 3 /nobreak >nul

echo.
echo === 2/4  Servicio de Apache (Apache2.4) ===
sc query Apache2.4 >nul 2>&1
if errorlevel 1 (
  "%XAMPP%\apache\bin\httpd.exe" -k install -n "Apache2.4"
) else (
  echo  Ya existia, se reutiliza.
)
sc config Apache2.4 start= auto >nul
net start Apache2.4

echo.
echo === 3/4  Servicio de MySQL/MariaDB (mysql) ===
sc query mysql >nul 2>&1
if errorlevel 1 (
  "%XAMPP%\mysql\bin\mysqld.exe" --install mysql --defaults-file="%XAMPP%\mysql\bin\my.ini"
) else (
  echo  Ya existia, se reutiliza.
)
sc config mysql start= auto >nul
net start mysql

echo.
echo === 4/4  Tareas programadas ===
echo  Respaldo 2:00 / Alertas de cobranza 8:00 / Resumen semanal lunes 8:00
echo  (corren aunque nadie haya iniciado sesion; si la PC estaba apagada, al prenderla)
powershell -NoProfile -ExecutionPolicy Bypass -File "%APP%\servidor\tareas_programadas.ps1"
if errorlevel 1 echo  [!] No se pudieron programar las tareas. Revisa el mensaje de arriba.

echo.
echo ================================================================
echo  Listo. Comprobacion:
echo ================================================================
sc query Apache2.4 | findstr /I "STATE ESTADO"
sc query mysql     | findstr /I "STATE ESTADO"
echo.
echo  Ya no hace falta abrir XAMPP para prender Apache y MySQL.
echo.
pause
