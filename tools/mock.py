#!/usr/bin/env python3
"""Serveur de démo local pour Hostkeep : sert app/public avec de fausses données (aucun PHP, aucune base).
Usage : python3 tools/mock.py app/public 8766 [role=superadmin|admin|editor|viewer] [nocsp]
Le rôle choisi fixe les permissions du compte de démo (lues dans app/src/rbac.php) : l'interface s'affiche
comme pour ce rôle. Les actions sont simulées, rien n'est exécuté."""
import json, math, random, re, sys, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse, parse_qs

ROOT = Path(sys.argv[1]).resolve()
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 8766
NAV = {'home': 'Accueil', 'sites': 'Sites', 'system': 'Système', 'security': 'Sécurité', 'data': 'Données'}
GB = 1024 ** 3
MB = 1024 ** 2
JOBS = {}


GROUPS = {'Surveillance': ['home', 'sites', 'system'], 'Protection': ['security', 'data']}
ROLE = next((a.split('=', 1)[1] for a in sys.argv[3:] if a.startswith('role=')), 'superadmin')
RANK = {'superadmin': 4, 'admin': 3, 'editor': 2, 'viewer': 1}
LABELS = {'superadmin': 'Super Admin', 'admin': 'Administrateur', 'editor': 'Éditeur / Modérateur', 'viewer': 'Lecteur'}
if ROLE not in RANK:
    sys.exit(f'rôle inconnu : {ROLE}')
# permissions of the role, from the real table of the panel
PERMS = [p for p, r in re.findall(r"^\s*'([a-z0-9._]+)' => \['(superadmin|admin|editor|viewer)',", (ROOT.parent / 'src' / 'rbac.php').read_text(), re.M)
         if RANK[ROLE] >= RANK[r]]
ME = {'id': 1, 'username': 'demo', 'display_name': 'Démo', 'email': 'demo@example.com', 'role': ROLE, 'role_label': LABELS[ROLE],
      'perms': PERMS, 'must_change_password': False, 'panel_name': 'Hostkeep', 'password_min': 12, 'mail': True, 'version': 'demo',
      'last_login_at': None, 'two_fa': {'enabled': True, 'recovery_left': 10, 'required': ROLE in ('admin', 'superadmin')}}
ADMIN = 'users.view' in PERMS or 'settings.view' in PERMS


def php(path):
    s = (ROOT / path).read_text()
    s = re.sub(r'^<\?php.*?\?>', '', s, count=1, flags=re.S)
    def loop(m):
        return ''.join(m.group(1).replace('<?= $id ?>', k).replace('<?= h($label) ?>', v) for k, v in NAV.items())
    # sidebar: groups of sections (two nested loops)
    def groups(m):
        head, item = m.group(1), m.group(2)
        return ''.join(head.replace('<?= h($g) ?>', g) + ''.join(item.replace('<?= $id ?>', k).replace('<?= h($label) ?>', NAV[k]) for k in ids)
                       for g, ids in GROUPS.items())
    s = re.sub(r'<\?php foreach \(\$groups.*?\?>(.*?)<\?php foreach \(\$items.*?\?>(.*?)<\?php endforeach; \?>\s*<\?php endforeach; \?>', groups, s, flags=re.S)
    s = re.sub(r'<\?php foreach \(\$nav.*?\?>(.*?)<\?php endforeach; \?>', loop, s, flags=re.S)
    s = re.sub(r'<\?php if \(\$admin\): \?>(.*?)<\?php endif; \?>', lambda m: m.group(1) if ADMIN else '', s, flags=re.S)
    s = re.sub(r'<\?php if .*?\?>.*?<\?php endif; \?>', '', s, flags=re.S)
    def expr(m):
        e = m.group(1)
        if 'me_payload' in e: return json.dumps(ME, ensure_ascii=False).replace('<', '\\u003c')
        if 'pwa_head' in e: return '<link rel="manifest" href="/assets/manifest.json"><link rel="icon" href="/assets/favicon.svg">'
        if (a := re.match(r"h\(asset\('([^']+)'\)\)", e)): return a.group(1)
        if 'gethostname' in e: return 'vps-demo'
        if 'role_label' in e: return LABELS[ROLE]
        if "$me['email']" in e: return ' · ' + ME['email']
        if 'display_name' in e: return ME['display_name']
        return {'h($token)': 'demo', 'h($name)': 'Hostkeep', 'h($initials)': 'D'}.get(e, '')
    return re.sub(r'<\?=\s*(.*?)\s*\?>', expr, s, flags=re.S)


