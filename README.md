# 💰 SALDO WEB

Monitor de saldo das contas de anúncios do **Meta Ads** que envia alertas automáticos no **grupo do WhatsApp** do cliente quando o saldo está acabando.

---

## 🚀 Instalar (4 passos)

### Passo 1 — Criar banco de dados no cPanel

1. Acesse **cPanel → MySQL Databases**
2. Crie um banco (ex: `saldoweb`)
3. Crie um usuário com senha forte
4. Adicione o usuário ao banco com **todas as permissões**
5. Anote: **nome do banco**, **usuário** e **senha** — você vai precisar logo.

> 💡 No cPanel, o nome final fica `seulogin_saldoweb` (ele coloca seu usuário de hospedagem na frente automaticamente). Use esse nome completo.

---

### Passo 2 — Subir os arquivos via FTP

Suba **toda a pasta do projeto** para o servidor mantendo a estrutura. O **Document Root** do domínio deve apontar para a pasta `public_html/`.

Exemplo de estrutura no servidor:
```
/home/seulogin/
  public_html/     ← raiz do domínio (index.php, login.php etc. ficam aqui)
  app/             ← fora do acesso público
  cron/            ← fora do acesso público
  sql/             ← fora do acesso público
```

Se o cPanel já tem um `public_html` como raiz, suba o conteúdo da pasta `public_html/` do projeto diretamente para lá, e as pastas `app/`, `cron/`, `sql/` um nível acima.

---

### Passo 3 — Rodar o instalador

Acesse no navegador:
```
https://seudominio.com.br/install.php
```

Preencha:
- **Host do banco** → `localhost` (quase sempre assim)
- **Nome do banco** → ex: `seulogin_saldoweb`
- **Usuário do banco** → ex: `seulogin_saldoweb`
- **Senha do banco** → a senha que você criou
- **URL do site** → `https://seudominio.com.br`
- **Usuário admin** → o login que você vai usar no painel
- **Senha admin** → mínimo 8 caracteres

Clique em **Instalar agora**. Pronto — banco criado, config salvo, usuário criado.

> ⚠️ Após instalar: **delete o arquivo `install.php`** pelo gerenciador de arquivos do cPanel (por segurança).

---

### Passo 4 — Configurar o cron (verificação automática)

No cPanel, vá em **Cron Jobs** e adicione:

```
*/15 * * * *   /usr/bin/php /home/SEULOGIN/cron/check_balances.php
```

Substitua `SEULOGIN` pelo seu usuário de hospedagem. Isso faz o sistema checar os saldos a cada 15 minutos automaticamente.

> Não sabe o caminho do PHP? No cPanel → Terminal, rode `which php`. Se não tiver terminal, tente `/usr/local/bin/php` ou `/opt/alt/php81/usr/bin/php`.

---

## ⚙️ Configuração inicial no painel

Acesse `https://seudominio.com.br/login.php` e entre com o usuário que você criou.

### 1. Configurar Meta Ads

Vá em **Configurações** e cole o **System User Token** do Meta.

**Como gerar o token (uma vez só):**

