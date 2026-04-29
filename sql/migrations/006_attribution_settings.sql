-- SALDO WEB — Migration 006: Attribution Window setting
--
-- Adiciona o setting `attribution_windows` para controlar quais janelas
-- de atribuição são usadas ao consultar a Meta API. O valor é uma lista
-- separada por vírgulas. Valores aceitos:
--   - default            (recomendado: usa a config padrão da conta — = gerenciador)
--   - 1d_view, 7d_view, 28d_view
--   - 1d_click, 7d_click, 28d_click
-- Exemplo: "7d_click,1d_view"
--
-- Quando o valor é 'default', o coletor usa unified attribution setting,
-- exatamente como o Gerenciador de Anúncios faz por padrão.

INSERT INTO `settings` (`key_name`, `value`, `is_encrypted`) VALUES
  ('attribution_windows', 'default', 0)
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;
