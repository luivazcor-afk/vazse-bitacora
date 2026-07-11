# CLAUDE.md — Bitácora VAZSE (vazse-bitacora)

## Proyecto
App web single-file (index.html) para control de viajes, cobranza y catálogos de Resolución Estratégica VAZSE (Grupo Vazse, Manzanillo). Desplegada en GitHub Pages (`luivazcor-afk/vazse-bitacora`). Backend: Google Sheets + Apps Script v9 (JSONP).

## Arquitectura
- **Frontend:** `index.html` — single-file app (HTML + CSS + JS ~350KB), Bootstrap-free, vanilla JS, Chart.js, SheetJS (xlsx export), docx (carta de instrucciones)
- **Backend:** Google Apps Script v9 (`Codigo_v9.gs`) — lectura por GET (JSONP), escritura por GET con `?action=write&data=...`
- **Storage:** Google Sheet `1zHMu0Jtf3i5xtlaYqoVX8x6yuRUmuEq7OfxGgRd1MBs`
- **Deployment:** GitHub Pages desde rama `main`, `/ (root)`
- **Auth:** Login SHA-256 con hash en `config.json` (no en el HTML)

## Hojas del Sheet (orden de columnas)
### Bitácora (18 cols)
`fecha, tipo, cliente, unidad, origen, destino, costo, cobrado, utilidad, referencia, notas, contenedor, patio, maniobras, maniobrasCobrado, maniobrasDesc, urlEir, estadoFlete`

### Cobranza (12 cols)
`folio, cliente, monto, fechaEmision, fechaVencimiento, estado, referencia, urlFactura, urlEvidencia, notas, fechaCobro, seguimiento(JSON)`

### Proveedores/Pagos (9 cols)
`folio, proveedor, monto, fecha, fechaPago, estado, referencia, concepto, notas`

### Clientes (7 cols)
`nombre, rfc, contacto, tel, email, notas, domicilios(JSON)`

### ProveedoresCat (10 cols)
`nombre, rfc, tipo, contacto, tel1, tel2, email, email2, cuentas(JSON), notas`

### ServiciosCat (5 cols)
`nombre, clave, unidad, desc, retencion`

## Apps Script v9 — acciones de escritura (13)
| Acción | Tabla | Operación |
|--------|-------|-----------|
| `append` | Bitácora | Agregar viaje |
| `update_viaje` | Bitácora | Editar viaje (busca por referencia) |
| `delete` | Bitácora | Eliminar viaje (busca por fecha+tipo+costo) |
| `cobranza_append` | Cobranza | Agregar factura (folio auto si vacío) |
| `cobranza_update` | Cobranza | Editar factura |
| `cobranza_update_estado` | Cobranza | Cambiar estado |
| `cobranza_delete` | Cobranza | Eliminar factura |
| `pago_append` | Proveedores | Agregar pago |
| `pago_update_estado` | Proveedores | Cambiar estado de pago |
| `pago_delete` | Proveedores | Eliminar pago |
| `catalogo_append` | Clientes/Prov/Svc | Agregar registro |
| `catalogo_update` | Clientes/Prov/Svc | Editar registro |
| `catalogo_delete` | Clientes/Prov/Svc | Eliminar registro |

## Bugs conocidos / patrones recurrentes
1. **CSS dark mode perdido:** La consolidación original a `main` borró reglas CSS base (light mode) de sub-paneles. Patrón: si algo se ve mal en dark mode → buscar si falta el override `html[data-theme=dark]`
2. **Colores inline hardcodeados:** Las funciones `renderReporte*` generan HTML con estilos inline. Usar `var(--color-text-primary,#1C1C1A)` con fallback para que funcione en ambos modos
3. **JSONP:** El Apps Script devuelve `cb({...})` — el HTML lo desenvuelve con regex antes de parsear
4. **Arrays vs objetos:** El Sheet devuelve arrays crudos — `normalizarDatosV30()` los convierte a objetos con nombre
5. **`getScriptUrlBase()` dinámica:** La URL del script se lee de `CFG.scriptUrl` en el momento del fetch, no al arrancar (porque `config.json` carga async)
6. **Fechas con hora:** Google Sheets devuelve `"2026-03-25T06:00:00.000Z"` — la función `normFecha()` extrae `YYYY-MM-DD` sin recalcular zona horaria para evitar corrimiento
7. **Estado cobranza:** El Sheet usa "vencida"/"pagada" (femenino) — `normalizarDatosV30` los normaliza a "vencido"/"pagado" para el código JS

