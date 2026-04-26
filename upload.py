"""Upload modified files to FTP /saldoweb/.

Credenciais lidas de variáveis de ambiente ou do arquivo _ftp_secrets.py (local, nunca commitar).
Para configurar, crie _ftp_secrets.py com:
    HOST = 'lventerprise.com.br'
    USER = 'seu_usuario@dominio.com'
    PW   = 'sua_senha'
    BASE = '/saldoweb'   # opcional

Ou exporte as variáveis antes de rodar:
    export FTP_HOST=lventerprise.com.br
    export FTP_USER=usuario@dominio.com
    export FTP_PASS=senha
"""
from ftplib import FTP_TLS
import os, sys

# Tenta importar credenciais do arquivo local (tem prioridade)
try:
    import _ftp_secrets as _s
    HOST = getattr(_s, 'HOST', os.environ.get('FTP_HOST', ''))
    USER = getattr(_s, 'USER', os.environ.get('FTP_USER', ''))
    PW   = getattr(_s, 'PW',   os.environ.get('FTP_PASS', ''))
    BASE = getattr(_s, 'BASE', os.environ.get('FTP_BASE', '/saldoweb'))
except ImportError:
    HOST = os.environ.get('FTP_HOST', '')
    USER = os.environ.get('FTP_USER', '')
    PW   = os.environ.get('FTP_PASS', '')
    BASE = os.environ.get('FTP_BASE', '/saldoweb')

if not HOST or not USER or not PW:
    print('ERRO: credenciais FTP não encontradas.')
    print('Crie _ftp_secrets.py ou defina FTP_HOST / FTP_USER / FTP_PASS.')
    sys.exit(1)

# Todos os arquivos do projeto (local relativo -> remoto em BASE)
files = [
    # Core
    'app/bootstrap.php',
    'app/lib/Db.php',
    'app/lib/Crypto.php',
    'app/lib/HttpClient.php',
    # Meta
    'app/lib/MetaAdsClient.php',
    'app/lib/InsightsClient.php',
    'app/lib/BalanceForecaster.php',
    # Alertas & Relatórios
    'app/lib/AlertEngine.php',
    'app/lib/ReportBuilder.php',
    'app/lib/WhatsAppClient.php',
    # Views
    'app/views/layout.php',
    # CSS
    'assets/css/app.css',
    # Páginas principais
    'accounts.php',
    'alerts.php',
    'clients.php',
    'creatives.php',
    'index.php',
    'login.php',
    'logout.php',
    'oauth_fb.php',
    'performance.php',
    'report_settings.php',
    'settings.php',
    'setup.php',
    # Runner web
    'run_collect.php',
    'approve_report.php',
    # Crons
    'cron/check_balances.php',
    'cron/collect_insights.php',
    'cron/send_daily_report.php',
    # SQL
    'sql/schema.sql',
    'sql/migrations/001_performance.sql',
    'sql/migrations/002_forecast.sql',
    'sql/migrations/003_advanced_reports.sql',
    'sql/migrations/004_pending_reports.sql',
    'sql/migrations/005_queue_status.sql',
]

ftp = FTP_TLS(HOST, timeout=30)
ftp.login(USER, PW)
ftp.prot_p()
ftp.cwd(BASE)

def ensure_dir(remote_path):
    parts = remote_path.split('/')
    cur = ''
    for p in parts:
        if not p: continue
        cur = cur + '/' + p
        try:
            ftp.cwd(BASE + cur)
        except Exception:
            try:
                ftp.mkd(BASE + cur)
                print('  mkd', BASE + cur)
            except Exception as e:
                print('  mkd fail', BASE + cur, e)
    ftp.cwd(BASE)

ok, fail = 0, 0
for rel in files:
    rel_norm = rel.replace('\\', '/')
    local = os.path.join(*rel_norm.split('/'))
    if not os.path.exists(local):
        print('SKIP (missing local):', rel_norm); fail += 1; continue
    remote_dir = os.path.dirname(rel_norm)
    if remote_dir:
        ensure_dir(remote_dir)
    try:
        with open(local, 'rb') as f:
            ftp.storbinary('STOR ' + rel_norm, f)
        size = os.path.getsize(local)
        print(f'OK  {rel_norm}  ({size} bytes)')
        ok += 1
    except Exception as e:
        print(f'FAIL {rel_norm}: {e}')
        fail += 1

ftp.quit()
print(f'\nDone. {ok} uploaded, {fail} failed.')
