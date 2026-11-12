#!/bin/bash
# Hostkeep installer. Run as root from the cloned repository:
#
#   sudo ./install.sh deps             system packages (PHP, MariaDB, fail2ban, certbot...)
#   sudo ./install.sh app              install / update the panel (keeps the installed .env)
#   sudo ./install.sh apache           publish it with Apache  + Let's Encrypt certificate
#   sudo ./install.sh nginx            publish it with Nginx   + Let's Encrypt certificate
#   sudo ./install.sh uninstall        remove the panel (keeps database and backups)
#   sudo ./install.sh uninstall --purge   also drop the database, backups, logs and system user
#
# Idempotent: running "app" again updates the code and keeps the configuration and password.
set -euo pipefail
SRC=$(cd "$(dirname "$0")" && pwd)
APP=/var/www/hostkeep
step() { echo; echo "== $*"; }
die() { echo "Erreur : $*" >&2; exit 1; }

[[ $(id -u) -eq 0 ]] || die "lance ce script avec sudo."
[[ -r /etc/debian_version ]] || die "seuls Debian et Ubuntu sont pris en charge."

# Settings: the installed .env wins, otherwise the one next to this script.
env_file() { if [[ -f $APP/.env ]]; then echo "$APP/.env"; else echo "$SRC/.env"; fi; }
env_get() {
  local v
  v=$(grep -m1 -E "^[[:space:]]*$1[[:space:]]*=" "$(env_file)" 2>/dev/null | cut -d= -f2- | sed -E 's/^[[:space:]]+|[[:space:]]+$//g')
  v=${v#[\"\']}; v=${v%[\"\']}
  printf '%s' "${v:-${2:-}}"
}
php_version() { env_get HOSTKEEP_PHP_VERSION "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"; }
domain() {
  local d; d=$(env_get HOSTKEEP_DOMAIN)
  [[ $d =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,}$ && $d != admin.example.com ]] || die "renseigne HOSTKEEP_DOMAIN dans $(env_file)."
  echo "$d"
}
# Nginx < 1.25.1 (Debian 12) has no "http2 on;": use the older "listen ... http2" form instead.
nginx_compat() {
  local v; v=$(nginx -v 2>&1 | sed -n 's#.*nginx/\([0-9.]*\).*#\1#p')
  if [[ -n $v ]] && dpkg --compare-versions "$v" lt 1.25.1; then
    sed -i -e '/^[[:space:]]*http2 on;/d' -e 's/listen 443 ssl;/listen 443 ssl http2;/' -e 's/listen \[::\]:443 ssl;/listen [::]:443 ssl http2;/' "$1"
  fi
}
# Copies a template from system/, replacing __DOMAIN__ and __PHP_VERSION__.
render() { sed -e "s/__DOMAIN__/${3:-}/g" -e "s/__PHP_VERSION__/$(php_version)/g" "$SRC/system/$1" > "$2"; chmod 644 "$2"; }

# Debian 12+ logs to journald only (no /var/log/auth.log): fail2ban's default sshd jail then fails
# to start, taking every other jail down. Read sshd from the journal instead.
fail2ban_journal() {
  [[ -e /var/log/auth.log ]] && return 0
  [[ -e /etc/fail2ban/jail.d/00-hostkeep-journal.conf ]] && return 0
  printf '%s\n' "# Hostkeep: no /var/log/auth.log on this system, read sshd from the systemd journal." "[sshd]" "backend = systemd" \
    > /etc/fail2ban/jail.d/00-hostkeep-journal.conf
  echo "fail2ban : jail sshd basculée sur le journal systemd (pas de /var/log/auth.log)."
}

certificate() {   # $1 = apache2|nginx
  local d email; d=$(domain); email=$(env_get HOSTKEEP_LETSENCRYPT_EMAIL)
  step "certificat Let's Encrypt pour $d"
  install -d -m 755 "$APP/public/.well-known/acme-challenge"
  certbot certonly --webroot -w "$APP/public" -d "$d" --cert-name "$d" --non-interactive --agree-tos --keep-until-expiring \
    ${email:+--email "$email"} ${email:---register-unsafely-without-email} --deploy-hook "systemctl reload $1"
  [[ -s /etc/letsencrypt/live/$d/fullchain.pem ]] || die "pas de certificat. Vérifie que le DNS de $d pointe vers ce serveur et que le port 80 est ouvert."
}

case ${1:-} in
deps)
  step "paquets système"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y php-fpm php-cli php-mysql php-curl php-mbstring mariadb-server fail2ban python3-systemd certbot sudo curl python3 logrotate
  fail2ban_journal
  systemctl enable --now mariadb
  systemctl enable -q fail2ban && systemctl restart fail2ban
  echo "Paquets installés (PHP $(php_version))." ;;

