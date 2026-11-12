-- 01_create_tables.sql
-- Creacion de tablas (sin indices secundarios ni claves foraneas: ver 02 y 03).
-- Compatible con MySQL 8+ y MariaDB 10.6+.

-- 4.1 Usuarios permitidos: quien puede acceder al servidor MCP y con que rol.
CREATE TABLE IF NOT EXISTS `allowed_users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `email`      VARCHAR(190) NOT NULL,
  `role`       ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
  `status`     ENUM('active','suspended','disabled') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.2 Tokens de acceso personales (STDIO / automatizacion). NO son tokens OAuth.
-- Solo se guarda el hash HMAC-SHA256 del token; el valor en claro se muestra una unica vez al crearlo.
CREATE TABLE IF NOT EXISTS `tokens` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `label`         VARCHAR(120) NOT NULL DEFAULT '',
  `token_hash`    CHAR(64) NOT NULL,
  `scopes`        VARCHAR(255) NOT NULL DEFAULT 'records:read records:write records:delete',
  `issued_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`    DATETIME NOT NULL,
  `status`        ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `revoked_at`    DATETIME NULL DEFAULT NULL,
  `last_used_at`  DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.3 Identidades OAuth externas (Google, GitHub, Keycloak...) vinculadas a usuarios permitidos.
CREATE TABLE IF NOT EXISTS `oauth_users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`      VARCHAR(60) NOT NULL,
  `external_id`   VARCHAR(190) NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `name`          VARCHAR(190) NOT NULL DEFAULT '',
  `linked_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `status`        ENUM('active','revoked') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servidor de autorizacion OAuth 2.1 embebido (league/oauth2-server)
CREATE TABLE IF NOT EXISTS `oauth_clients` (
  `id`            VARCHAR(80) NOT NULL,
  `name`          VARCHAR(190) NOT NULL,
  `secret_hash`   VARCHAR(255) NULL DEFAULT NULL,
  `redirect_uris` TEXT NOT NULL,
  `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
  `status`        ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_auth_codes` (
  `id`         VARCHAR(100) NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `client_id`  VARCHAR(80) NOT NULL,
  `scopes`     VARCHAR(255) NOT NULL DEFAULT '',
  `revoked`    TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_access_tokens` (
  `id`         VARCHAR(100) NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `client_id`  VARCHAR(80) NOT NULL,
  `scopes`     VARCHAR(255) NOT NULL DEFAULT '',
  `revoked`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_refresh_tokens` (
  `id`              VARCHAR(100) NOT NULL,
  `access_token_id` VARCHAR(100) NOT NULL,
  `revoked`         TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at`      DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eventos de seguridad (login, rechazos, revocaciones, errores de autorizacion...)
CREATE TABLE IF NOT EXISTS `security_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(80) NOT NULL,
  `severity`   ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  `user_id`    INT UNSIGNED NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `details`    TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.4 Tabla de datos genericos para probar el CRUD.
CREATE TABLE IF NOT EXISTS `sample_records` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`   VARCHAR(80) NOT NULL,
  `last_name`    VARCHAR(80) NOT NULL,
  `email`        VARCHAR(190) NOT NULL,
  `birth_date`   DATE NULL DEFAULT NULL,
  `event_date`   DATE NULL DEFAULT NULL,
  `title`        VARCHAR(150) NOT NULL,
  `short_text`   VARCHAR(255) NULL DEFAULT NULL,
  `description`  TEXT NULL,
  `quantity`     INT NOT NULL DEFAULT 0,
  `price`        DECIMAL(12,2) NULL DEFAULT NULL,
  `rating`       DECIMAL(4,2) NULL DEFAULT NULL,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `category`     VARCHAR(50) NOT NULL,
  `status`       ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  `notes`        TEXT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
