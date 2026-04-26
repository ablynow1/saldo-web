# SALDO WEB — TODO & Roadmap

## Páginas do sistema (equivalente ao routes)

```
/login.php              → Autenticação
/logout.php             → Encerrar sessão
/index.php              → Dashboard (visão geral + alertas recentes)
/accounts.php           → Gerenciar contas Meta Ads
/clients.php            → Gerenciar clientes + grupos WhatsApp
/performance.php        → Campanhas & métricas (ROAS, CPA, CTR)
/creatives.php          → Galeria de criativos (winners/losers)
/alerts.php             → Histórico de alertas
/report_settings.php    → Configurar relatórios agendados
/settings.php           → Configurações gerais (API, WhatsApp, etc)
/approve.php            → Aprovação de relatórios antes do envio
/approve_report.php     → Callback de aprovação via link WhatsApp
/oauth_fb.php           → Callback OAuth Facebook
/install.php            → Setup inicial / migrations
/setup.php              → Health check e runner de migrations
/test_whatsapp.php      → Testar conexão WhatsApp
/ping.php               → Health check externo (cron validation)
/run_check.php          → Entry point cron: verificar saldos
/run_collect.php        → Entry point cron: coletar insights
```

---

## [ ] Design & UX

- [ ] Adicionar dark/light mode toggle
- [ ] Skeleton loading nas tabelas (enquanto carrega dados)
- [ ] Animação de entrada nas metric cards (stagger)
- [ ] Página 404 customizada com o novo design
- [ ] Toast notifications (substituir flash messages por toast flutuante)
- [ ] Gráfico de runway por conta no dashboard (sparkline)
- [ ] Mobile: bottom navigation bar (melhorar UX mobile)

## [ ] Dashboard (index.php)

- [ ] Adicionar filtro por cliente no topo
- [ ] Card de "contas em risco" com highlight visual
- [ ] Ordenação da tabela por colunas (client-side)
- [ ] Export CSV das contas monitoradas

## [ ] Performance (performance.php)

- [ ] Gráfico de evolução de gasto diário
- [ ] Filtro por campanha / ad set
- [ ] Comparativo de período (semana atual vs anterior)
- [ ] Indicadores de tendência (seta up/down com delta %)

## [ ] Criativos (creatives.php)

- [ ] Preview de imagem ao hover (tooltip com thumbnail)
- [ ] Filtro por ROAS mínimo configurável
- [ ] Exportar lista de winners/losers

## [ ] Relatórios (report_settings.php)

- [ ] Preview do relatório antes de enviar
- [ ] Histórico de relatórios enviados por cliente
- [ ] Agendamento recorrente (diário, semanal, mensal)

## [ ] Alertas (alerts.php)

- [ ] Filtro por tipo de alerta
- [ ] Filtro por cliente/conta
- [ ] Marcar alertas como resolvidos
- [ ] Badge de contagem no nav (alertas não vistos)

## [ ] Configurações (settings.php)

- [ ] Testar conexão Meta API com feedback visual
- [ ] Múltiplos tokens Meta (multi-usuário)
- [ ] Log de erros de API

## [ ] Backend / Infra

- [ ] Rate limiting nas rotas de cron
- [ ] Log de auditoria (quem alterou o quê)
- [ ] Backup automático das configurações
- [ ] Suporte a múltiplos admins (roles: admin, viewer)
- [ ] Webhook para alertas (além do WhatsApp)