app)
  [[ -f $(env_file) ]] || die "copie d'abord la configuration : cp .env.example .env, puis édite-la."
  PHPV=$(php_version)
  [[ -n $PHPV && -d /etc/php/$PHPV/fpm ]] || die "PHP-FPM $PHPV introuvable. Lance d'abord : sudo ./install.sh deps"
  command -v mysql >/dev/null || die "MariaDB introuvable. Lance d'abord : sudo ./install.sh deps"

  step "utilisateur système hostkeep"
  id hostkeep >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin hostkeep

  step "dossiers d'état et de logs"
  install -d -o root -g hostkeep -m 750 /var/lib/hostkeep /var/lib/hostkeep/jobs
  install -d -o hostkeep -g hostkeep -m 700 /var/lib/hostkeep/sessions
  install -d -o hostkeep -g adm -m 750 /var/log/hostkeep
  install -d -o root -g hostkeep -m 750 /var/backups/hostkeep
  for f in auth.log php-error.log; do [[ -e /var/log/hostkeep/$f ]] || install -o hostkeep -g adm -m 640 /dev/null "/var/log/hostkeep/$f"; done

  step "code de l'application ($APP)"
  install -d -o root -g root -m 755 "$APP"
  rm -rf "$APP/src" "$APP/bin"
  find "$APP/public" -mindepth 1 -maxdepth 1 ! -name .well-known -exec rm -rf {} + 2>/dev/null || true
  cp -r "$SRC/app/public" "$SRC/app/src" "$SRC/app/bin" "$APP/"
  chown -R root:hostkeep "$APP/public" "$APP/src" "$APP/bin"
  # public/: served by the web server -> world-readable, no secret inside. src/ bin/ .env: group hostkeep only.
  find "$APP/public" -type d -exec chmod 755 {} +; find "$APP/public" -type f -exec chmod 644 {} +
  find "$APP/src" "$APP/bin" -type d -exec chmod 750 {} +; find "$APP/src" "$APP/bin" -type f -exec chmod 640 {} +
  [[ -e $APP/.env ]] || install -o root -g hostkeep -m 640 "$SRC/.env" "$APP/.env"
  for f in $(find "$APP" -name '*.php'); do php -l "$f" >/dev/null; done

  step "base de données"
  mysql < "$SRC/app/bin/schema.sql"

  step "helper root + sudoers"
  install -o root -g root -m 750 "$SRC/system/hostkeep-action" /usr/local/sbin/hostkeep-action
  visudo -cf "$SRC/system/sudoers-hostkeep"
  install -o root -g root -m 440 "$SRC/system/sudoers-hostkeep" /etc/sudoers.d/hostkeep
  sudo -n -u hostkeep sudo -n -l /usr/local/sbin/hostkeep-action >/dev/null || die "règle sudoers non prise en compte."

  step "pool PHP-FPM $PHPV"
  render php-fpm-hostkeep.conf "/etc/php/$PHPV/fpm/pool.d/hostkeep.conf"
  if ! "php-fpm$PHPV" -t 2>&1 | tail -1; then rm -f "/etc/php/$PHPV/fpm/pool.d/hostkeep.conf"; die "config PHP-FPM invalide : retirée."; fi
  systemctl enable -q "php$PHPV-fpm" && systemctl reload-or-restart "php$PHPV-fpm"

  step "fail2ban"
  fail2ban_journal
  install -m 644 "$SRC/system/fail2ban-filter-hostkeep.conf" /etc/fail2ban/filter.d/hostkeep.conf
  install -m 644 "$SRC/system/fail2ban-jail-hostkeep.conf" /etc/fail2ban/jail.d/hostkeep.conf
  if ! fail2ban-client -t >/dev/null; then rm -f /etc/fail2ban/jail.d/hostkeep.conf /etc/fail2ban/filter.d/hostkeep.conf; die "config fail2ban invalide : retirée."; fi
  systemctl is-active -q fail2ban && fail2ban-client reload >/dev/null || systemctl restart fail2ban

  step "MariaDB : journal des requêtes lentes (> 1 s), sans redémarrage"
  install -d -o mysql -g adm -m 2750 /var/log/mysql
  install -m 644 "$SRC/system/mariadb-hostkeep.cnf" /etc/mysql/mariadb.conf.d/90-hostkeep.cnf
  mysql -e "SET GLOBAL slow_query_log_file = '/var/log/mysql/mariadb-slow.log'; SET GLOBAL long_query_time = 1; SET GLOBAL slow_query_log = 1;"

  step "timers systemd de collecte + logrotate"
  rm -f /etc/cron.d/hostkeep   # older versions used cron
  install -m 644 "$SRC"/system/systemd/hostkeep-* /etc/systemd/system/
  install -m 644 "$SRC/system/logrotate-hostkeep" /etc/logrotate.d/hostkeep
  systemctl daemon-reload
  systemctl enable --now hostkeep-{minute,5min,hourly,purge,backup,scan}.timer

  step "première collecte"
  php "$APP/bin/collect.php" all && echo "OK"
  if [[ -z $(env_get HOSTKEEP_PASSWORD_HASH) ]]; then
    echo; echo "Définis maintenant ton mot de passe :  sudo php $APP/bin/set-password.php"
  fi ;;

