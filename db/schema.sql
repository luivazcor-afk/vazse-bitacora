-- Bitácora VAZSE — esquema MySQL/MariaDB
-- Reemplaza las hojas de Google Sheets (Bitácora, Cobranza, Proveedores,
-- Clientes, ProveedoresCat, ServiciosCat, Cotizaciones, Config).
-- Fechas: se guardan como DATE en hora de México (el Sheet las exponía
-- como medianoche local = 06:00Z).

CREATE DATABASE IF NOT EXISTS vazse_bitacora
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vazse_bitacora;

-- Hoja "Bitácora". La referencia NO es única (hay viajes que la comparten),
-- por eso cada viaje tiene su propio id.
CREATE TABLE IF NOT EXISTS viajes (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha             DATE NULL,
  tipo              VARCHAR(60)   NOT NULL DEFAULT '',
  cliente           VARCHAR(150)  NOT NULL DEFAULT '',
  unidad            VARCHAR(150)  NOT NULL DEFAULT '',  -- proveedor / transportista
  origen            VARCHAR(120)  NOT NULL DEFAULT '',
  destino           VARCHAR(120)  NOT NULL DEFAULT '',
  costo             DECIMAL(12,2) NOT NULL DEFAULT 0,
  cobrado           DECIMAL(12,2) NOT NULL DEFAULT 0,
  utilidad          DECIMAL(12,2) NOT NULL DEFAULT 0,
  referencia        VARCHAR(60)   NOT NULL DEFAULT '',
  notas             TEXT          NULL,
  contenedor        VARCHAR(30)   NOT NULL DEFAULT '',
  patio             VARCHAR(60)   NOT NULL DEFAULT '',
  maniobras         DECIMAL(12,2) NOT NULL DEFAULT 0,
  maniobras_cobrado DECIMAL(12,2) NOT NULL DEFAULT 0,
  maniobras_desc    VARCHAR(255)  NOT NULL DEFAULT '',
  url_eir           VARCHAR(500)  NOT NULL DEFAULT '',
  estado_flete      VARCHAR(20)   NOT NULL DEFAULT '',
  usuario           VARCHAR(60)   NOT NULL DEFAULT '',
  creado_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fecha (fecha),
  KEY idx_referencia (referencia),
  KEY idx_contenedor (contenedor),
  KEY idx_cliente (cliente)
) ENGINE=InnoDB;

-- Hoja "Cobranza"
CREATE TABLE IF NOT EXISTS cobranza (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  folio             VARCHAR(40)   NOT NULL,
  cliente           VARCHAR(150)  NOT NULL DEFAULT '',
  monto             DECIMAL(12,2) NOT NULL DEFAULT 0,
  fecha_emision     DATE NULL,
  fecha_vencimiento DATE NULL,
  estado            VARCHAR(20)   NOT NULL DEFAULT 'pendiente',
  referencia        VARCHAR(60)   NOT NULL DEFAULT '',
  url_factura       VARCHAR(500)  NOT NULL DEFAULT '',
  url_evidencia     VARCHAR(500)  NOT NULL DEFAULT '',
  notas             TEXT          NULL,
  fecha_cobro       DATE NULL,
  seguimiento       JSON          NULL,
  abonos            JSON          NULL,
  usuario           VARCHAR(60)   NOT NULL DEFAULT '',
  creado_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_folio (folio),
  KEY idx_estado (estado),
  KEY idx_referencia (referencia)
) ENGINE=InnoDB;

-- Hoja "Proveedores" (movimientos / pagos a proveedores)
CREATE TABLE IF NOT EXISTS pagos (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  folio             VARCHAR(40)   NOT NULL,
  proveedor         VARCHAR(150)  NOT NULL DEFAULT '',
  monto             DECIMAL(12,2) NOT NULL DEFAULT 0,
  fecha             DATE NULL,
  fecha_pago        DATE NULL,
  estado            VARCHAR(20)   NOT NULL DEFAULT 'pendiente',
  referencia        VARCHAR(60)   NOT NULL DEFAULT '',
  concepto          VARCHAR(255)  NOT NULL DEFAULT '',
  notas             TEXT          NULL,
  usuario           VARCHAR(60)   NOT NULL DEFAULT '',
  creado_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_folio (folio),
  KEY idx_proveedor (proveedor),
  KEY idx_referencia (referencia)
) ENGINE=InnoDB;

