-- SALDO WEB — Migration 003: Advanced Reports Funnel Metrics
-- Compatível com MySQL 5.7+ e MariaDB 10.4+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────
-- Colunas de Funil na tabela campaign_insights
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `campaign_insights` ADD COLUMN `cpm` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT 'spend / impressions * 1000';
ALTER TABLE `campaign_insights` ADD COLUMN `landing_page_views` INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `campaign_insights` ADD COLUMN `adds_to_cart` INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `campaign_insights` ADD COLUMN `initiates_checkout` INT UNSIGNED NOT NULL DEFAULT 0;

-- Atualizar o ENUM de relatórios agendados
ALTER TABLE `report_schedules`
  MODIFY COLUMN `report_type` ENUM(
    'daily_creatives',
    'weekly_summary',
    'advanced_weekly_summary',
    'monthly_summary',
    'weekend_forecast'
  ) NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;