apache)
  D=$(domain)
  step "Apache + modules"
  command -v apache2 >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y apache2
  a2enmod -q ssl headers proxy_fcgi alias >/dev/null
  render hostkeep.conf /etc/apache2/sites-available/hostkeep.conf "$D"
  install -m 644 "$SRC/system/apache-hostkeep-traffic.conf" /etc/apache2/conf-available/hostkeep-traffic.conf
  install -m 644 "$SRC/system/apache-hardening.conf" /etc/apache2/conf-available/hardening.conf
  a2ensite -q hostkeep; a2enconf -q hostkeep-traffic hardening
  apache2ctl configtest && systemctl reload-or-restart apache2
  certificate apache2
  step "vhost HTTPS"
  render hostkeep-le-ssl.conf /etc/apache2/sites-available/hostkeep-le-ssl.conf "$D"
  a2ensite -q hostkeep-le-ssl
  if ! apache2ctl configtest; then a2dissite -q hostkeep-le-ssl; die "configtest en échec : vhost HTTPS désactivé, pas de reload."; fi
  systemctl reload-or-restart apache2 && echo "Panel en ligne : https://$D" ;;

nginx)
  D=$(domain)
  step "Nginx"
  command -v nginx >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y nginx
  # First an HTTP-only server block, so certbot can answer the challenge.
  render nginx-hostkeep.conf /etc/nginx/sites-available/hostkeep.conf "$D"
  if [[ ! -s /etc/letsencrypt/live/$D/fullchain.pem ]]; then
    awk 'BEGIN{n=0} /^server \{/{n++} n<2' /etc/nginx/sites-available/hostkeep.conf > /etc/nginx/sites-available/hostkeep.conf.tmp
    mv /etc/nginx/sites-available/hostkeep.conf.tmp /etc/nginx/sites-available/hostkeep.conf
  fi
  ln -sf /etc/nginx/sites-available/hostkeep.conf /etc/nginx/sites-enabled/hostkeep.conf
  nginx -t && systemctl reload-or-restart nginx
  certificate nginx
  step "server block HTTPS"
  render nginx-hostkeep.conf /etc/nginx/sites-available/hostkeep.conf "$D"
  nginx_compat /etc/nginx/sites-available/hostkeep.conf
  if ! nginx -t; then rm -f /etc/nginx/sites-enabled/hostkeep.conf; die "nginx -t en échec : site désactivé, pas de reload."; fi
  systemctl reload-or-restart nginx && echo "Panel en ligne : https://$D" ;;

uninstall)
  PHPV=$(php_version)
  step "désactivation du panel"
  systemctl disable --now hostkeep-{minute,5min,hourly,purge,backup,scan}.timer 2>/dev/null || true
  rm -f /etc/systemd/system/hostkeep-* && systemctl daemon-reload
  if command -v a2dissite >/dev/null; then
    a2dissite -q hostkeep hostkeep-le-ssl 2>/dev/null || true; a2disconf -q hostkeep-traffic 2>/dev/null || true
    rm -f /etc/apache2/sites-available/hostkeep*.conf /etc/apache2/conf-available/hostkeep-traffic.conf
    apache2ctl configtest 2>/dev/null && systemctl reload apache2 2>/dev/null || true
  fi
  if [[ -e /etc/nginx/sites-available/hostkeep.conf ]]; then
    rm -f /etc/nginx/sites-enabled/hostkeep.conf /etc/nginx/sites-available/hostkeep.conf
    nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null || true
  fi
  rm -f /etc/sudoers.d/hostkeep /usr/local/sbin/hostkeep-action "/etc/php/$PHPV/fpm/pool.d/hostkeep.conf" \
        /etc/fail2ban/jail.d/hostkeep.conf /etc/fail2ban/filter.d/hostkeep.conf /etc/logrotate.d/hostkeep \
        /etc/mysql/mariadb.conf.d/90-hostkeep.cnf /etc/cron.d/hostkeep
  # /etc/fail2ban/jail.d/00-hostkeep-journal.conf is kept: it fixes the system's own sshd jail.
  systemctl reload "php$PHPV-fpm" 2>/dev/null || true
  fail2ban-client reload >/dev/null 2>&1 || true
  echo "Le fichier /etc/apache2/conf-available/hardening.conf (protection des fichiers sensibles) est conservé."
  if [[ ${2:-} == --purge ]]; then
    read -r -p "Supprimer aussi la base « hostkeep », les sauvegardes, les logs et $APP ? Tape OUI : " ok
    [[ $ok == OUI ]] || die "annulé."
    mysql -e "DROP DATABASE IF EXISTS hostkeep; DROP USER IF EXISTS 'hostkeep'@'localhost';"
    rm -rf "$APP" /var/lib/hostkeep /var/log/hostkeep /var/backups/hostkeep
    userdel hostkeep 2>/dev/null || true
    echo "Hostkeep entièrement supprimé (le certificat Let's Encrypt reste : certbot delete --cert-name <domaine>)."
  else
    echo "Panel désinstallé. Base, sauvegardes et $APP conservés (ajoute --purge pour tout supprimer)."
  fi ;;

*) sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
