#!/bin/bash
# Installs / updates the admin dashboard. Run as root from the unpacked source folder:
#   bash install.sh app   -> system user, files, database, root helper, sudoers, PHP-FPM pool, cron, fail2ban, logrotate
#   bash install.sh web   -> certificate (certonly, touches no other vhost) + the two vhosts, configtest, reload
# Idempotent: running it again updates the code and keeps config.php (the password).
set -euo pipefail
SRC=$(cd "$(dirname "$0")" && pwd)
APP=/var/www/admin-dashboard
HOST=admin.mmi24c04.mmi-troyes.fr
step() { echo "== $*"; }

case ${1:-} in
app)
  step "utilisateur système dashboard"
  id dashboard >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin dashboard

  step "dossiers d'état et de logs"
  install -d -o root -g dashboard -m 750 /var/lib/dashboard /var/lib/dashboard/jobs
  install -d -o dashboard -g dashboard -m 700 /var/lib/dashboard/sessions
  install -d -o dashboard -g adm -m 750 /var/log/dashboard
  install -d -o root -g dashboard -m 750 /var/backups/dashboard-db
  for f in auth.log php-error.log; do [ -e "/var/log/dashboard/$f" ] || install -o dashboard -g adm -m 640 /dev/null "/var/log/dashboard/$f"; done
  [ -e /var/log/dashboard/collect.log ] || install -o root -g adm -m 640 /dev/null /var/log/dashboard/collect.log

  step "code de l'application ($APP)"
  install -d -o root -g root -m 755 "$APP"
  rm -rf "$APP/public" "$APP/src" "$APP/bin"
  cp -r "$SRC/app/public" "$SRC/app/src" "$SRC/app/bin" "$APP/"
  chown -R root:dashboard "$APP/public" "$APP/src" "$APP/bin"
  # public/: served by Apache (assets) -> world-readable, no secret inside. src/ bin/: group dashboard only.
  find "$APP/public" -type d -exec chmod 755 {} +; find "$APP/public" -type f -exec chmod 644 {} +
  find "$APP/src" "$APP/bin" -type d -exec chmod 750 {} +; find "$APP/src" "$APP/bin" -type f -exec chmod 640 {} +
  [ -e "$APP/config.php" ] || install -o root -g dashboard -m 640 "$SRC/app/config.sample.php" "$APP/config.php"
  for f in $(find "$APP" -name '*.php'); do php -l "$f" >/dev/null; done

  step "base de données"
  mysql < "$SRC/app/bin/schema.sql"

  step "helper root + sudoers"
  install -o root -g root -m 750 "$SRC/system/dashboard-action" /usr/local/sbin/dashboard-action
  visudo -cf "$SRC/system/sudoers-dashboard"
  install -o root -g root -m 440 "$SRC/system/sudoers-dashboard" /etc/sudoers.d/dashboard
  sudo -n -u dashboard sudo -n -l /usr/local/sbin/dashboard-action >/dev/null || { echo "Règle sudoers non prise en compte."; exit 1; }

  step "pool PHP-FPM"
  install -m 644 "$SRC/system/php-fpm-dashboard.conf" /etc/php/8.4/fpm/pool.d/dashboard.conf
  if ! php-fpm8.4 -t 2>&1 | tail -1; then rm -f /etc/php/8.4/fpm/pool.d/dashboard.conf; echo "Config FPM invalide : retirée."; exit 1; fi
  systemctl reload php8.4-fpm

  step "fail2ban"
  install -m 644 "$SRC/system/fail2ban-filter-dashboard.conf" /etc/fail2ban/filter.d/dashboard.conf
  install -m 644 "$SRC/system/fail2ban-jail-dashboard.conf" /etc/fail2ban/jail.d/dashboard.conf
  if ! fail2ban-client -t >/dev/null; then rm -f /etc/fail2ban/jail.d/dashboard.conf /etc/fail2ban/filter.d/dashboard.conf; echo "Config fail2ban invalide : retirée."; exit 1; fi
  fail2ban-client reload >/dev/null

  step "MariaDB : journal des requêtes lentes (> 1 s), sans redémarrage"
  install -d -o mysql -g adm -m 2750 /var/log/mysql
  install -m 644 "$SRC/system/mariadb-dashboard.cnf" /etc/mysql/mariadb.conf.d/90-dashboard.cnf
  mysql -e "SET GLOBAL slow_query_log_file = '/var/log/mysql/mariadb-slow.log'; SET GLOBAL long_query_time = 1; SET GLOBAL slow_query_log = 1;"

  step "cron + logrotate"
  install -m 644 "$SRC/system/cron-dashboard" /etc/cron.d/dashboard
  install -m 644 "$SRC/system/logrotate-dashboard" /etc/logrotate.d/dashboard
  # /var/log/mysql/mariadb-slow.log is already rotated by Debian's /etc/logrotate.d/mariadb.

  step "première collecte"
  php "$APP/bin/collect.php" all && echo "OK" ;;

web)
  step "certificat $HOST"
  certbot certonly --apache -d "$HOST" --cert-name "$HOST" --non-interactive --keep-until-expiring 2>&1 | grep -E "Successfully|Certificate not yet due|saved at|error|Error" || true
  [ -s "/etc/letsencrypt/live/$HOST/fullchain.pem" ] || { echo "Pas de certificat : arrêt."; exit 1; }

  step "vhosts"
  install -m 644 "$SRC/system/admin-dashboard.conf" /etc/apache2/sites-available/admin-dashboard.conf
  install -m 644 "$SRC/system/admin-dashboard-le-ssl.conf" /etc/apache2/sites-available/admin-dashboard-le-ssl.conf
  install -m 644 "$SRC/system/apache-dashboard-traffic.conf" /etc/apache2/conf-available/dashboard-traffic.conf
  install -m 644 "$SRC/system/apache-hardening.conf" /etc/apache2/conf-available/hardening.conf
  a2ensite -q admin-dashboard admin-dashboard-le-ssl
  a2enconf -q dashboard-traffic hardening
  if ! apache2ctl configtest; then
    a2dissite -q admin-dashboard admin-dashboard-le-ssl; a2disconf -q dashboard-traffic hardening
    echo "configtest en échec : config du dashboard désactivée, pas de reload."; exit 1
  fi
  systemctl reload apache2 && echo "Apache rechargé." ;;

*) echo "usage: bash install.sh app|web" >&2; exit 1 ;;
esac
