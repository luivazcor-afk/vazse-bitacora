# Bitácora de Viajes y Cobranza — VAZSE + MMB Logistics

## Descripción del proyecto
Sistema web de bitácora de viajes, cobranza y control operativo para empresas de logística/agencia aduanal. Arquitectura single-file HTML con Google Sheets como backend vía Apps Script. Dos empresas independientes con repositorios GitHub separados.

## Archivos en esta carpeta
- `index.html` — App VAZSE (subir a repo `vazse-bitacora`)
- `index_mmb.html` — App MMB Logistics (subir a repo `mmb-bitacora`, renombrar a `index.html`)
- `CLAUDE.md` — Este archivo de contexto

## Arquitectura

### Frontend
- **Un solo archivo HTML** por empresa (~6000 líneas)
- CSS propio (sin frameworks), JS vanilla, Chart.js, XLSX.js, docx.js
- Alojado en **GitHub Pages** (gratuito, sin servidor)

### Backend
- **Google Apps Script** — expuesto como Web App pública
- **Google Sheets** — base de datos (6 hojas por empresa)
- Comunicación via **JSONP** (GET con callback) — evita CORS
- Escrituras via `?action=write&data={...}&cb=callbackName`
- Lecturas via GET normal que devuelve JSON

### Repositorios GitHub
| Empresa | Repo | URL | Apps Script URL |
|---------|------|-----|-----------------|
| VAZSE | `luivazcor-afk/vazse-bitacora` | `luivazcor-afk.github.io/vazse-bitacora` | Ver `SCRIPT_URL_BASE` en index.html |
| MMB | `luivazcor-afk/mmb-bitacora` | `luivazcor-afk.github.io/mmb-bitacora` | Ver `SCRIPT_URL_BASE` en index_mmb.html |

### Google Sheets
| Empresa | Sheet ID |
|---------|----------|
| VAZSE | `1zHMu0Jtf3i5xtlaYqoVX8x6yuRUmuEq7OfxGgRd1MBs` |
| MMB | `1pzNFkbAFDQ0MifP8uYGfif8eDNkBYT0ZxGJMQZXrWtQ` |

### Estructura Google Sheets (6 hojas por empresa)
| Hoja | Columnas clave |
|------|---------------|
| Bitácora | Fecha, Tipo, Cliente, Proveedor, Origen, Destino, Costo, Cobrado, Utilidad, Referencia, Notas, Contenedor, Patio, Maniobras$, ManiobrasCobrado, ManiobrasDesc, URLEir, EstadoFlete |
| Cobranza | Folio, Cliente, Monto, FechaEmisión, FechaVencimiento, Estado, Referencia, URLFactura, URLEvidencia, Notas, FechaCobro, Seguimiento(JSON) |
| Proveedores | Folio, Proveedor, Monto, Fecha, FechaPago, Estado, Referencia, Concepto, Notas |
| Clientes | Nombre, RFC, Contacto, Teléfono, Email, Notas, Domicilios(JSON) |
| ProveedoresCat | Nombre, RFC, Tipo, Contacto, Tel1, Tel2, Email1, Email2, Cuentas(JSON), Notas |
| ServiciosCat | Nombre, ClaveSAT, UnidadSAT, Descripción, Retención(si/no) |

## Credenciales de acceso
| Empresa | Usuario | Contraseña |
|---------|---------|-----------|
| VAZSE | `vazse` | `vazse2024` |
| MMB | `mmb` | `mmb2024` |
Credenciales hardcodeadas en `_CREDS` al inicio del bloque `<script>`.

## Módulos del sistema (pestañas)
1. **Viajes** — CRUD con autocompletado catálogos, panel flete condicional, nav mes ‹ ›, botón 📄 Carta, pipeline estado flete clickeable
2. **Cobranza** — CRUD con badge seguimiento 💬, botón 📧 3 estados (pendiente→enviado→recibida)
3. **Reportes** — Mensual, Flujo caja, Comparativo, Top5, Por cliente/proveedor/tipo, Cobranza, 💹 Rentabilidad
4. **Contenedores** — Búsqueda + timeline por contenedor
5. **Historial** — Log de cambios
6. **Proveedores** — Balance por proveedor (total/pagado/pendiente) + CRUD pagos
7. **Catálogos** — Clientes/Proveedores/Servicios con CRUD completo + edición modal