1. Acesse [business.facebook.com](https://business.facebook.com)
2. Menu → **Configurações do Negócio**
3. No menu esquerdo: **Usuários → Usuários do Sistema → Adicionar**
4. Nome: `saldo-monitor` · Função: **Admin** → Criar
5. Clique em **Adicionar Ativos** → Contas de Anúncios → selecione as contas dos clientes → marque **"Gerenciar campanhas"** → Salvar
6. Volte no System User criado → clique em **Gerar Novo Token**
7. Escolha o seu App Meta (se não tiver App ainda, veja abaixo)
8. Marque: ✅ `ads_read` · ✅ `business_management` · ✅ `read_insights`
9. Clique em Gerar → **copie o token inteiro**
10. Cole no painel em Configurações → *System User Token* → Salvar

<details>
<summary>📌 Não tenho App Meta ainda — o que fazer?</summary>

1. Acesse [developers.facebook.com](https://developers.facebook.com) → Meus Apps → Criar App
2. Escolha tipo **"Negócio"**
3. Preencha nome (ex: `Saldo Monitor`) e email
4. Pronto — volte ao passo 6 acima e escolha esse App

Não precisa publicar o App nem fazer revisão da Meta para uso interno com System User Token.
</details>

**Para contas de clientes externos:**
O cliente acessa o BM dele → Configurações → Contas de Anúncios → **Atribuir Parceiro** → cola o ID do BM da sua agência.

---

### 2. Configurar WhatsApp

> ⚠️ A API oficial do Meta **não** envia mensagens para grupos. Por isso usamos a **Evolution API** (open source, baseada em WhatsApp Web).

Você precisa de uma **VPS barata** para rodar a Evolution API (~R$ 25/mês na Contabo ou Hetzner) **ou** contratar um serviço gerenciado.

**Opção fácil — Evolution gerenciada (sem VPS):**
Alguns serviços já oferecem Evolution pronta. Busque por "Evolution API gerenciada" ou use [codechat.dev](https://codechat.dev). Vai ter uma URL, uma API Key e um nome de instância.

**Opção técnica — instalar na VPS:**
```bash
# Numa VPS Ubuntu 22 limpa:
curl -fsSL https://raw.githubusercontent.com/EvolutionAPI/evolution-api/main/Docker/install.sh | bash
```
Depois acesse `http://ip-da-vps:8080/manager`, crie uma instância e escaneie o QR.

**Depois de ter a instância:**
1. No painel → Configurações:
   - **Base URL** → ex: `https://evo.seudominio.com`
   - **API Key** → a chave da Evolution
   - **Instância** → nome que você criou
2. Clique em **Testar instância** — deve mostrar `state: open`

---

### 3. Cadastrar clientes e contas

**Clientes:**
- Menu → **Clientes** → Novo cliente
- Preencha nome e selecione o grupo de WhatsApp no dropdown (os grupos aparecem automaticamente se o WhatsApp estiver conectado)

**Contas Meta:**
- Menu → **Contas Meta** → clique em **Importar do BM**
- Escolha o cliente padrão → Importar
- As contas aparecem desativadas — ative as que quer monitorar e ajuste os thresholds

**Thresholds por conta:**
- **Pré-pago:** alerta quando o saldo restante for menor que X dias de gasto (padrão: 3 dias)
- **Pós-pago:** alerta quando atingir X% do limite de gasto (padrão: 80%)

---

## 🔔 Como funciona o alerta

A cada 15 min o cron verifica todas as contas ativas. Se uma conta atingir o threshold configurado, uma mensagem é enviada para o **grupo do WhatsApp** do cliente:

**Exemplo pré-pago:**
> ⚠️ *Saldo baixo - Cliente ABC*
> Conta: *Conta Ads Principal*
> Saldo atual: R$ 45,00
> Gasto diário médio (7d): R$ 38,00
> Autonomia estimada: 1.2 dias
> Recomendamos recarga antes que as campanhas parem.

**Exemplo conta bloqueada:**
> 🚨 *ALERTA CRÍTICO - Cliente ABC*
> Conta de anúncios *Conta Ads Principal* está com status: *SEM FUNDOS*.
> As campanhas estão paradas ou prestes a parar. Ação imediata necessária.

- Cooldown padrão: 6 horas entre alertas do mesmo tipo (configurável)
- Histórico completo em **Menu → Alertas**

---

## ❓ Problemas comuns

| Problema | Solução |
|---|---|
| "Meta API: Error validating access token" | Token expirou ou foi revogado. Gere novo em business.facebook.com |
| "Evolution: state=close" | WhatsApp desconectou. Abra o manager da Evolution e escaneie o QR novamente |
| Alertas com ❌ no histórico | Veja a resposta do provedor clicando em "ver" — geralmente é JID do grupo errado ou WhatsApp desconectado |
| Cron não roda | Teste manualmente no Terminal do cPanel: `php /home/SEULOGIN/cron/check_balances.php` |
| Conta aparece como "unknown" | Na 1ª verificação o sistema detecta automático; se continuar, edite a conta e selecione o tipo manualmente |
