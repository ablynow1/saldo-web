-- Migration 005 — Novos status na fila de relatórios
-- 'test_pending' = criado pelo botão 🧪 Testar no painel (envia pro grupo quando aprovado)
-- 'sent'         = já foi disparado automaticamente no horário configurado

ALTER TABLE `report_queue`
  MODIFY COLUMN `status`
    ENUM('pending','approved','rejected','expired','test_pending','sent')
    NOT NULL DEFAULT 'pending';