-- Hoja "Clientes"
CREATE TABLE IF NOT EXISTS clientes (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre            VARCHAR(150)  NOT NULL,
  rfc               VARCHAR(20)   NOT NULL DEFAULT '',
  contacto          VARCHAR(120)  NOT NULL DEFAULT '',
  tel               VARCHAR(40)   NOT NULL DEFAULT '',
  email             VARCHAR(150)  NOT NULL DEFAULT '',
  notas             TEXT          NULL,
  domicilios        JSON          NULL,
  UNIQUE KEY uk_nombre (nombre)
) ENGINE=InnoDB;

-- Hoja "ProveedoresCat"
CREATE TABLE IF NOT EXISTS proveedores_cat (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre            VARCHAR(150)  NOT NULL,
  rfc               VARCHAR(20)   NOT NULL DEFAULT '',
  tipo              VARCHAR(40)   NOT NULL DEFAULT '',
  contacto          VARCHAR(120)  NOT NULL DEFAULT '',
  tel1              VARCHAR(40)   NOT NULL DEFAULT '',
  tel2              VARCHAR(40)   NOT NULL DEFAULT '',
  email1            VARCHAR(150)  NOT NULL DEFAULT '',
  email2            VARCHAR(150)  NOT NULL DEFAULT '',
  cuentas           JSON          NULL,
  notas             TEXT          NULL,
  UNIQUE KEY uk_nombre (nombre)
) ENGINE=InnoDB;

-- Hoja "ServiciosCat"
CREATE TABLE IF NOT EXISTS servicios_cat (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre            VARCHAR(150)  NOT NULL,
  clave_sat         VARCHAR(20)   NOT NULL DEFAULT '',
  unidad_sat        VARCHAR(10)   NOT NULL DEFAULT '',
  descripcion       VARCHAR(255)  NOT NULL DEFAULT '',
  retencion         ENUM('si','no') NOT NULL DEFAULT 'no',
  UNIQUE KEY uk_nombre (nombre)
) ENGINE=InnoDB;

-- Hoja "Cotizaciones"
CREATE TABLE IF NOT EXISTS cotizaciones (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  folio             VARCHAR(40)   NOT NULL,
  fecha             DATE NULL,
  cliente           VARCHAR(150)  NOT NULL DEFAULT '',
  unidad            VARCHAR(30)   NOT NULL DEFAULT '',
  unidad_nombre     VARCHAR(80)   NOT NULL DEFAULT '',
  origen            VARCHAR(120)  NOT NULL DEFAULT '',
  destino           VARCHAR(120)  NOT NULL DEFAULT '',
  km                DECIMAL(10,2) NOT NULL DEFAULT 0,
  redondo           VARCHAR(20)   NOT NULL DEFAULT '',
  casetas           DECIMAL(12,2) NOT NULL DEFAULT 0,
  maniobras         DECIMAL(12,2) NOT NULL DEFAULT 0,
  otros             DECIMAL(12,2) NOT NULL DEFAULT 0,
  costo             DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio            DECIMAL(12,2) NOT NULL DEFAULT 0,
  margen            DECIMAL(6,2)  NOT NULL DEFAULT 0,
  estado            VARCHAR(20)   NOT NULL DEFAULT 'borrador',
  vigencia          INT           NOT NULL DEFAULT 15,
  notas             TEXT          NULL,
  UNIQUE KEY uk_folio (folio)
) ENGINE=InnoDB;

-- Cuentas de acceso (una por persona). nombre es lo que se guarda en el
-- campo "usuario" de viajes, cobranza y pagos.
CREATE TABLE IF NOT EXISTS usuarios (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario           VARCHAR(40)   NOT NULL,
  nombre            VARCHAR(60)   NOT NULL,
  password_hash     VARCHAR(255)  NOT NULL,
  rol               ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
  activo            TINYINT(1)    NOT NULL DEFAULT 1,
  debe_cambiar      TINYINT(1)    NOT NULL DEFAULT 0,  -- forzar cambio de contraseña al entrar
  creado_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_acceso     TIMESTAMP     NULL,
  UNIQUE KEY uk_usuario (usuario)
) ENGINE=InnoDB;