def now():
    return int(time.time())


def sites():
    names = [('blog.example.com', 200, 84, 61), ('shop.example.com', 200, 212, 12), ('api.example.com', 200, 47, 74),
             ('docs.example.com', 301, 31, 80), ('admin.example.com', 200, 58, 88), ('legacy.example.com', 502, 0, None)]
    out, up, lat = [], {}, {}
    for n, code, ms, cert in names:
        short = n.split('.')[0]
        out.append({'name': n, 'url': f'https://{n}', 'docroot': f'/var/www/{short}/public', 'aliases': [f'www.{n}'] if short == 'blog' else [],
                    'code': code, 'ms': ms, 'cert_days': cert, 'confs': [f'{short}.conf', f'{short}-le-ssl.conf'] if cert else [f'{short}.conf']})
        up[n] = 93.4 if code == 502 else 99.98 if short == 'shop' else 100
        lat[n] = [None] * 24 if code == 502 else [max(10, ms + int(ms * .4 * math.sin(i / 3 + len(n)))) for i in range(24)]
    return {'sites': out, 'uptime': up, 'latency': lat, 'disabled': ['old-landing.conf'], 'self': 'admin'}


def section(name):
    t = now()
    d = {
        'processes': {k: [{'pid': 800 + i * 37, 'user': u, 'cpu': c, 'rss': r, 'etime': 86400 * 2 + i * 911, 'cmd': cmd}
                          for i, (u, c, r, cmd) in enumerate(rows)] for k, rows in {
            'by_mem': [('mysql', 2.1, 412000, '/usr/sbin/mariadbd'), ('www-data', 4.0, 96000, 'php-fpm: pool shop'), ('node', 1.2, 88000, 'node /srv/api/server.js'),
                       ('www-data', 0.4, 61000, 'php-fpm: pool blog'), ('root', 0.1, 42000, '/usr/sbin/apache2 -k start')],
            'by_cpu': [('www-data', 4.0, 96000, 'php-fpm: pool shop'), ('mysql', 2.1, 412000, '/usr/sbin/mariadbd'), ('node', 1.2, 88000, 'node /srv/api/server.js'),
                       ('root', 0.3, 9000, '/usr/bin/python3 /usr/bin/fail2ban-server'), ('root', 0.1, 42000, '/usr/sbin/apache2 -k start')]}.items()},
        'sites': sites(),
        'certs': {'certs': [{'name': n, 'domains': [f'{n}.example.com'], 'expires': t + d * 86400, 'days': d}
                            for n, d in [('shop', 12), ('blog', 61), ('api', 74), ('docs', 80), ('admin', 88)]]},
        'services': {'failed': [], 'units': [
            {'Id': f'{n}.service', 'Description': desc, 'ActiveState': 'active', 'SubState': 'running', 'MemoryCurrent': str(mem * MB),
             'ActiveEnterTimestamp': time.strftime('%a %Y-%m-%d %H:%M:%S UTC', time.gmtime(t - age)), 'action': n if act else None, 'reload': rl}
            for n, desc, mem, age, act, rl in [
                ('apache2', 'The Apache HTTP Server', 86, 190000, True, True), ('mariadb', 'MariaDB 10.11 database server', 402, 190000, True, False),
                ('php8.4-fpm', 'The PHP 8.4 FastCGI Process Manager', 174, 86000, True, True), ('fail2ban', 'Fail2Ban Service', 31, 190000, True, True),
                ('ssh', 'OpenBSD Secure Shell server', 6, 190000, False, False), ('cron', 'Regular background program processing daemon', 2, 190000, False, False),
                ('systemd-journald', 'Journal Service', 24, 190000, False, False), ('unattended-upgrades', 'Unattended Upgrades Shutdown', 11, 190000, False, False),
                ('pm2-deploy', 'PM2 process manager', 64, 190000, True, False), ('certbot', 'Certbot', 0, 4000, False, False)]]},
        'pm2': {'apps': [{'name': 'api', 'status': 'online', 'cpu': 1.2, 'memory': 88 * MB, 'restarts': 0, 'uptime': (t - 190000) * 1000},
                         {'name': 'legacy-worker', 'status': 'stopped', 'cpu': 0, 'memory': 0, 'restarts': 14, 'uptime': 0}]},
        'cron': {'timers': [{'next': 'dans 42 s', 'unit': 'hostkeep-minute.timer', 'activates': 'hostkeep-collect@minute.service'},
                            {'next': 'dans 3 min', 'unit': 'hostkeep-5min.timer', 'activates': 'hostkeep-collect@5min.service'},
                            {'next': 'demain 03:10', 'unit': 'hostkeep-backup.timer', 'activates': 'hostkeep-collect@backup.service'},
                            {'next': 'demain 05:41', 'unit': 'certbot.timer', 'activates': 'certbot.service'}],
                 'crontabs': {'deploy': '# m h dom mon dow command\n*/15 * * * * /srv/api/bin/sync.sh'}, 'crond': {'php': '09,39 * * * * root /usr/lib/php/sessionclean'}},
        'perf': {'memory': [{'name': 'MariaDB', 'rss': 412 * MB}, {'name': 'PHP-FPM', 'rss': 174 * MB}, {'name': 'Node (PM2)', 'rss': 88 * MB}, {'name': 'Apache', 'rss': 86 * MB}],
                 'mariadb': {'Slow_queries': 37, 'long_query_time': '1.000000', 'slow_query_log': 'ON', 'max_connections': 151, 'innodb_buffer_pool_size': 256 * MB},
                 'pools': [{'name': 'shop', 'user': 'shop', 'pm': 'dynamic', 'procs': 4, 'max_children': 8, 'rss': 96 * MB, 'max_reached': 3, 'sites': ['shop.example.com']},
                           {'name': 'blog', 'user': 'blog', 'pm': 'ondemand', 'procs': 1, 'max_children': 5, 'rss': 61 * MB, 'max_reached': 0, 'sites': ['blog.example.com']},
                           {'name': 'hostkeep', 'user': 'hostkeep', 'pm': 'ondemand', 'procs': 1, 'max_children': 4, 'rss': 17 * MB, 'max_reached': 0, 'sites': ['admin.example.com']}],
                 'slow': [{'db': 'shop', 'count': 21, 'total': 48.2, 'max': 4.81, 'rows': 1840221, 'query': 'SELECT * FROM orders o JOIN order_items i ON i.order_id = o.id WHERE o.created_at > ?',
                           'example': "SELECT * FROM orders o JOIN order_items i ON i.order_id = o.id\nWHERE o.created_at > '2026-10-01' ORDER BY o.created_at DESC;"}]},
        'storage': {'df': [{'target': '/', 'source': '/dev/vda1', 'fstype': 'ext4', 'size': 80 * GB, 'avail': 28 * GB, 'pct': 65},
                           {'target': '/boot/efi', 'source': '/dev/vda15', 'fstype': 'vfat', 'size': 124 * MB, 'avail': 112 * MB, 'pct': 10}],
                    'dirs': [{'path': p, 'size': int(s * GB)} for p, s in [('/var/lib/mysql', 21.4), ('/var/www', 14.2), ('/var/backups/hostkeep', 6.1), ('/var/log', 2.3), ('/home', 1.9), ('/usr', 4.8)]],
                    'journal': 96 * MB, 'apt_cache': 412 * MB},
        'security': {'reboot_required': True, 'kernel_running': '6.8.0-45-generic', 'kernel_latest': '6.8.0-48',
                     'jails': [{'name': 'sshd', 'banned_now': 3, 'banned_total': 412, 'failed_now': 7, 'banned': ['203.0.113.44', '198.51.100.7', '192.0.2.201']},
                               {'name': 'hostkeep', 'banned_now': 0, 'banned_total': 2, 'failed_now': 0, 'banned': []}],
                     'listening': [{'addr': '0.0.0.0:22', 'proc': 'sshd'}, {'addr': '0.0.0.0:80', 'proc': 'apache2'}, {'addr': '0.0.0.0:443', 'proc': 'apache2'},
                                   {'addr': '127.0.0.1:3306', 'proc': 'mariadbd'}, {'addr': '127.0.0.1:3000', 'proc': 'node'}],
                     'logins': ['deploy  pts/0  198.51.100.23  Wed Nov 11 21:14 - 21:52 (00:38)', 'deploy  pts/0  198.51.100.23  Tue Nov 10 09:02 - 09:31 (00:29)']},
        'secscan': {'ran': t - 31000, 'findings': [
            {'severity': 'crit', 'site': 'legacy.example.com', 'title': 'Fichier .env accessible', 'detail': 'GET /.env → 200'},
            {'severity': 'warn', 'site': 'blog.example.com', 'title': 'En-tête HSTS absent', 'detail': 'Strict-Transport-Security'},
            {'severity': 'warn', 'site': 'docs.example.com', 'title': 'Dossier listable', 'detail': '/assets/'},
            {'severity': 'info', 'site': 'serveur', 'title': 'SSH : authentification par mot de passe active', 'detail': 'PasswordAuthentication yes'}]},
        'updates': {'count': 11, 'security_count': 2, 'packages': [
            {'name': n, 'version': v, 'from': f, 'security': s, 'suite': 'noble-updates'} for n, v, f, s in [
                ('openssl', '3.0.13-0ubuntu3.6', '3.0.13-0ubuntu3.5', True), ('libssl3t64', '3.0.13-0ubuntu3.6', '3.0.13-0ubuntu3.5', True),
                ('apache2', '2.4.58-1ubuntu8.8', '2.4.58-1ubuntu8.7', False), ('apache2-bin', '2.4.58-1ubuntu8.8', '2.4.58-1ubuntu8.7', False),
                ('apache2-data', '2.4.58-1ubuntu8.8', '2.4.58-1ubuntu8.7', False), ('apache2-utils', '2.4.58-1ubuntu8.8', '2.4.58-1ubuntu8.7', False),
                ('curl', '8.5.0-2ubuntu10.7', '8.5.0-2ubuntu10.6', False), ('libcurl4t64', '8.5.0-2ubuntu10.7', '8.5.0-2ubuntu10.6', False),
                ('git', '1:2.43.0-1ubuntu7.4', '1:2.43.0-1ubuntu7.3', False), ('git-man', '1:2.43.0-1ubuntu7.4', '1:2.43.0-1ubuntu7.3', False),
                ('tzdata', '2026b-0ubuntu0.24.04', '2026a-0ubuntu0.24.04', False)]]},
        'panel': {'git': True, 'signed': False, 'hash': '2932936', 'ts': t - 86400, 'subject': 'chore: fusionne develop dans main', 'remote': 'origin/main', 'branch': 'main',
                  'source': '/opt/hostkeep', 'dirty': False, 'behind': [], 'previous': {'hash': '31e7d1a', 'ts': t - 4 * 86400, 'subject': 'chore: fusionne develop dans main'}},
        'databases': {'version': '10.11.8-MariaDB', 'status': {'Uptime': 190000, 'Threads_connected': 4, 'Max_used_connections': 23, 'Questions': 4812233, 'Slow_queries': 37},
                      'dbs': [{'name': 'shop', 'tables': 64, 'rows': 2140331, 'size': int(18.2 * GB)}, {'name': 'blog', 'tables': 12, 'rows': 48211, 'size': 412 * MB},
                              {'name': 'hostkeep', 'tables': 9, 'rows': 902114, 'size': 96 * MB}, {'name': 'mysql', 'tables': 31, 'rows': 1204, 'size': 3 * MB}]},
        'backups': {'total': int(6.1 * GB), 'keep': 7, 'dir': '/var/backups/hostkeep', 'pre': [],
                    'days': [{'day': time.strftime('%Y-%m-%d', time.localtime(t - i * 86400)), 'size': int(.87 * GB),
                              'files': [{'db': db, 'size': sz * MB, 'mtime': t - i * 86400 - 20000} for db, sz in [('shop', 820), ('blog', 48), ('hostkeep', 22), ('mysql', 1)]],
                              'archives': [{'name': n, 'path': pth, 'size': sz * MB, 'mtime': t - i * 86400 - 19000} for n, pth, sz in [('www', '/var/www', 1900), ('nginx', '/etc/nginx', 1), ('hostkeep', '/etc/hostkeep', 1)]]} for i in range(7)]},
        'logkeys': {'keys': [{'key': 'apache-error', 'label': 'Apache — erreurs', 'size': 3 * MB}, {'key': 'apache-access', 'label': 'Apache — accès', 'size': 48 * MB},
                             {'key': 'auth', 'label': 'Connexions SSH', 'size': 0}, {'key': 'hostkeep', 'label': 'Hostkeep', 'size': 120000}]},
    }
    return {'updated': t - 140, 'data': d.get(name)}


