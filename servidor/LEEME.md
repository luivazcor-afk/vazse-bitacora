# Servidor de la Bitácora VAZSE (XAMPP)

- Esta PC: http://localhost/vazse-bitacora/
- Red de la oficina: https://192.168.68.108/vazse-bitacora/ (certificado autofirmado: "Configuración avanzada → Continuar")

## Arranque automático y tareas programadas

`servidor\instalar_servicios.bat` → clic derecho → **Ejecutar como administrador** (una sola vez).

- Instala Apache (`Apache2.4`) y MySQL (`mysql`) como servicios que arrancan con Windows.
- Programa en el Programador de tareas (carpeta `VAZSE`). Corren aunque nadie haya
  iniciado sesión y, si la PC estaba apagada a esa hora, en cuanto se prende:
  - **Bitácora - Respaldo**, 2:00: respalda la base y los archivos subidos.
  - **Bitácora - Diario**, 8:00: marca cobranza vencida y envía alertas de vencimiento.
  - **Bitácora - Resumen semanal**, lunes 8:00: resumen de la semana anterior.

## Respaldos

`tareas\respaldo.php` (diario, 2:00):

- Base de datos comprimida en `C:\vazse-respaldos\diarios\` (se conservan 30 días).
- Archivos subidos nuevos en `C:\vazse-respaldos\archivos\`.
- **Recomendado:** una segunda copia fuera de este disco. En `db\config.servidor.php` pon
  `'respaldo_copia_dir' => 'E:/Respaldos Bitacora'` (disco externo) o la carpeta de
  Google Drive para escritorio. Si esa carpeta no está disponible, avisa por correo.
- Respaldo manual en cualquier momento: `C:\xampp\php\php.exe C:\vazse-bitacora\tareas\respaldo.php`

Restaurar un respaldo (reemplaza la base actual):

1. Descomprime el `.sql.gz` (7-Zip o WinRAR) → `bitacora_<fecha>.sql`
2. `C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -u root < "C:\ruta\bitacora_<fecha>.sql"`
3. Si hace falta, copia de vuelta `C:\vazse-respaldos\archivos\` a `C:\vazse-bitacora-archivos\`.

## Correos

1. Copia `db\config.servidor.ejemplo.php` como `db\config.servidor.php` (no se sube a GitHub).
2. Llena la cuenta de correo (`smtp_usuario`, `smtp_password`) y los `destinatarios`.
3. Prueba: `C:\xampp\php\php.exe C:\vazse-bitacora\tareas\probar_correo.php`

Para ver qué enviarían las tareas sin enviar nada ni cambiar datos:
`C:\xampp\php\php.exe C:\vazse-bitacora\tareas\diario.php --prueba` (también `semanal.php --prueba`).
La vista previa queda en `logs\ultimo_diario.html`; el historial en `logs\tareas.log`.

## Corte final (dejar Google Sheets)

1. Avisa al equipo y elige un momento sin captura.
2. Ensayo (no cambia nada): `C:\xampp\php\php.exe C:\vazse-bitacora\db\corte_final.php --simular`
3. Corte: `C:\xampp\php\php.exe C:\vazse-bitacora\db\corte_final.php` → escribe `SI`.
   Respalda la base en `C:\vazse-respaldos\`, descarga el Sheet y lo importa.
   No toca usuarios ni archivos subidos.
4. ~~En el Sheet → Extensiones → Apps Script → **Activadores**: elimina `triggerDiario` y
   `triggerSemanal`.~~ Hecho el 23-oct-2026 (`triggerBackup` se dejó).
5. Desde ese momento todos capturan solo en el sistema nuevo.

### Cerrar el sistema viejo (justo después del corte)

6. **GitHub Pages → aviso de mudanza.** En la rama `main`, reemplazar `index.html` por
   `servidor/aviso_mudanza.html`. Quien abra luivazcor-afk.github.io/vazse-bitacora verá
   el aviso con la dirección nueva en vez de la app vieja.
7. **Apps Script → dejar de publicar la app web.** Extensiones → Apps Script →
   Implementar → Administrar implementaciones → **Archivar** la implementación.
   Importante: hoy esa URL está en `config.json` (público en GitHub) y **cualquiera que la
   conozca puede leer y modificar todos los datos** sin contraseña. Hacerlo **después** del
   paso 3, porque `corte_final.php` descarga los datos por esa URL.
8. **Google Sheet en solo lectura.** Compartir → a cada persona cámbiala de *Editor* a
   *Lector*. Queda como archivo histórico.

Para regresar al estado anterior al corte:
`C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -u root < "C:\vazse-respaldos\antes_del_corte_<fecha>.sql"`

## Carpetas importantes (inclúyelas en tus respaldos)

| Qué | Dónde |
|---|---|
| Base de datos | MySQL `vazse_bitacora` (`C:\xampp\mysql\data`) |
| Archivos subidos (facturas, evidencias, EIR) | `C:\vazse-bitacora-archivos` |
| Respaldos del corte | `C:\vazse-respaldos` |
| Configuración de correo | `db\config.servidor.php` |