-- Intentos de login fallidos, para bloquear ataques de fuerza bruta
CREATE TABLE IF NOT EXISTS login_intentos (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario           VARCHAR(40)   NOT NULL,
  ip                VARCHAR(45)   NOT NULL,
  fecha             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_usuario_fecha (usuario, fecha),
  KEY idx_ip_fecha (ip, fecha)
) ENGINE=InnoDB;

-- Archivos subidos (facturas, evidencias, EIR). Reemplaza a Google Drive.
-- El archivo vive fuera de la carpeta web; solo se descarga con sesión (archivos.php).
CREATE TABLE IF NOT EXISTS archivos (
  id                CHAR(32)      NOT NULL PRIMARY KEY,   -- aleatorio, va en la URL
  nombre            VARCHAR(255)  NOT NULL,               -- nombre original
  mime              VARCHAR(100)  NOT NULL,
  tamano            INT UNSIGNED  NOT NULL,
  ruta              VARCHAR(255)  NOT NULL,               -- relativa a la carpeta de archivos
  subido_por        VARCHAR(60)   NOT NULL DEFAULT '',
  subido_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Copia de solo lectura de datos del otro sistema de captura (referencia →
-- cliente, contenedor, peso, bultos, mercancía, BL). La llena
-- tareas/sincronizar_externo.php; la Bitácora la consulta para precargar
-- la captura de viajes y la Carta de Instrucciones. No se edita a mano.
-- Una referencia puede tener varios contenedores: una fila por cada uno.
CREATE TABLE IF NOT EXISTS referencias_externas (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referencia        VARCHAR(60)   NOT NULL,               -- normalizada: sin espacios, mayúsculas
  cliente           VARCHAR(150)  NOT NULL DEFAULT '',
  contenedor        VARCHAR(30)   NOT NULL DEFAULT '',    -- normalizado igual que la referencia
  peso              VARCHAR(30)   NOT NULL DEFAULT '',    -- texto tal cual viene (ej. "7,518.000")
  bultos            VARCHAR(30)   NOT NULL DEFAULT '',
  mercancia         VARCHAR(500)  NOT NULL DEFAULT '',
  bl                VARCHAR(60)   NOT NULL DEFAULT '',
  naviera           VARCHAR(150)  NOT NULL DEFAULT '',
  tipo_contenedor   VARCHAR(30)   NOT NULL DEFAULT '',    -- tamaño/tipo, ej. "40 HC"
  regimen           VARCHAR(80)   NOT NULL DEFAULT '',    -- ej. "DEFINITIVO DE IMPORTACIÓN"
  factura           VARCHAR(200)  NOT NULL DEFAULT '',
  sincronizado_en   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ref_cont (referencia, contenedor),
  KEY idx_contenedor (contenedor)
) ENGINE=InnoDB;

-- Para bases creadas antes de que existieran estas columnas
ALTER TABLE referencias_externas
  ADD COLUMN IF NOT EXISTS naviera         VARCHAR(150) NOT NULL DEFAULT '' AFTER bl,
  ADD COLUMN IF NOT EXISTS tipo_contenedor VARCHAR(30)  NOT NULL DEFAULT '' AFTER naviera,
  ADD COLUMN IF NOT EXISTS regimen         VARCHAR(80)  NOT NULL DEFAULT '' AFTER tipo_contenedor,
  ADD COLUMN IF NOT EXISTS factura         VARCHAR(200) NOT NULL DEFAULT '' AFTER regimen;

-- Hoja "Config" (pares clave/valor)
CREATE TABLE IF NOT EXISTS config (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave             VARCHAR(60)   NOT NULL,
  valor             TEXT          NULL,
  UNIQUE KEY uk_clave (clave)
) ENGINE=InnoDB;