## Config
- **config.json** en raíz del repo: `scriptUrl`, `authHash`, `authSalt`, servicios, clientes, etc.
- **Deployment URL Apps Script:** `AKfycbzgiS_AHAY8-F8rEYgummuEe4ApWoSw2dinm8jLC3eu0AOJ85dLUZx9yfbmWGjbof_V`
- **Email alertas:** `ejecutivo2@grupovazse.com.mx`

## Pestañas de la app
1. **Resumen** — KPIs, gráficas, top 5 clientes, facturas en riesgo (estilo IBM Plex)
2. **Viajes** — CRUD con autocomplete, skeleton loaders, plantillas de rutas rápidas
3. **Cobranza** — CRUD con banner de vencimiento, resumen por cliente, días de crédito
4. **Reportes** — 10 sub-reportes: mensual, flujo de caja, comparativo, top 5, por cliente/proveedor/tipo, cobranza, rentabilidad
5. **Contenedores** — Búsqueda por contenedor con agrupación
6. **Historial** — Log de acciones
7. **Proveedores** — Pagos a proveedores
8. **Catálogos** — Clientes, proveedores, servicios (con sub-tabs)

## Roadmap pendiente (6 ítems)
- [ ] Línea de tiempo por contenedor
- [ ] Días de crédito automático (CSS badges implementados, falta refinar)
- [ ] Resumen por cliente en cobranza (implementado, falta pulir dark mode)
- [ ] Reporte mensual PDF con membrete
- [ ] Reporte de contenedores
- [ ] Importar desde Excel

---
## Historial de cambios (sesión jul 2026)

### 2026-07-06 — Recuperación de diseño + conexión al Sheet
- **Rama `recuperacion-diseno-v2`** creada desde `main`
- Funciones recuperadas de `dev-pruebas`: modo oscuro (190+ reglas CSS), plantillas de rutas, skeleton loaders, banner de vencimiento, selector de domicilios en carta, búsqueda global ampliada (clientes/prov/svc + nav teclado)
- CSS base de `.cat-panel`, `.cat-tab`, `.report-panel`, `.report-tab` restaurados (perdidos en consolidación a main)
- Animación `tabSwitch` restaurada

### 2026-07-07 — Conexión al Sheet (bug crítico)
- **Bug:** `SCRIPT_URL_BASE` se congelaba vacío al arrancar (const evaluada antes de cargar config.json) → fetch iba a github.io en vez de al Apps Script
- **Fix:** `getScriptUrlBase()` función dinámica que lee `CFG.scriptUrl` en el momento del fetch
- **Bug:** Apps Script v30 responde en JSONP (`cb({...})`) → `res.json()` tronaba
- **Fix:** Desenvuelve JSONP con regex antes de parsear
- **Bug:** Apps Script v30 devuelve arrays crudos → `r.costo` etc. undefined
- **Fix:** `normalizarDatosV30()` convierte arrays a objetos por posición de columna
- **Fix:** Estado "vencida"→"vencido", "cobrada"→"pagado" normalizado
- **Fix:** `window.cb` como alias del callback JSONP para escrituras

### 2026-07-07 — Apps Script v9
- `doGet` ahora maneja `?action=write&data=...` (antes solo leía)
- 13 acciones de escritura implementadas con orden de columnas verificado
- `normFecha()` robusto: extrae YYYY-MM-DD de ISO sin recalcular TZ
- Automatizaciones (triggers, emails, backup) preservadas de v8
- Test de 18 pruebas: 18/18 pasaron (5 "fallos" eran formato de fecha del test)

### 2026-07-07 — Pestaña Resumen Ejecutivo
- Construida nativa con datos en vivo (reemplaza bundle viejo de 24MB)
- Estética IBM Plex del dashboard original (paleta, tipografía mono, KPI hero card)
- 2 gráficas (utilidad mensual, dona cobranza) + 2 tablas (top 5, facturas riesgo)
- Compatible con modo oscuro

### 2026-07-07 — Roadmap avances
- Badges de días de crédito: CSS base (light mode) restaurado
- Resumen por cliente en cobranza: tarjetas con desglose, barra de progreso, clic-para-filtrar
- `@import` IBM Plex movido al inicio del `<style>` (fix warning CSS)

### 2026-07-07 — Dark mode texto en reportes
- 38 colores inline hardcodeados reemplazados por `var(--color-text-*)` con fallback
- 15 overrides dark mode agregados para `tbody td`, `.modal-title`, `.report-panel td/th/h3`, `.autocomplete-item`, `.bal-total`, etc.
