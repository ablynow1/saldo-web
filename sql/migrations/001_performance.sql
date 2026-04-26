-- SALDO WEB — Migration 001: Performance, criativos e agendamento de relatórios
-- Compatível com MySQL 5.7+ e MariaDB 10.4+
-- Cada ADD COLUMN em ALTER TABLE separado para que erros de coluna já existente
-- sejam ignorados individualmente pelo instalador.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────
-- Snapshots de performance por anúncio (nível ad — mais granular)
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ad_insights` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `meta_account_id`   INT UNSIGNED    NOT NULL,
  `ad_id`             VARCHAR(64)     NOT NULL COMMENT 'ID do anúncio no Meta',
  `ad_name`           VARCHAR(512)    NULL,
  `adset_id`          VARCHAR(64)     NULL,
  `adset_name`        VARCHAR(512)    NULL,
  `campaign_id`       VARCHAR(64)     NULL,
  `campaign_name`     VARCHAR(512)    NULL,
  `date_start`        DATE            NOT NULL,
  `date_stop`         DATE            NOT NULL,
  `spend`             DECIMAL(18,4)   NOT NULL DEFAULT 0,
  `impressions`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `clicks`            INT UNSIGNED    NOT NULL DEFAULT 0,
  `reach`             BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `conversions`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `conversion_value`  DECIMAL(18,4)   NOT NULL DEFAULT 0,
  `leads`             INT UNSIGNED    NOT NULL DEFAULT 0,
  `ctr`               DECIMAL(10,4)   NOT NULL DEFAULT 0 COMMENT 'clicks/impressions * 100',
  `cpc`               DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT 'spend/clicks',
  `cpa`               DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT 'spend/conversions',
  `roas`              DECIMAL(10,4)   NOT NULL DEFAULT 0 COMMENT 'conversion_value/spend',
  `cpp`               DECIMAL(18,4)   NOT NULL DEFAULT 0 COMMENT 'custo por 1k impressões',
  `effective_status`  VARCHAR(64)     NULL COMMENT 'ACTIVE, PAUSED, DISAPPROVED...',
  `thumbnail_url`     VARCHAR(1024)   NULL COMMENT 'URL da imagem do criativo',
  `collected_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_ad_date` (`ad_id`, `date_start`),
  KEY `idx_account_date` (`meta_account_id`, `date_start`),
  KEY `idx_campaign_date` (`campaign_id`, `date_start`),
  CONSTRAINT `fk_adinsights_account` FOREIGN KEY (`meta_account_id`) REFERENCES `meta_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- Snapshots de performance por campanha (nível campaign)
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `campaign_insights` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `meta_account_id`   INT UNSIGNED    NOT NULL,
  `campaign_id`       VARCHAR(64)     NOT NULL,
  `campaign_name`     VARCHAR(512)    NULL,
  `date_start`        DATE            NOT NULL,
  `date_stop`         DATE            NOT NULL,
  `spend`             DECIMAL(18,4)   NOT NULL DEFAULT 0,
  `impressions`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `clicks`            INT UNSIGNED    NOT NULL DEFAULT 0,
  `conversions`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `conversion_value`  DECIMAL(18,4)   NOT NULL DEFAULT 0,
  `leads`             INT UNSIGNED    NOT NULL DEFAULT 0,
  `ctr`               DECIMAL(10,4)   NOT NULL DEFAULT 0,
  `cpa`               DECIMAL(18,4)   NOT NULL DEFAULT 0,
  `roas`              DECIMAL(10,4)   NOT NULL DEFAULT 0,
  `effective_status`  VARCHAR(64)     NULL,
  `collected_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_campaign_date` (`campaign_id`, `date_start`),
  KEY `idx_account_campaign_date` (`meta_account_id`, `date_start`),
  CONSTRAINT `fk_campinsights_account` FOREIGN KEY (`meta_account_id`) REFERENCES `meta_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- Relatórios agendados por cliente
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `report_schedules` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `client_id`   INT UNSIGNED NOT NULL,
  `report_type` ENUM('daily_creatives','weekly_summary','monthly_summary','weekend_forecast') NOT NULL,
  `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
  `send_hour`   TINYINT      NOT NULL DEFAULT 8  COMMENT '0-23',
  `send_minute` TINYINT      NOT NULL DEFAULT 0  COMMENT '0-59',
  `send_day`    TINYINT      NULL COMMENT 'Para weekly/monthly',
  `last_sent_at`DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_client_type` (`client_id`, `report_type`),
  CONSTRAINT `fk_schedule_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- Histórico de relatórios enviados
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `report_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `client_id`   INT UNSIGNED    NOT NULL,
  `report_type` VARCHAR(64)     NOT NULL,
  `message`     TEXT            NOT NULL,
  `sent_ok`     TINYINT(1)      NOT NULL DEFAULT 0,
  `sent_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_client_type` (`client_id`, `report_type`, `sent_at`),
  CONSTRAINT `fk_replog_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- Colunas de thresholds de performance na meta_accounts
-- Uma por ALTER TABLE para MySQL 5.7 (sem suporte a IF NOT EXISTS)
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `meta_accounts` ADD COLUMN `alert_roas_min`   DECIMAL(10,4) NULL DEFAULT NULL COMMENT 'Alerta se ROAS < esse valor';
ALTER TABLE `meta_accounts` ADD COLUMN `alert_cpa_max`    DECIMAL(18,4) NULL DEFAULT NULL COMMENT 'Alerta se CPA > esse valor';
ALTER TABLE `meta_accounts` ADD COLUMN `alert_ctr_min`    DECIMAL(10,4) NULL DEFAULT NULL COMMENT 'Alerta se CTR < esse valor';
ALTER TABLE `meta_accounts` ADD COLUMN `report_daily`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Receber relatório diário';
ALTER TABLE `meta_accounts` ADD COLUMN `collect_insights` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Coletar métricas de performance';

-- Novos tipos de alerta no ENUM
ALTER TABLE `alerts_log`
  MODIFY COLUMN `alert_type` ENUM(
    'low_balance','spend_cap_near','account_blocked','no_funding',
    'roas_drop','cpa_spike','ctr_drop','ad_disapproved','budget_overpace'
  ) NOT NULL;

-- Seed: novos settings
INSERT INTO `settings` (`key_name`, `value`, `is_encrypted`) VALUES
  ('insights_collect_hours', '1', 0),
  ('report_timezone', 'America/Sao_Paulo', 0),
  ('conversion_event', 'purchase', 0)
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

SET FOREIGN_KEY_CHECKS = 1;
