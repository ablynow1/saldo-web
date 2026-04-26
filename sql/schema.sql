-- SALDO WEB - Schema do banco de dados
-- MySQL 5.7+ / 8.0 compatível

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` VARCHAR(100) NOT NULL PRIMARY KEY,
  `value` TEXT,
  `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(80) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `whatsapp_group_jid` VARCHAR(120) NULL COMMENT 'ex.: 120363012345678901@g.us',
  `whatsapp_group_name` VARCHAR(200) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `meta_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT UNSIGNED NOT NULL,
  `ad_account_id` VARCHAR(64) NOT NULL COMMENT 'sem o prefixo act_',
  `account_name` VARCHAR(200) NULL,
  `currency` VARCHAR(8) NULL,
  `account_type` ENUM('prepaid','postpaid','unknown') NOT NULL DEFAULT 'unknown',
  `threshold_days_runway` DECIMAL(5,2) NOT NULL DEFAULT 2 COMMENT 'pré-pago: alerta se saldo < runway * gasto diário',
  `threshold_spend_cap_pct` DECIMAL(5,2) NOT NULL DEFAULT 80 COMMENT 'pós-pago: alerta se % gasto >= X',
  `last_balance` DECIMAL(18,4) NULL,
  `last_spend_cap` DECIMAL(18,4) NULL,
  `last_amount_spent` DECIMAL(18,4) NULL,
  `last_account_status` INT NULL,
  `last_checked_at` DATETIME NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_ad_account` (`ad_account_id`),
  KEY `idx_client` (`client_id`),
  CONSTRAINT `fk_meta_accounts_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `balance_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `meta_account_id` INT UNSIGNED NOT NULL,
  `balance` DECIMAL(18,4) NULL,
  `spend_cap` DECIMAL(18,4) NULL,
  `amount_spent` DECIMAL(18,4) NULL,
  `account_status` INT NULL,
  `raw_json` MEDIUMTEXT NULL,
  `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_account_time` (`meta_account_id`, `checked_at`),
  CONSTRAINT `fk_snap_account` FOREIGN KEY (`meta_account_id`) REFERENCES `meta_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alerts_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `meta_account_id` INT UNSIGNED NOT NULL,
  `alert_type` ENUM('low_balance','spend_cap_near','account_blocked','no_funding') NOT NULL,
  `severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  `message` TEXT NOT NULL,
  `sent_ok` TINYINT(1) NOT NULL DEFAULT 0,
  `provider_response` TEXT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_account_type_time` (`meta_account_id`, `alert_type`, `sent_at`),
  CONSTRAINT `fk_alerts_account` FOREIGN KEY (`meta_account_id`) REFERENCES `meta_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `check_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  `accounts_checked` INT NOT NULL DEFAULT 0,
  `alerts_fired` INT NOT NULL DEFAULT 0,
  `errors` INT NOT NULL DEFAULT 0,
  `log` MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed de configurações (em branco, preenchidas pelo painel)
INSERT INTO `settings` (`key_name`, `value`, `is_encrypted`) VALUES
  ('meta_system_user_token', '', 1),
  ('meta_api_version', 'v19.0', 0),
  ('evolution_base_url', '', 0),
  ('evolution_api_key', '', 1),
  ('evolution_instance', '', 0),
  ('alert_cooldown_hours', '6', 0)
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;