def live():
    t = time.time()
    return {'cpu': 14 + 9 * math.sin(t / 7) + random.random() * 4, 'load1': .31 + .1 * math.sin(t / 30), 'cores': 2, 'mem_total': 4 * GB,
            'mem_avail': int((2.2 + .1 * math.sin(t / 20)) * GB), 'net_rx': 72000 + random.randint(0, 40000), 'net_tx': 134000 + random.randint(0, 60000), 'uptime': 187200}


def overview():
    return {'host': 'vps-demo', 'os': 'Ubuntu 24.04.3 LTS', 'kernel': '6.8.0-45-generic', 'sites_up': 5, 'sites_total': 6,
            'metric': {'disk_used': 52 * GB, 'disk_total': 80 * GB},
            'alerts': [] if 'ok' in MODE else [
                {'level': 'info', 'text': 'Redémarrage requis (nouveau noyau 6.8.0-48).', 'section': 'security'},
                {'level': 'crit', 'text': 'Site legacy.example.com injoignable (HTTP 502).', 'section': 'sites'},
                {'level': 'warn', 'text': 'Certificat shop expire dans 12 j.', 'section': 'certs'},
                {'level': 'crit', 'text': 'Appli PM2 legacy-worker : stopped.', 'section': 'cron'},
                {'level': 'warn', 'text': '2 mise(s) à jour de sécurité en attente.', 'section': 'updates'}]}