## Funciones JS clave (202 total)
```
cargar()              — fetch Google Sheets, actualiza viajes/cobranzas/pagos/catálogos
sendPost(payload)     — escribe en Sheets via JSONP
sendPostJsonp(payload)— JSONP directo con callback único
filtrarViajes()       — filtra y renderiza tabla de viajes
filtrarCobranza()     — filtra y renderiza tabla de cobranza
filtrarPagos()        — filtra y renderiza proveedores
agregarViaje()        — valida + guarda nuevo viaje
abrirCarta(viajeId)  — abre modal carta instrucciones prellenada
descargarCarta()      — genera DOCX con docx.js
efBadge(estado,id)   — renderiza pipeline clickeable de estado flete
avanzarFlete(id,est) — avanza estado flete y guarda en Sheets
validarCampos(ids)   — validación visual con shake + mensaje inline
hacerLogin()          — valida credenciales hardcodeadas
verificarAuth()       — checa localStorage 'vazse_auth'
renderReporteRentabilidad() — reporte por cliente con KPIs + barra
exportarRentabilidad()      — exporta a Excel
```

## Pipeline Estado Flete
Estados: `libre` → `en_patio` → `en_proceso` → `entregado`
- **Click** en badge = avanza al siguiente estado
- **Hover** = menú para saltar a cualquier estado
- Migración automática de estados viejos (en_transito → libre)

## Carta de Instrucciones (DOCX)
- Botón 📄 en cada fila de Viajes
- Modal 7 secciones prellenadas desde viaje + catálogo clientes
- Descarga via `Packer.toBlob()` de docx.js (CDN unpkg)
- Librerías: `https://unpkg.com/docx@8.5.0/build/index.umd.js`

## Apps Script — Actions soportadas
```
append              — nuevo viaje
update_viaje        — actualiza viaje por referencia (col 9)
delete              — elimina viaje por fecha+tipo+costo
cobranza_append     — nueva cobranza
cobranza_update     — actualiza cobranza por folio
cobranza_update_estado — actualiza solo estado cobranza
cobranza_delete     — elimina cobranza por folio
pago_append         — nuevo pago proveedor
pago_update_estado  — actualiza estado pago
pago_delete         — elimina pago
catalogo_append     — nuevo cliente/proveedor/servicio
catalogo_update     — edita catálogo por nombreViejo
catalogo_delete     — elimina catálogo por nombre
upload_file         — sube archivo a Google Drive
```

## Diferencias VAZSE vs MMB

| Aspecto | VAZSE | MMB |
|---------|-------|-----|
| Color principal | `#185FA5` (azul) | `#CC0000` (rojo) |
| Logo | `logo_R_resolucion.png` | `MMB_LOGO.png` |
| Favicon | `logo_R_resolucion.png` | `MMB_LOBO.png` |
| Correo factura | `fvalencia@grupovazse.com.mx` | `facturacion@mmblogistics.com` |
| Apps Script | Script propio VAZSE | Script propio MMB |
| Sheet | Sheet VAZSE | Sheet MMB |
| Firma carta | Resolución Estratégica VAZSE, S.A. de C.V. | MMB LOGISTICS |

## Pendientes de mejora (roadmap)
- [ ] Vista de tarjetas en móvil (tabla tiene min-width:800px)
- [ ] Skeleton loader durante carga
- [ ] Modo oscuro con toggle en header
- [ ] Búsqueda global (salta a pestaña correcta)
- [ ] Duplicar viaje con fecha actualizada automáticamente
- [ ] Alertas de vencimiento próximo en cobranza (3 días)
- [ ] Confirmación de eliminación con nombre del registro
- [ ] Plantillas de rutas frecuentes
- [ ] Exportar carta instrucciones a PDF (window.print)
- [ ] Notificaciones WhatsApp al cambiar estado a ENTREGADO (integrar con SOIA)
- [ ] Resumen semanal automático via Apps Script trigger

## Notas técnicas importantes
- JSONP no soporta `!important` en inline styles — usar `cssText`
- `ProveedoresCat` requiere 10 columnas — script auto-expande con `insertColumnAfter`
- `ServiciosCat` columna E: `r[4] === 'si' ? 'si' : 'no'` (NO `r[4]||'si'`)
- `Clientes` requiere columna G = Domicilios (JSON array)
- Login guarda token en `localStorage('vazse_auth')` = `btoa(user:timestamp)`
- `Packer.toBuffer()` es Node.js only — en browser usar `Packer.toBlob()`
- Apps Script: republica como **Nueva versión** cada vez que cambies código
- Taskkill SOIA: `taskkill /f /im node.exe` (no Ctrl+C para no perder sesión WhatsApp)

## Comandos útiles para pruebas (consola F12)
```javascript
// Verificar conexión
fetch(SCRIPT_URL_BASE+'?v=1').then(r=>r.json()).then(d=>console.log('Registros:',d.registros?.length))

// Verificar estado
console.log('Online:', estaOnline(), '| Viajes:', viajes.length, '| SheetId:', getSheetId())

// Limpiar sesión
localStorage.removeItem('vazse_auth'); location.reload();
```
