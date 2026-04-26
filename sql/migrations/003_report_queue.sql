-- Migration 003 — Fila de aprovação de relatórios
-- O relatório vai para o WhatsApp do admin com links de Aprovar/Cancelar
-- Só depois de aprovado é disparado pro grupo do cliente.

CREATE TABLE IF NOT EXISTS `report_queue` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `client_id`       INT UNSIGNED    NOT NULL,
  `meta_account_id` INT UNSIGNED    NOT NULL,
  `report_type`     VARCHAR(64)     NOT NULL,
  `group_jid`       VARCHAR(200)    NOT NULL COMMENT 'Destino final (grupo do cliente)',
  `message`         TEXT            NOT NULL COMMENT 'Mensagem já montada, pronta pra enviar',
  `approve_token`   VARCHAR(64)     NOT NULL UNIQUE,
  `status`          ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at`     DATETIME        NULL,
  KEY `idx_token`   (`approve_token`),
  KEY `idx_status`  (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Setting: número do WhatsApp do admin pra receber as aprovações
INSERT INTO `settings` (`key_name`, `value`, `is_encrypted`) VALUES
  ('admin_whatsapp', '', 0),
  ('report_approval', '1', 0)
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;
