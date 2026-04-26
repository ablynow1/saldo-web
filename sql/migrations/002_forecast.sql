-- ============================================================================
-- Migration 002 — Monitoramento inteligente de saldo
-- Compatível com MySQL 5.7+ (sem ADD COLUMN IF NOT EXISTS)
-- Cada coluna em ALTER TABLE separado para que falhas individuais
-- (coluna já existente) sejam capturadas e ignoradas pelo instalador.
-- ============================================================================

ALTER TABLE `meta_accounts` ADD COLUMN `forecast_runway_days`  DECIMAL(8,2)  NULL COMMENT 'Dias estimados até fim do saldo';
ALTER TABLE `meta_accounts` ADD COLUMN `forecast_depletion_at` DATETIME      NULL COMMENT 'Data/hora prevista de término';
ALTER TABLE `meta_accounts` ADD COLUMN `forecast_daily_cents`  DECIMAL(14,2) NULL COMMENT 'Gasto diário ponderado (centavos)';
ALTER TABLE `meta_accounts` ADD COLUMN `forecast_today_cents`  DECIMAL(14,2) NULL COMMENT 'Gasto de hoje até agora (centavos)';
ALTER TABLE `meta_accounts` ADD COLUMN `forecast_trend`        ENUM('accelerating','stable','decelerating') NULL;
ALTER TABLE `meta_accounts` ADD COLUMN `forecast_active_ads`   INT           NULL COMMENT 'Anúncios com status ACTIVE';
ALTER TABLE `meta_accounts` ADD COLUMN `forecast_confidence`   ENUM('high','medium','low') NULL;
ALTER TABLE `meta_accounts` ADD COLUMN `forecast_updated_at`   DATETIME      NULL;

-- Índice para consultas "quais contas estão em alerta de runway?"
ALTER TABLE `meta_accounts` ADD INDEX `idx_runway` (`forecast_runway_days`);