def history(rng):
    span, bucket = {'1h': (3600, 60), '24h': (86400, 300), '7d': (604800, 1800), '30d': (2592000, 7200)}.get(rng, (86400, 300))
    t0 = now() - span
    random.seed(7)
    pts = [{'t': t, 'cpu': round(18 + 14 * math.sin(t / 9000) + random.random() * 9, 1), 'load1': round(.4 + .3 * math.sin(t / 7000) + random.random() * .2, 2),
            'mem_used': int((1.8 + .3 * math.sin(t / 20000)) * GB), 'mem_total': 4 * GB, 'swap_used': int(.2 * GB),
            'disk_used': int((51 + (t - t0) / span) * GB), 'disk_total': 80 * GB,
            'rx': int(90000 + 60000 * abs(math.sin(t / 5000)) + random.random() * 30000), 'tx': int(150000 + 90000 * abs(math.sin(t / 6000)) + random.random() * 40000)}
           for t in range(t0, now(), bucket)]
    return {'range': rng, 'bucket': bucket, 'since': t0, 'points': pts}


def traffic(days, host):
    random.seed(days)
    hosts = [{'host': s['name'], 'hits': 4000 * days // (i + 1), 'visitors': 300 * days // (i + 1), 'bots': 900 * days // (i + 1), 's4': 40 * days // (i + 1),
              's5': 12 if 'legacy' in s['name'] else 0, 'bytes': 80 * MB * days // (i + 1)} for i, s in enumerate(sites()['sites'])]
    series = [{'day': time.strftime('%Y-%m-%d', time.localtime(now() - (days - 1 - i) * 86400)), 'hits': 9000 + random.randint(0, 3000), 'bots': 2000 + random.randint(0, 800),
               's4': 90 + random.randint(0, 40), 's5': random.randint(0, 4), 'visitors': 700 + random.randint(0, 250)} for i in range(days)]
    top = {'page': [{'item': p, 'hits': h} for p, h in [('/', 4211), ('/produits', 2180), ('/blog/installer-hostkeep', 1320), ('/contact', 402)]],
           'ref': [{'item': 'google.com', 'hits': 812}, {'item': 'github.com', 'hits': 240}], '404': [{'item': '/wp-login.php', 'hits': 311}, {'item': '/.env', 'hits': 148}],
           '5xx': [{'item': 'legacy.example.com/', 'hits': 12}], 'ip': [{'item': '203.0.113.44', 'hits': 902}], 'bot': [{'item': 'Googlebot', 'hits': 2400}, {'item': 'bingbot', 'hits': 610}]}
    return {'days': days, 'host': host, 'hosts': hosts, 'series': series, 'top': top, 'since_first': '2026-10-01'}


def sitehistory(host, days):
    days = 30 if days == '30' else 7
    bucket = 21600 if days == 30 else 3600
    random.seed(len(host))
    pts = [{'t': t, 'ms': 60 + random.randint(0, 50), 'max_ms': 120 + random.randint(0, 200), 'errors': 0, 'n': bucket // 300} for t in range(now() - days * 86400, now(), bucket)]
    return {'host': host, 'days': days, 'points': pts, 'checks': len(pts) * (bucket // 300), 'errors': 3, 'avg': 84, 'max': 318}


def fs(path):
    path = path or '/var/www'
    t = now()
    ents = [{'name': n, 'type': ty, 'size': sz, 'mtime': t - i * 90000, 'mode': m, 'owner': 'www-data', 'group': 'www-data'} for i, (n, ty, sz, m) in enumerate(
        [('blog', 'dir', 0, 'drwxr-x---'), ('shop', 'dir', 0, 'drwxr-x---'), ('html', 'dir', 0, 'drwxr-xr-x'), ('current', 'link', 0, 'lrwxrwxrwx'),
         ('index.html', 'file', 10671, '-rw-r--r--'), ('robots.txt', 'file', 68, '-rw-r--r--')])]
    return {'path': path, 'entries': ents, 'total': len(ents)}


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def send(self, body, ctype='application/json; charset=utf-8', code=200):
        if not isinstance(body, bytes):
            body = (body if isinstance(body, str) else json.dumps(body)).encode()
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Cache-Control', 'no-store')
        if not NOCSP: self.send_header('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'")
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        u = urlparse(self.path)
        n = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(n)
        if u.path == '/api.php':
            jid = f'job{len(JOBS) + 1}'
            JOBS[jid] = (time.time(), json.loads(raw or b'{}'))
            return self.send({'job': jid})
        self.send_response(303)
        self.send_header('Location', '/login.php' if u.path == '/logout.php' else '/')
        self.end_headers()

    def do_GET(self):
        global MODE
        u = urlparse(self.path)
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        p = u.path
        if p == '/frame':   # wrapper used to take screenshots at an exact viewport size
            return self.send(f'<!doctype html><body style="margin:0;background:#888"><iframe id="f" src="{q["u"]}" width="{q["w"]}" height="{q["h"]}" style="border:0;display:block"></iframe>', 'text/html; charset=utf-8')
        if p in ('/', '/index.php'):
            MODE = q.get('demo', '')
            return self.send(php('index.php'), 'text/html; charset=utf-8')
        if p == '/login.php':
            return self.send(php('login.php'), 'text/html; charset=utf-8')
        if p == '/api.php':
            r = q.get('r', '')
            if r == 'me': return self.send(ME)
            if r == 'live': return self.send(live())
            if r == 'overview': return self.send(overview())
            if r == 'history': return self.send(history(q.get('range', '24h')))
            if r == 'section': return self.send(section(q.get('name', '')))
            if r == 'traffic': return self.send(traffic(int(q.get('days', 7)), q.get('host', '')))
            if r == 'sitehistory': return self.send(sitehistory(q.get('host', ''), q.get('days', '7')))
            if r == 'fsroots': return self.send({'roots': ['/var/www', '/etc/apache2', '/var/log']})
            if r == 'fs': return self.send(fs(q.get('path', '')))
            if r == 'fsread': return self.send({'content': 'User-agent: *\nDisallow: /admin/\n', 'binary': False, 'truncated': False})
            if r == 'log': return self.send({'output': '\n'.join(f'[Thu Nov 12 10:{i:02d}:14 2026] [proxy_fcgi:error] [client 203.0.113.{i}] AH01071: Got error "PHP message: demo line {i}"' for i in range(40))})
            if r == 'audit':
                return self.send([{'ts': now() - i * 7200, 'ip': '198.51.100.23', 'user': 'yoan', 'action': a, 'job': f'j{i}', 'rc': rc} for i, (a, rc) in enumerate(
                    [('service restart php8.4-fpm', 0), ('backup-now', 0), ('certbot dry-run', 0), ('apt-update', 0), ('site disable old-landing.conf', 0), ('f2b-unban sshd 203.0.113.9', 1)])])
            if r == 'job':
                t0, body = JOBS.get(q.get('id'), (0, {}))
                el = time.time() - t0
                lines = [f"$ hostkeep-action {body.get('verb', '')} {' '.join(body.get('args', []))}", 'Vérification des arguments… ok', 'Exécution…', 'Terminé.']
                return self.send({'output': '\n'.join(lines[:1 + int(el // 1.2)]), 'done': el > 4, 'rc': 0})
            return self.send({'error': 'route inconnue'}, code=404)
        f = (ROOT / p.lstrip('/')).resolve()
        if ROOT in f.parents and f.is_file():
            ct = {'.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.json': 'application/json', '.html': 'text/html; charset=utf-8'}.get(f.suffix, 'application/octet-stream')
            return self.send(f.read_bytes(), ct)
        self.send('not found', 'text/plain', 404)


MODE = ''
NOCSP = 'nocsp' in sys.argv
ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
