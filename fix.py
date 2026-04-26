"""
Limpa mojibake usando ftfy (fixes text for you).
Roda: python fix.py
"""
import os
import ftfy

files = [
    'accounts.php', 'alerts.php', 'clients.php', 'creatives.php',
    'index.php', 'install.php', 'login.php', 'logout.php',
    'performance.php', 'ping.php', 'report_settings.php',
    'run_check.php', 'settings.php', 'setup.php', 'oauth_fb.php',
    os.path.join('app', 'views', 'layout.php'),
]

for f in files:
    if not os.path.exists(f):
        print('SKIP', f)
        continue
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    fixed = ftfy.fix_text(content)
    if fixed != content:
        with open(f, 'w', encoding='utf-8', newline='\n') as file:
            file.write(fixed)
        print('Fixed', f)
    else:
        print('OK', f)

print('Done.')
