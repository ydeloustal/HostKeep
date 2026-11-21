#!/bin/bash
# Hostkeep installer, for Debian 12/13 and Ubuntu 22.04/24.04.
#
# One line, on the server:
#   curl -fsSL https://raw.githubusercontent.com/ydeloustal/HostKeep/main/install.sh | sudo bash
#   curl -fsSL …/install.sh | sudo bash -s -- --domain admin.example.com --email moi@example.com --yes
#
# From a clone (/opt/hostkeep), or later through « sudo hostkeep … »:
#   sudo ./install.sh [install] [options]    full guided install (default)
#   sudo ./install.sh deps                   check and install the missing prerequisites
#   sudo ./install.sh app                    install / update the application (keeps .env, database, accounts)
#   sudo ./install.sh web [MODE] [options]   (re)configure the web front: auto apache nginx caddy proxy none
#   sudo ./install.sh admin [options]        create the first Super Admin
#   sudo ./install.sh status                 what was detected, state of the services
#   sudo ./install.sh uninstall [--purge]    remove the panel (--purge: also database, backups, logs)
#
# Options:
#   --domain D         domain of the panel; its DNS A/AAAA record must point to this server
#   --email E          e-mail for Let's Encrypt (certificate expiry warnings)
#   --web MODE         auto (default: detects what already listens on ports 80/443), apache, nginx, caddy,
#                      proxy (Traefik, HAProxy, Nginx Proxy Manager… in front), none (configure it yourself)
#   --https yes|no     Let's Encrypt certificate + redirect to HTTPS (default yes)
#   --listen ADDR      proxy mode: local HTTP address of the panel (default 127.0.0.1:8090)
#   --admin-user U     first Super Admin (default admin); --admin-email E; password generated, shown once
#   --harden-apache    also enable the server-wide Apache rules (deny .env, .git, dumps… on EVERY site)
#   --repo URL --branch B --dir D   source of the one-line install (default: GitHub, main, /opt/hostkeep)
#   --yes, -y          no questions: options above and defaults only
#
# Every step is idempotent. Existing sites are never modified: the panel gets its own vhost / server
# block / site file, each change is syntax-checked and rolled back on error before any reload.
set -euo pipefail
umask 022
export LC_ALL=C.UTF-8 DEBIAN_FRONTEND=noninteractive

REPO=https://github.com/ydeloustal/HostKeep.git
BRANCH=main
SRC_DIR=/opt/hostkeep
APP=/var/www/hostkeep
ETC=/etc/hostkeep
SOCK=/run/hostkeep/php-fpm.sock
ROLLBACK_DIR=/var/lib/hostkeep/rollback

# ---------- output ----------
if [[ -t 1 ]]; then B=$'\e[1m'; G=$'\e[32m'; Y=$'\e[33m'; R=$'\e[31m'; C=$'\e[36m'; N=$'\e[0m'; else B='' G='' Y='' R='' C='' N=''; fi
step() { echo; echo "${B}${C}==>${N}${B} $*${N}"; }
ok() { echo "  ${G}✔${N} $*"; }
warn() { echo "  ${Y}!${N} $*" >&2; }
die() { echo "${R}Erreur :${N} $*" >&2; exit 1; }

# ---------- options ----------
CMD=''; DOMAIN=''; EMAIL=''; WEB=''; HTTPS=''; LISTEN=''; ADMIN_USER=''; ADMIN_EMAIL=''; HARDEN=0; YES=0; PURGE=0
ARGS=("$@")
parse_args() {
  while (($#)); do
    case $1 in
      --domain) DOMAIN=${2:-}; shift ;;  --domain=*) DOMAIN=${1#*=} ;;
      --email) EMAIL=${2:-}; shift ;;  --email=*) EMAIL=${1#*=} ;;
      --web) WEB=${2:-}; shift ;;  --web=*) WEB=${1#*=} ;;
      --https) HTTPS=${2:-}; shift ;;  --https=*) HTTPS=${1#*=} ;;
      --listen) LISTEN=${2:-}; shift ;;  --listen=*) LISTEN=${1#*=} ;;
      --admin-user) ADMIN_USER=${2:-}; shift ;;  --admin-user=*) ADMIN_USER=${1#*=} ;;
      --admin-email) ADMIN_EMAIL=${2:-}; shift ;;  --admin-email=*) ADMIN_EMAIL=${1#*=} ;;
      --repo) REPO=${2:-}; shift ;;  --repo=*) REPO=${1#*=} ;;
      --branch) BRANCH=${2:-}; shift ;;  --branch=*) BRANCH=${1#*=} ;;
      --dir) SRC_DIR=${2:-}; shift ;;  --dir=*) SRC_DIR=${1#*=} ;;
      --harden-apache) HARDEN=1 ;;
      --purge) PURGE=1 ;;
      -y|--yes|--non-interactive) YES=1 ;;
      -h|--help|help) CMD=help ;;
      install|deps|app|web|admin|status|uninstall) [[ -z $CMD ]] && CMD=$1 ;;
      apache|nginx|caddy|proxy|none|auto) if [[ -z $CMD ]]; then CMD=web; WEB=$1; elif [[ $CMD == web ]]; then WEB=$1; else die "argument inattendu : $1"; fi ;;
      *) die "option inconnue : $1 (voir --help)" ;;
    esac
    shift
  done
  CMD=${CMD:-install}
  [[ -z $DOMAIN ]] || valid_domain "$DOMAIN" || die "domaine invalide : $DOMAIN"
  [[ -z $EMAIL ]] || valid_email "$EMAIL" || die "e-mail invalide : $EMAIL"
  [[ -z $ADMIN_EMAIL ]] || valid_email "$ADMIN_EMAIL" || die "e-mail invalide : $ADMIN_EMAIL"
  [[ -z $ADMIN_USER || $ADMIN_USER =~ ^[A-Za-z0-9_.-]{3,40}$ ]] || die "identifiant invalide : $ADMIN_USER"
  [[ -z $WEB || $WEB =~ ^(auto|apache|nginx|caddy|proxy|none)$ ]] || die "--web : auto, apache, nginx, caddy, proxy ou none"
  [[ -z $HTTPS || $HTTPS =~ ^(yes|no)$ ]] || die "--https : yes ou no"
  [[ -z $LISTEN ]] || valid_listen "$LISTEN" || die "--listen : adresse:port, ex. 127.0.0.1:8090"
  return 0
}
valid_domain() { [[ ${#1} -le 253 && $1 =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ && $1 != *example.com ]]; }
valid_email() { [[ ${#1} -le 190 && $1 =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; }
valid_username() { [[ $1 =~ ^[A-Za-z0-9_.-]{3,40}$ ]]; }
valid_listen() { [[ $1 =~ ^(\[[0-9a-fA-F:]+\]|[0-9]{1,3}(\.[0-9]{1,3}){3}|localhost):[0-9]{2,5}$ ]]; }

# ---------- questions (read from the terminal, even when the script comes from « curl | bash ») ----------
has_tty() { ((YES == 0)) && { true </dev/tty; } 2>/dev/null; }
ask() {   # ask VAR "question" [default] [validator]
  local __var=$1 q=$2 def=${3:-} check=${4:-} ans
  if ! has_tty; then printf -v "$__var" '%s' "$def"; return 0; fi
  while true; do
    read -r -p "  ${B}?${N} $q${def:+ [$def]} : " ans </dev/tty || ans=''
    ans=${ans:-$def}
    if [[ -z $check ]] || [[ -z $ans && -z $def ]] || $check "$ans"; then printf -v "$__var" '%s' "$ans"; return 0; fi
    echo "    Valeur invalide." >&2
  done
}
confirm() {   # confirm "question" y|n
  local def=${2:-y} ans
  has_tty || { [[ $def == y ]]; return; }
  read -r -p "  ${B}?${N} $1 [$([[ $def == y ]] && echo 'O/n' || echo 'o/N')] : " ans </dev/tty || ans=''
  ans=$(printf '%s' "${ans:-$def}" | tr '[:upper:]' '[:lower:]')
  [[ $ans =~ ^(o|oui|y|yes)$ ]]
}

# ---------- one-line install: fetch the repository, then run the installer from it ----------
bootstrap() {
  [[ $(id -u) -eq 0 ]] || die "lance l'installation en root : curl … | sudo bash"
  [[ -r /etc/debian_version ]] || die "seuls Debian et Ubuntu sont pris en charge."
  step "Récupération de Hostkeep ($REPO, branche $BRANCH)"
  if ! command -v git >/dev/null; then apt-get update -qq </dev/null && apt-get install -y -qq git ca-certificates </dev/null >/dev/null; fi
  if [[ -d $SRC_DIR/.git ]]; then
    git -C "$SRC_DIR" fetch -q origin "$BRANCH"
    if [[ -n $(git -C "$SRC_DIR" status --porcelain --untracked-files=no) ]]; then
      warn "$SRC_DIR contient des modifications locales : code laissé tel quel."
    else
      git -C "$SRC_DIR" checkout -q "$BRANCH" && git -C "$SRC_DIR" merge -q --ff-only "origin/$BRANCH"
    fi
  elif [[ -e $SRC_DIR ]]; then
    die "$SRC_DIR existe et n'est pas un clone git : choisis un autre dossier avec --dir."
  else
    git clone -q --branch "$BRANCH" "$REPO" "$SRC_DIR"
  fi
  chown -R root:root "$SRC_DIR"
  ok "Code dans $SRC_DIR ($(git -C "$SRC_DIR" log -1 --format='%h, %cd' --date=short))"
  exec bash "$SRC_DIR/install.sh" "${ARGS[@]}"
}

# ---------- settings (.env of the installed app) ----------
env_file() { if [[ -f $APP/.env ]]; then echo "$APP/.env"; elif [[ -f $SRC/.env ]]; then echo "$SRC/.env"; else echo "$SRC/.env.example"; fi; }
env_get() {
  local v
  v=$(grep -m1 -E "^[[:space:]]*$1[[:space:]]*=" "$(env_file)" 2>/dev/null | cut -d= -f2- | sed -E 's/^[[:space:]]+|[[:space:]]+$//g') || true
  v=${v#[\"\']}; v=${v%[\"\']}
  printf '%s' "${v:-${2:-}}"
}
# Values written here are validated beforehand (domain, e-mail, mode, address, base64 key): no quoting issue.
env_set() {
  local f=$APP/.env tmp
  [[ -f $f ]] || return 0
  tmp=$(mktemp "$f.XXXX")
  awk -v k="$1" -v v="$2" 'BEGIN{d=0} $0 ~ "^[[:space:]]*" k "[[:space:]]*=" {print k "=" v; d=1; next} {print} END{if(!d) print k "=" v}' "$f" > "$tmp"
  chown root:hostkeep "$tmp"; chmod 640 "$tmp"; mv -f "$tmp" "$f"
}
php_version() { env_get HOSTKEEP_PHP_VERSION "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"; }
render() { sed -e "s/__DOMAIN__/${3:-}/g" -e "s/__PHP_VERSION__/$(php_version)/g" -e "s/__WEB_GROUP__/${4:-www-data}/g" "$SRC/system/$1" > "$2"; chmod 644 "$2"; }

# ---------- rollback of web configuration files ----------
TOUCHED=()
keep() {   # save a file (or its absence) before changing it
  local f
  for f in "$@"; do
    [[ " ${TOUCHED[*]} " == *" $f "* ]] && continue   # first saved state wins
    mkdir -p "$ROLLBACK_DIR$(dirname "$f")"
    rm -rf "$ROLLBACK_DIR$f" "$ROLLBACK_DIR$f.absent"
    if [[ -e $f || -L $f ]]; then cp -a "$f" "$ROLLBACK_DIR$f"; else : > "$ROLLBACK_DIR$f.absent"; fi
    TOUCHED+=("$f")
  done
}
rollback() {
  local f
  ((${#TOUCHED[@]})) || return 0
  warn "retour à la configuration précédente…"
  for f in "${TOUCHED[@]}"; do
    if [[ -e $ROLLBACK_DIR$f.absent ]]; then rm -f "$f"; elif [[ -e $ROLLBACK_DIR$f || -L $ROLLBACK_DIR$f ]]; then rm -f "$f"; cp -a "$ROLLBACK_DIR$f" "$f"; fi
  done
  TOUCHED=()
  rm -rf "$ROLLBACK_DIR"
}
commit_changes() { TOUCHED=(); rm -rf "$ROLLBACK_DIR"; }

# ---------- environment detection ----------
os_check() {
  [[ $(id -u) -eq 0 ]] || die "lance ce script avec sudo."
  [[ -r /etc/os-release ]] || die "système non reconnu."
  # shellcheck disable=SC1091
  . /etc/os-release
  case "$ID:${VERSION_ID:-}" in
    debian:12|debian:13|ubuntu:22.04|ubuntu:24.04) ;;
    debian:*|ubuntu:*) warn "$PRETTY_NAME n'est pas testé (Debian 12/13, Ubuntu 22.04/24.04) : on continue." ;;
    *) [[ ${ID_LIKE:-} == *debian* ]] || die "seuls Debian et Ubuntu sont pris en charge ($PRETTY_NAME)."
       warn "$PRETTY_NAME : dérivé de Debian non testé, on continue." ;;
  esac
  command -v systemctl >/dev/null && [[ -d /run/systemd/system ]] || die "systemd est nécessaire (conteneur sans systemd ?)."
}
port_owner() { ss -Hltnp "sport = :$1" 2>/dev/null | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' | sort -u | head -n1; }
svc_installed() { systemctl cat "$1.service" >/dev/null 2>&1; }
docker_traefik() { command -v docker >/dev/null && docker ps --format '{{.Image}} {{.Names}}' 2>/dev/null | grep -qi traefik; }
# What serves ports 80/443 today. Sets DETECTED (apache | nginx | caddy | proxy | empty) and DETECTED_WHAT (explanation).
DETECTED=''; DETECTED_WHAT=''
detect_web() {
  local own; own=$(port_owner 443); own=${own:-$(port_owner 80)}
  case $own in
    apache2|httpd) DETECTED=apache; DETECTED_WHAT="Apache écoute sur les ports 80/443" ;;
    nginx)
      if systemctl is-active -q nginx 2>/dev/null; then DETECTED=nginx; DETECTED_WHAT="Nginx écoute sur les ports 80/443"
      else DETECTED=proxy; DETECTED_WHAT="un Nginx hors systemd (conteneur ?) écoute sur les ports 80/443"; fi ;;
    caddy) DETECTED=caddy; DETECTED_WHAT="Caddy écoute sur les ports 80/443" ;;
    traefik) DETECTED=proxy; DETECTED_WHAT="Traefik écoute sur les ports 80/443" ;;
    docker-proxy)
      DETECTED=proxy
      if docker_traefik; then DETECTED_WHAT="Traefik (Docker) écoute sur les ports 80/443"; else DETECTED_WHAT="un conteneur Docker écoute sur les ports 80/443 (reverse proxy ?)"; fi ;;
    '')
      if svc_installed apache2; then DETECTED=apache; DETECTED_WHAT="Apache installé (arrêté)"
      elif svc_installed nginx; then DETECTED=nginx; DETECTED_WHAT="Nginx installé (arrêté)"
      elif svc_installed caddy; then DETECTED=caddy; DETECTED_WHAT="Caddy installé (arrêté)"
      else DETECTED=''; DETECTED_WHAT="aucun serveur web, ports 80/443 libres"; fi ;;
    *) DETECTED=proxy; DETECTED_WHAT="« $own » écoute sur les ports 80/443" ;;
  esac
}
web_group() { case $1 in caddy) echo caddy ;; *) echo www-data ;; esac; }
row() { local pad=$(( ${2:-22} - ${#1} )); ((pad > 0)) || pad=1; printf '  %s%*s %s\n' "$1" "$pad" '' "$3"; }   # aligned even with accents
public_ips() { ip -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1; }
dns_check() {   # warn (not fatal) when the domain does not resolve to this server
  local d=$1 ips mine
  ips=$(getent ahosts "$d" 2>/dev/null | awk '{print $1}' | sort -u | xargs)
  if [[ -z $ips ]]; then warn "$d ne résout vers aucune adresse : crée l'enregistrement DNS A/AAAA avant de demander un certificat."; return 1; fi
  mine=$(public_ips | xargs)
  for ip in $ips; do [[ " $mine " == *" $ip "* ]] && { ok "DNS : $d → $ip (ce serveur)"; return 0; }; done
  warn "DNS : $d → $ips ; ce serveur : ${mine:-?}. Normal derrière un NAT ou un proxy (Cloudflare…), sinon corrige le DNS."
  return 0
}

# ---------- deps ----------
PKGS=(php-fpm php-cli php-mysql php-curl php-mbstring mariadb-server fail2ban python3-systemd certbot sudo curl python3 logrotate git ca-certificates iproute2 rclone)
cmd_deps() {
  os_check
  step "Prérequis"
  if dpkg -s mysql-server >/dev/null 2>&1 && ! dpkg -s mariadb-server >/dev/null 2>&1; then
    die "MySQL (Oracle) est installé : Hostkeep a besoin de MariaDB (authentification par socket unix_socket)."
  fi
  local missing=() p
  for p in "${PKGS[@]}"; do
    if dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'ok installed'; then ok "$p"; else missing+=("$p"); echo "  ${Y}✗${N} $p (à installer)"; fi
  done
  if ((${#missing[@]})); then
    step "Installation de ${#missing[@]} paquet(s) : ${missing[*]}"
    apt-get update -q </dev/null
    apt-get install -y -q "${missing[@]}" </dev/null
  fi
  local v; v=$(php -r 'echo PHP_VERSION;')
  dpkg --compare-versions "$v" ge 8.1 || die "PHP $v trop ancien : Hostkeep demande PHP 8.1 ou plus (Debian 12+, Ubuntu 22.04+)."
  ok "PHP $v"
  fail2ban_journal
  systemctl enable -q --now mariadb
  ok "MariaDB $(mysql -N -e 'SELECT VERSION()' 2>/dev/null || echo '?')"
  systemctl enable -q fail2ban && systemctl restart fail2ban
}
# Debian 12+ logs to journald only (no /var/log/auth.log): fail2ban's default sshd jail then fails
# to start, taking every other jail down. Read sshd from the journal instead.
fail2ban_journal() {
  [[ -e /var/log/auth.log || -e /etc/fail2ban/jail.d/00-hostkeep-journal.conf ]] && return 0
  [[ -d /etc/fail2ban/jail.d ]] || return 0
  printf '%s\n' "# Hostkeep: no /var/log/auth.log on this system, read sshd from the systemd journal." "[sshd]" "backend = systemd" \
    > /etc/fail2ban/jail.d/00-hostkeep-journal.conf
  ok "fail2ban : jail sshd basculée sur le journal systemd"
}

# ---------- app ----------
legacy_web_mode() {
  if [[ -e /etc/apache2/sites-enabled/hostkeep-le-ssl.conf || -e /etc/apache2/sites-enabled/hostkeep.conf ]]; then echo apache
  elif [[ -e /etc/nginx/sites-enabled/hostkeep.conf || -e /etc/nginx/conf.d/hostkeep.conf ]]; then echo nginx
  elif [[ -e /etc/caddy/hostkeep.caddy ]]; then echo caddy
  elif [[ -e $ETC/nginx-http.conf ]]; then echo proxy
  else echo ''; fi
}
cmd_app() {
  os_check
  local PHPV; PHPV=$(php_version)
  [[ -n $PHPV && -x /usr/sbin/php-fpm$PHPV ]] || die "PHP-FPM $PHPV introuvable. Lance d'abord : sudo $SRC/install.sh deps"
  command -v mysql >/dev/null || die "MariaDB introuvable. Lance d'abord : sudo $SRC/install.sh deps"
  if ! command -v rclone >/dev/null; then   # added in 2.4.0 (off-site copy of the backups): also on updates
    { apt_install_quiet rclone || { apt-get update -qq </dev/null && apt_install_quiet rclone; }; } && ok "rclone installé (copie externe des sauvegardes)" \
      || warn "rclone n'a pas pu être installé : la copie externe des sauvegardes restera indisponible (sudo apt install rclone)."
  fi

  step "Utilisateur système et dossiers"
  id hostkeep >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin hostkeep
  install -d -o root -g hostkeep -m 750 /var/lib/hostkeep /var/lib/hostkeep/jobs
  install -d -o hostkeep -g hostkeep -m 700 /var/lib/hostkeep/sessions
  install -d -o hostkeep -g adm -m 750 /var/log/hostkeep
  install -d -o root -g hostkeep -m 750 /var/backups/hostkeep
  install -d -o root -g root -m 755 "$ETC"
  local f
  for f in auth.log php-error.log php-fpm.log; do [[ -e /var/log/hostkeep/$f ]] || install -o hostkeep -g adm -m 640 /dev/null "/var/log/hostkeep/$f"; done
  ok "utilisateur hostkeep, /var/lib/hostkeep, /var/log/hostkeep, /var/backups/hostkeep"

  step "Code de l'application ($APP)"
  install -d -o root -g root -m 755 "$APP"
  rm -rf "${APP:?}/src" "${APP:?}/bin"
  find "$APP/public" -mindepth 1 -maxdepth 1 ! -name .well-known -exec rm -rf {} + 2>/dev/null || true
  cp -r "$SRC/app/public" "$SRC/app/src" "$SRC/app/bin" "$APP/"
  install -m 644 "$SRC/VERSION" "$APP/VERSION"
  chown -R root:hostkeep "$APP/public" "$APP/src" "$APP/bin"
  # public/: served by the web server -> world-readable, no secret inside. src/ bin/ .env: group hostkeep only.
  find "$APP/public" -type d -exec chmod 755 {} +; find "$APP/public" -type f -exec chmod 644 {} +
  find "$APP/src" "$APP/bin" -type d -exec chmod 750 {} +; find "$APP/src" "$APP/bin" -type f -exec chmod 640 {} +
  if [[ ! -e $APP/.env ]]; then
    if [[ -f $SRC/.env ]]; then install -o root -g hostkeep -m 640 "$SRC/.env" "$APP/.env"; else install -o root -g hostkeep -m 640 "$SRC/.env.example" "$APP/.env"; fi
  fi
  chown root:hostkeep "$APP/.env"; chmod 640 "$APP/.env"
  [[ -n $(env_get HOSTKEEP_APP_KEY) ]] || env_set HOSTKEEP_APP_KEY "$(head -c 32 /dev/urandom | base64)"
  [[ -n $(env_get HOSTKEEP_WEBSERVER) ]] || { local lw; lw=$(legacy_web_mode); [[ -z $lw ]] || env_set HOSTKEEP_WEBSERVER "$lw"; }
  # The "Mettre à jour" button pulls this clone, then reruns this script.
  if [[ -d $SRC/.git ]]; then printf '%s\n' "$SRC" > /var/lib/hostkeep/source; else rm -f /var/lib/hostkeep/source; fi
  find "$APP" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
  ok "version $(cat "$SRC/VERSION")"

  step "Base de données"
  mysql < "$SRC/app/bin/schema.sql"
  php "$APP/bin/user.php" migrate-env
  ok "base hostkeep à jour (accès par socket Unix, sans mot de passe)"

  step "Helper root, sudoers et commande « hostkeep »"
  install -o root -g root -m 750 "$SRC/system/hostkeep-action" /usr/local/sbin/hostkeep-action
  install -o root -g root -m 755 "$SRC/system/hostkeep-cli" /usr/local/bin/hostkeep
  visudo -cf "$SRC/system/sudoers-hostkeep" >/dev/null
  install -o root -g root -m 440 "$SRC/system/sudoers-hostkeep" /etc/sudoers.d/hostkeep
  sudo -n -u hostkeep sudo -n -l /usr/local/sbin/hostkeep-action >/dev/null || die "règle sudoers non prise en compte."
  ok "/usr/local/sbin/hostkeep-action, /usr/local/bin/hostkeep"

  step "Service systemd hostkeep (PHP-FPM $PHPV dédié)"
  render php-fpm-hostkeep.conf "$ETC/php-fpm.conf" '' "$(web_group "$(env_get HOSTKEEP_WEBSERVER)")"
  render systemd/hostkeep.service /etc/systemd/system/hostkeep.service
  "/usr/sbin/php-fpm$PHPV" --test --fpm-config "$ETC/php-fpm.conf" >/dev/null 2>&1 || { "/usr/sbin/php-fpm$PHPV" --test --fpm-config "$ETC/php-fpm.conf"; die "configuration PHP-FPM invalide."; }
  systemctl daemon-reload
  systemctl enable -q hostkeep
  systemctl restart hostkeep
  for _ in 1 2 3 4 5 6 7 8 9 10; do [[ -S $SOCK ]] && break; sleep 0.5; done
  [[ -S $SOCK ]] || { journalctl -u hostkeep -n 20 --no-pager; die "hostkeep.service ne démarre pas."; }
  ok "hostkeep.service actif, socket $SOCK"
  migrate_legacy_pool "$PHPV"

  step "fail2ban (jail hostkeep)"
  fail2ban_journal
  install -m 644 "$SRC/system/fail2ban-filter-hostkeep.conf" /etc/fail2ban/filter.d/hostkeep.conf
  install -m 644 "$SRC/system/fail2ban-jail-hostkeep.conf" /etc/fail2ban/jail.d/hostkeep.conf
  if ! fail2ban-client -t >/dev/null 2>&1; then rm -f /etc/fail2ban/jail.d/hostkeep.conf /etc/fail2ban/filter.d/hostkeep.conf; die "config fail2ban invalide : retirée."; fi
  { systemctl is-active -q fail2ban && fail2ban-client reload >/dev/null; } || systemctl restart fail2ban
  ok "5 échecs de connexion → IP bannie 1 h"

  step "MariaDB : journal des requêtes lentes (> 1 s), sans redémarrage"
  install -d -o mysql -g adm -m 2750 /var/log/mysql
  install -m 644 "$SRC/system/mariadb-hostkeep.cnf" /etc/mysql/mariadb.conf.d/90-hostkeep.cnf
  mysql -e "SET GLOBAL slow_query_log_file = '/var/log/mysql/mariadb-slow.log'; SET GLOBAL long_query_time = 1; SET GLOBAL slow_query_log = 1;"

  if systemctl is-active -q nginx 2>/dev/null; then step "Nginx : journal de trafic"; nginx_traffic_setup; commit_changes; fi

  step "Timers de collecte et logrotate"
  rm -f /etc/cron.d/hostkeep   # older versions used cron
  install -m 644 "$SRC"/system/systemd/hostkeep-*.timer "$SRC"/system/systemd/hostkeep-collect@.service /etc/systemd/system/
  install -m 644 "$SRC/system/logrotate-hostkeep" /etc/logrotate.d/hostkeep
  systemctl daemon-reload
  systemctl enable -q --now hostkeep-{minute,5min,hourly,purge,backup,scan}.timer
  ok "collecte chaque minute, 5 min, heure ; sauvegarde et audit chaque nuit"

  step "Première collecte"
  php "$APP/bin/collect.php" all && ok "données collectées"
}
# Hostkeep 1.x ran in a pool of the system PHP-FPM: point every web config that uses its socket
# (vhosts, but also hand-written snippets) to hostkeep.service, then remove the old pool.
# If a config test fails, the files are put back and the old pool is kept.
migrate_legacy_pool() {
  local PHPV=$1 old=/etc/php/$1/fpm/pool.d/hostkeep.conf f files=() bk=/var/lib/hostkeep/pre-2.0-web
  [[ -e $old ]] || return 0
  mapfile -t files < <(grep -rlsE '/run/php/php[0-9.]*-fpm-hostkeep\.sock' /etc/nginx /etc/apache2 /etc/caddy 2>/dev/null || true)
  mkdir -p "$bk"
  for f in "${files[@]}"; do
    cp -a "$f" "$bk/$(echo "$f" | tr / _)"
    sed -i "s#/run/php/php[0-9.]*-fpm-hostkeep\.sock#$SOCK#g" "$f"
    ok "socket mis à jour : $f"
  done
  local failed=0
  if command -v nginx >/dev/null && systemctl is-active -q nginx; then nginx -t >/dev/null 2>&1 && systemctl reload nginx || failed=1; fi
  if command -v apache2ctl >/dev/null && systemctl is-active -q apache2; then apache2ctl configtest >/dev/null 2>&1 && systemctl reload apache2 || failed=1; fi
  if ((failed)); then
    for f in "${files[@]}"; do cp -a "$bk/$(echo "$f" | tr / _)" "$f"; done
    systemctl reload nginx 2>/dev/null || true; systemctl reload apache2 2>/dev/null || true
    warn "test de configuration du serveur web en échec : fichiers remis en place, ancien pool PHP-FPM conservé."
    return 0
  fi
  rm -f "$old"
  systemctl reload "php$PHPV-fpm" 2>/dev/null || true
  ok "ancien pool PHP-FPM de Hostkeep 1.x retiré (copies des fichiers modifiés : $bk)"
}

# ---------- web front ----------
choose_web() {   # sets WEB (and explains the detection)
  detect_web
  local detected=$DETECTED
  [[ -n $WEB && $WEB != auto ]] && { ok "serveur web demandé : $WEB ($DETECTED_WHAT)"; return 0; }
  echo "  Détecté : ${B}$DETECTED_WHAT${N}"
  local def=${detected:-nginx}
  if has_tty; then
    echo "  Comment publier le panel ?"
    echo "    1) nginx   — nouveau server block$([[ $detected == nginx ]] && echo " ${G}(détecté)${N}")$([[ -z $detected ]] && echo ' (installé, recommandé)')"
    echo "    2) apache  — nouveau vhost, active aussi les pages Sites/Trafic$([[ $detected == apache ]] && echo " ${G}(détecté)${N}")"
    echo "    3) caddy   — nouveau site, HTTPS automatique$([[ $detected == caddy ]] && echo " ${G}(détecté)${N}")"
    echo "    4) proxy   — un reverse proxy existant (Traefik, HAProxy, NPM…) relaie vers Hostkeep en HTTP local$([[ $detected == proxy ]] && echo " ${G}(détecté)${N}")"
    echo "    5) none    — ne rien configurer, je branche moi-même mon serveur web sur le socket PHP-FPM"
    local n; n=$(case $def in nginx) echo 1 ;; apache) echo 2 ;; caddy) echo 3 ;; proxy) echo 4 ;; *) echo 5 ;; esac)
    ask n "Choix" "$n"
    case $n in 1|nginx) WEB=nginx ;; 2|apache) WEB=apache ;; 3|caddy) WEB=caddy ;; 4|proxy) WEB=proxy ;; 5|none) WEB=none ;; *) die "choix invalide : $n" ;; esac
  else
    WEB=$def
    ok "serveur web : $WEB (choisi automatiquement)"
  fi
  # Never start a second server on ports already taken by another one.
  local own; own=$(port_owner 443); own=${own:-$(port_owner 80)}
  case $WEB in
    apache) [[ -z $own || $own =~ ^(apache2|httpd)$ ]] || die "les ports 80/443 sont pris par « $own » : choisis plutôt « $detected »." ;;
    nginx) [[ -z $own || $own == nginx ]] || die "les ports 80/443 sont pris par « $own » : choisis plutôt « $detected »." ;;
    caddy) [[ -z $own || $own == caddy ]] || die "les ports 80/443 sont pris par « $own » : choisis plutôt « $detected »." ;;
  esac
}

cmd_web() {
  os_check
  [[ -f $APP/.env ]] || die "application non installée : sudo $SRC/install.sh app"
  step "Publication du panel"
  [[ -n $WEB ]] || WEB=$(env_get HOSTKEEP_WEBSERVER)
  [[ $WEB != auto ]] || WEB=''
  choose_web
  local old_domain; old_domain=$(env_get HOSTKEEP_DOMAIN)
  [[ $old_domain == *example.com ]] && old_domain=''
  DOMAIN=${DOMAIN:-$old_domain}
  if [[ $WEB =~ ^(apache|nginx|caddy)$ ]]; then
    while [[ -z $DOMAIN ]] && has_tty; do ask DOMAIN "Domaine du panel (ex. admin.mondomaine.fr)" '' valid_domain; done
  elif [[ -z $DOMAIN ]]; then
    ask DOMAIN "Domaine public du panel, servi par le proxy (facultatif)" '' valid_domain
  fi
  [[ -n $DOMAIN || $WEB == none || $WEB == proxy ]] || die "indique le domaine : --domain admin.mondomaine.fr"
  EMAIL=${EMAIL:-$(env_get HOSTKEEP_LETSENCRYPT_EMAIL)}
  HTTPS=${HTTPS:-$(env_get HOSTKEEP_HTTPS yes)}
  if [[ $WEB =~ ^(apache|nginx|caddy)$ ]]; then
    [[ -n $DOMAIN ]] && dns_check "$DOMAIN" || true
    if [[ $HTTPS == yes && -z $EMAIL ]] && has_tty; then ask EMAIL "E-mail pour Let's Encrypt (alertes d'expiration, facultatif)" '' valid_email; fi
  fi
  keep "$APP/.env" "$ETC/php-fpm.conf"
  PREV_WEB=$(env_get HOSTKEEP_WEBSERVER)
  trap web_abort EXIT   # any failure from here on: previous files back, both web servers reloaded
  [[ -z $PREV_WEB || $PREV_WEB == "$WEB" ]] || web_unpublish "$PREV_WEB"
  case $WEB in
    apache) web_apache ;;
    nginx) web_nginx ;;
    caddy) web_caddy ;;
    proxy) web_proxy ;;
    none) web_none ;;
  esac
  env_set HOSTKEEP_WEBSERVER "$WEB"
  [[ -z $DOMAIN ]] || env_set HOSTKEEP_DOMAIN "$DOMAIN"
  [[ -z $EMAIL ]] || env_set HOSTKEEP_LETSENCRYPT_EMAIL "$EMAIL"
  env_set HOSTKEEP_HTTPS "$HTTPS"
  # Socket group follows the web server (caddy runs as "caddy", the others as "www-data").
  render php-fpm-hostkeep.conf "$ETC/php-fpm.conf" '' "$(web_group "$WEB")"
  systemctl restart hostkeep   # a new socket group needs a restart, not a reload
  commit_changes
  trap - EXIT
  [[ $WEB == none || $WEB == proxy ]] || ok "Panel en ligne : ${B}$(panel_url)${N}"
}
# Removes the panel from the web front used before (when switching mode). Saved first: restored on failure.
web_unpublish() {
  echo "  Retrait de la configuration précédente ($1)…"
  case $1 in
    apache)
      keep /etc/apache2/sites-enabled/hostkeep.conf /etc/apache2/sites-enabled/hostkeep-le-ssl.conf
      a2dissite -q hostkeep hostkeep-le-ssl 2>/dev/null || true
      { apache2ctl configtest && systemctl reload apache2; } >/dev/null 2>&1 || true ;;
    nginx)
      keep /etc/nginx/sites-enabled/hostkeep.conf /etc/nginx/conf.d/hostkeep.conf
      rm -f /etc/nginx/sites-enabled/hostkeep.conf /etc/nginx/conf.d/hostkeep.conf
      { nginx -t && systemctl reload nginx; } >/dev/null 2>&1 || true ;;
    caddy)
      keep /etc/caddy/Caddyfile /etc/caddy/hostkeep.caddy
      sed -i '/^# Hostkeep panel$/d; \#^import /etc/caddy/hostkeep.caddy$#d' /etc/caddy/Caddyfile; rm -f /etc/caddy/hostkeep.caddy
      systemctl reload caddy >/dev/null 2>&1 || true ;;
    proxy)
      keep /etc/systemd/system/multi-user.target.wants/hostkeep-http.service
      systemctl disable -q --now hostkeep-http 2>/dev/null || true ;;
  esac
}
panel_url() { local d; d=$(env_get HOSTKEEP_DOMAIN); [[ -n $d ]] && echo "$([[ $(env_get HOSTKEEP_HTTPS yes) == no ]] && echo http || echo https)://$d" || echo "http://$(env_get HOSTKEEP_HTTP_LISTEN 127.0.0.1:8090)"; }
web_fail() { die "$*"; }   # web_abort (EXIT trap) restores the previous state
PREV_WEB=''
web_abort() {
  local rc=$?
  ((rc)) || return 0
  rollback
  systemctl daemon-reload
  local w
  for w in "$WEB" "$PREV_WEB"; do [[ -n $w ]] && reload_web quiet "$w"; done
  systemctl restart hostkeep 2>/dev/null || true
  exit "$rc"
}
reload_web() {   # [quiet] [mode]
  case ${2:-$WEB} in
    apache) apache2ctl configtest >/dev/null 2>&1 && systemctl reload-or-restart apache2 ;;
    nginx) nginx -t >/dev/null 2>&1 && systemctl reload-or-restart nginx ;;
    caddy) systemctl reload-or-restart caddy ;;
    proxy) if systemctl is-enabled -q hostkeep-http 2>/dev/null; then systemctl reload-or-restart hostkeep-http; fi ;;
  esac >/dev/null 2>&1 || [[ ${1:-} == quiet ]]
}
# Installs a package whose service must not grab ports 80/443 by itself.
apt_install_quiet() { apt-get install -y -q "$@" </dev/null >/dev/null; }

# Let's Encrypt through the panel's webroot. Returns 1 (without dying) when the certificate cannot be obtained.
certificate() {
  local svc=$1
  [[ -s /etc/letsencrypt/live/$DOMAIN/fullchain.pem ]] && { ok "certificat Let's Encrypt présent pour $DOMAIN"; return 0; }
  install -d -m 755 "$APP/public/.well-known/acme-challenge"
  echo "  Demande du certificat Let's Encrypt pour $DOMAIN…"
  if certbot certonly --webroot -w "$APP/public" -d "$DOMAIN" --cert-name "$DOMAIN" --non-interactive --agree-tos --keep-until-expiring \
      ${EMAIL:+--email "$EMAIL"} ${EMAIL:---register-unsafely-without-email} --deploy-hook "systemctl reload $svc" </dev/null \
      && [[ -s /etc/letsencrypt/live/$DOMAIN/fullchain.pem ]]; then
    ok "certificat obtenu (renouvellement automatique par certbot.timer)"; return 0
  fi
  warn "pas de certificat pour $DOMAIN : vérifie que le DNS pointe vers ce serveur et que le port 80 est ouvert."
  return 1
}
https_or_http() {   # after a failed certificate: continue in HTTP (asked) or roll back
  if confirm "Publier quand même en HTTP seul (non chiffré, à éviter) en attendant ? Relance ensuite « sudo hostkeep web »." n; then
    HTTPS=no; return 0
  fi
  web_fail "certificat impossible : configuration précédente remise en place. Corrige le DNS puis relance : sudo hostkeep web"
}

web_apache() {
  step "Apache"
  if ! command -v apache2 >/dev/null; then echo "  Installation d'Apache…"; apt_install_quiet apache2; fi
  systemctl enable -q --now apache2
  a2enmod -q ssl headers proxy_fcgi alias >/dev/null
  local other
  other=$(apache2ctl -S 2>/dev/null | grep -E "(namevhost|alias) $DOMAIN( |$)" | grep -v '/hostkeep' | head -n1 || true)
  [[ -z $other ]] || web_fail "le domaine $DOMAIN est déjà servi par un autre vhost :$(echo "$other" | sed "s/^ *port/ port/")"
  local avail=/etc/apache2/sites-available
  keep "$avail/hostkeep.conf" "$avail/hostkeep-le-ssl.conf" /etc/apache2/sites-enabled/hostkeep.conf /etc/apache2/sites-enabled/hostkeep-le-ssl.conf \
       "$ETC/apache-app.conf" /etc/apache2/conf-available/hostkeep-traffic.conf /etc/apache2/conf-enabled/hostkeep-traffic.conf
  cat > "$ETC/apache-app.conf" <<'EOF'
# Hostkeep: application part of the vhosts (generated by install.sh, rerun « sudo hostkeep web » after editing).
DocumentRoot /var/www/hostkeep/public
<Directory /var/www/hostkeep/public>
    Options -Indexes -FollowSymLinks
    AllowOverride None
    Require all granted
    DirectoryIndex index.php
</Directory>
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/hostkeep/php-fpm.sock|fcgi://hostkeep"
</FilesMatch>
<LocationMatch "^/assets/">
    Header set Cache-Control "no-cache"
</LocationMatch>
<LocationMatch "/\.(?!well-known/)">
    Require all denied
</LocationMatch>
ErrorLog ${APACHE_LOG_DIR}/hostkeep-error.log
CustomLog ${APACHE_LOG_DIR}/hostkeep-access.log combined
EOF
  apache_http_vhost "$([[ $HTTPS == yes ]] && echo redirect || echo app)"
  install -m 644 "$SRC/system/apache-hostkeep-traffic.conf" /etc/apache2/conf-available/hostkeep-traffic.conf
  a2ensite -q hostkeep >/dev/null; a2enconf -q hostkeep-traffic >/dev/null
  apache2ctl configtest >/dev/null 2>&1 || { apache2ctl configtest || true; web_fail "configtest Apache en échec : rien n'a été rechargé."; }
  systemctl reload apache2
  if [[ $HTTPS == yes ]] && ! certificate apache2; then
    https_or_http; apache_http_vhost app
    apache2ctl configtest >/dev/null 2>&1 || web_fail "configtest Apache en échec."
    systemctl reload apache2
  fi
  if [[ $HTTPS == yes ]]; then
    cat > "$avail/hostkeep-le-ssl.conf" <<EOF
# Hostkeep, HTTPS part (generated by install.sh).
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName $DOMAIN
    Include $ETC/apache-app.conf
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN/privkey.pem
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder off
    SSLSessionTickets off
</VirtualHost>
</IfModule>
EOF
    a2ensite -q hostkeep-le-ssl >/dev/null
  else
    a2dissite -q hostkeep-le-ssl 2>/dev/null || true
  fi
  apache_hardening
  apache2ctl configtest >/dev/null 2>&1 || { apache2ctl configtest || true; web_fail "configtest Apache en échec : configuration précédente remise en place."; }
  systemctl reload apache2
  ok "vhost hostkeep actif ($([[ $HTTPS == yes ]] && echo 'HTTPS + redirection' || echo 'HTTP seul')), autres vhosts inchangés"
}
apache_http_vhost() {   # $1 = redirect | app
  {
    echo "# Hostkeep, HTTP part (generated by install.sh)."
    echo "<VirtualHost *:80>"
    echo "    ServerName $DOMAIN"
    if [[ $1 == redirect ]]; then
      echo "    DocumentRoot /var/www/hostkeep/public"
      echo "    <Directory /var/www/hostkeep/public/.well-known/acme-challenge>"
      echo "        Require all granted"
      echo "    </Directory>"
      echo "    RedirectMatch 301 ^/(?!\.well-known/acme-challenge/)(.*)\$ https://$DOMAIN/\$1"
    else
      echo "    Include $ETC/apache-app.conf"
    fi
    echo "</VirtualHost>"
  } > /etc/apache2/sites-available/hostkeep.conf
}
# Server-wide rules (deny .env, .git, dumps, phpinfo, listings) apply to EVERY site: opt-in only.
apache_hardening() {
  [[ -e /etc/apache2/conf-enabled/hardening.conf ]] && { ok "protection Apache globale (hardening.conf) déjà active"; return 0; }
  if ((HARDEN)) || confirm "Activer aussi la protection Apache globale (bloque .env, .git, dumps SQL, phpinfo… sur TOUS les sites) ?" n; then
    keep /etc/apache2/conf-available/hardening.conf /etc/apache2/conf-enabled/hardening.conf
    install -m 644 "$SRC/system/apache-hardening.conf" /etc/apache2/conf-available/hardening.conf
    a2enconf -q hardening >/dev/null && ok "protection globale activée (désactivable : a2disconf hardening)"
  fi
}

nginx_app_conf() {   # $1 = file, $2 = extra fastcgi line
  cat > "$1" <<EOF
# Hostkeep: application part of the server block (generated by install.sh, rerun « sudo hostkeep web » after editing).
root /var/www/hostkeep/public;
index index.php;
client_max_body_size 1m;
server_tokens off;

location ~ /\.(?!well-known/) { deny all; }
location / { try_files \$uri \$uri/ =404; }
location ^~ /assets/ { add_header Cache-Control "no-cache"; }
location ~ \.php\$ {
    try_files \$uri =404;
    include /etc/nginx/fastcgi_params;
    fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    ${2:-}
    fastcgi_pass unix:$SOCK;
}
EOF
  chmod 644 "$1"
}
# Traffic statistics on Nginx: Apache has a GlobalLog for every vhost, Nginx does not. A format and an http-level
# access_log (used by the sites that do not set their own) + a snippet to include in the others.
nginx_traffic_setup() {
  command -v nginx >/dev/null && systemctl is-active -q nginx || return 0
  local conf=/etc/nginx/conf.d/hostkeep-traffic.conf snip=/etc/nginx/snippets/hostkeep-traffic.conf
  [[ -d /etc/nginx/conf.d ]] || return 0
  keep "$conf" "$snip"
  # Own format name: another file may already define "hostkeep_traffic" (and conf.d loads in alphabetical order).
  {
    echo "# Hostkeep: per-site traffic log (generated by install.sh). Sites with their own access_log can add:"
    echo "#   include snippets/hostkeep-traffic.conf;"
    echo "log_format hostkeep_vhost '\$host \$remote_addr [\$time_local] \"\$request\" \$status \$body_bytes_sent \"\$http_referer\" \"\$http_user_agent\"';"
    echo "access_log /var/log/nginx/hostkeep-traffic.log hostkeep_vhost;"
  } > "$conf"
  chmod 644 "$conf"
  install -d /etc/nginx/snippets
  printf '%s\n' "# Hostkeep: add « include snippets/hostkeep-traffic.conf; » in a server block to count its visits." \
    "access_log /var/log/nginx/hostkeep-traffic.log hostkeep_vhost;" > "$snip"
  chmod 644 "$snip"
  if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx
    ok "statistiques de trafic Nginx : /var/log/nginx/hostkeep-traffic.log"
  else
    rm -f "$conf" "$snip"
    for f in "$conf" "$snip"; do [[ -e $ROLLBACK_DIR$f ]] && cp -a "$ROLLBACK_DIR$f" "$f"; done
    warn "journal de trafic Nginx non activé (nginx -t en échec) : configuration remise en place."
  fi
}

web_nginx() {
  step "Nginx"
  if ! command -v nginx >/dev/null; then echo "  Installation de Nginx…"; apt_install_quiet nginx; fi
  systemctl enable -q --now nginx
  local site link
  if [[ -d /etc/nginx/sites-enabled ]] && grep -rqs 'sites-enabled' /etc/nginx/nginx.conf; then
    site=/etc/nginx/sites-available/hostkeep.conf; link=/etc/nginx/sites-enabled/hostkeep.conf
  else
    site=/etc/nginx/conf.d/hostkeep.conf; link=''
  fi
  local other
  other=$(nginx -T 2>/dev/null | awk -v d="$DOMAIN" '/^# configuration file /{f=$4; sub(/:$/,"",f)} /server_name/{for(i=2;i<=NF;i++){n=$i; sub(/;$/,"",n); if(n==d) print f}}' | grep -v hostkeep | head -n1 || true)
  [[ -z $other ]] || web_fail "le domaine $DOMAIN est déjà servi par un autre server block : $other"
  keep "$site" ${link:+"$link"} "$ETC/nginx-app.conf"
  nginx_app_conf "$ETC/nginx-app.conf" ''
  nginx_site "$site" "$([[ $HTTPS == yes ]] && echo redirect || echo app)"
  [[ -z $link ]] || ln -sf "$site" "$link"
  nginx -t >/dev/null 2>&1 || { nginx -t || true; web_fail "nginx -t en échec : rien n'a été rechargé."; }
  systemctl reload nginx
  if [[ $HTTPS == yes ]] && ! certificate nginx; then https_or_http; nginx_site "$site" app; nginx -t >/dev/null 2>&1 || web_fail "nginx -t en échec."; systemctl reload nginx; fi
  [[ $HTTPS == yes ]] && nginx_site "$site" https
  nginx -t >/dev/null 2>&1 || { nginx -t || true; web_fail "nginx -t en échec : configuration précédente remise en place."; }
  systemctl reload nginx
  nginx_traffic_setup
  ok "server block hostkeep actif ($([[ $HTTPS == yes ]] && echo 'HTTPS + redirection' || echo 'HTTP seul')), autres sites inchangés"
}
nginx_site() {   # $1 file, $2 = redirect (ACME + redirect only) | app (HTTP) | https (redirect + 443)
  local http2='http2 on;' l443='listen 443 ssl;' l443b='listen [::]:443 ssl;' v
  # Nginx < 1.25.1 (Debian 12, Ubuntu 22.04/24.04) has no "http2 on;"
  v=$(nginx -v 2>&1 | sed -n 's#.*nginx/\([0-9.]*\).*#\1#p')
  if [[ -n $v ]] && dpkg --compare-versions "$v" lt 1.25.1; then http2=''; l443='listen 443 ssl http2;'; l443b='listen [::]:443 ssl http2;'; fi
  {
    echo "# Hostkeep (generated by install.sh, rerun « sudo hostkeep web » to regenerate)."
    echo "server {"
    echo "    listen 80;"
    echo "    listen [::]:80;"
    echo "    server_name $DOMAIN;"
    if [[ $2 == app ]]; then
      echo "    access_log /var/log/nginx/hostkeep-access.log;"
      echo "    error_log /var/log/nginx/hostkeep-error.log;"
      echo "    include $ETC/nginx-app.conf;"
    else
      echo "    location ^~ /.well-known/acme-challenge/ { root /var/www/hostkeep/public; default_type text/plain; }"
      echo "    location / { return 301 https://\$host\$request_uri; }"
    fi
    echo "}"
    if [[ $2 == https ]]; then
      cat <<EOF

server {
    $l443
    $l443b
    $http2
    server_name $DOMAIN;
    ssl_certificate     /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:hostkeep:10m;
    ssl_session_tickets off;
    access_log /var/log/nginx/hostkeep-access.log;
    error_log /var/log/nginx/hostkeep-error.log;
    include $ETC/nginx-app.conf;
}
EOF
    fi
  } > "$1"
  chmod 644 "$1"
}

web_caddy() {
  step "Caddy"
  if ! command -v caddy >/dev/null; then
    if apt-cache policy caddy 2>/dev/null | grep -q 'Candidate: [0-9]'; then
      echo "  Installation de Caddy…"; apt_install_quiet caddy
    else
      die "Caddy n'est pas dans les dépôts de ce système : installe-le (https://caddyserver.com/docs/install#debian-ubuntu-raspbian) puis relance, ou choisis nginx."
    fi
  fi
  systemctl enable -q --now caddy
  local cf=/etc/caddy/Caddyfile site=/etc/caddy/hostkeep.caddy
  [[ -f $cf ]] || die "$cf introuvable."
  if grep -rlw --exclude=hostkeep.caddy -- "$DOMAIN" /etc/caddy 2>/dev/null | head -n1 | grep -q .; then
    die "le domaine $DOMAIN apparaît déjà dans la configuration de Caddy ($(grep -rlw --exclude=hostkeep.caddy -- "$DOMAIN" /etc/caddy | head -n1))."
  fi
  keep "$cf" "$site"
  {
    echo "# Hostkeep (generated by install.sh, rerun « sudo hostkeep web » to regenerate). HTTPS is automatic."
    echo "$([[ $HTTPS == no ]] && echo "http://")$DOMAIN {"
    [[ $HTTPS == yes && -n $EMAIL ]] && echo "    tls $EMAIL"
    echo "    root * /var/www/hostkeep/public"
    echo "    @hidden {"
    echo "        path */.*"
    echo "        not path /.well-known/*"
    echo "    }"
    echo "    respond @hidden 404"
    echo "    header /assets/* Cache-Control \"no-cache\""
    echo "    header -Server"
    echo "    encode gzip"
    echo "    php_fastcgi unix/$SOCK"
    echo "    file_server"
    echo "}"
  } > "$site"
  chmod 644 "$site"
  grep -qxF "import $site" "$cf" || printf '\n# Hostkeep panel\nimport %s\n' "$site" >> "$cf"
  caddy validate --config "$cf" --adapter caddyfile >/dev/null 2>&1 || { caddy validate --config "$cf" --adapter caddyfile 2>&1 | tail -5; web_fail "caddy validate en échec : configuration précédente remise en place."; }
  # The socket group must be "caddy" before Caddy talks to it.
  render php-fpm-hostkeep.conf "$ETC/php-fpm.conf" '' caddy
  systemctl reload-or-restart hostkeep
  systemctl reload caddy || web_fail "rechargement de Caddy impossible."
  ok "site Caddy hostkeep actif, autres sites inchangés"
}

web_proxy() {
  step "Derrière un reverse proxy"
  local docker_gw; docker_gw=$(ip -4 -o addr show docker0 2>/dev/null | awk '{print $4}' | cut -d/ -f1 || true)
  local def=127.0.0.1:8090
  if docker_traefik && [[ -n $docker_gw ]]; then def="$docker_gw:8090"; echo "  Traefik tourne dans Docker : le panel écoutera sur la passerelle docker0 ($docker_gw), joignable depuis les conteneurs."; fi
  LISTEN=${LISTEN:-$(env_get HOSTKEEP_HTTP_LISTEN "$def")}
  has_tty && ask LISTEN "Adresse HTTP locale du panel (le proxy y envoie les requêtes)" "$LISTEN" valid_listen
  local port=${LISTEN##*:} own
  own=$(port_owner "$port")
  [[ -z $own || $own == nginx ]] || die "le port $port est déjà utilisé par « $own » : choisis une autre adresse avec --listen."
  if ! command -v nginx >/dev/null; then
    echo "  Installation de Nginx (instance dédiée, sans toucher aux ports 80/443)…"
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; chmod 755 /usr/sbin/policy-rc.d
    apt_install_quiet nginx || { rm -f /usr/sbin/policy-rc.d; die "installation de nginx impossible."; }
    rm -f /usr/sbin/policy-rc.d
    systemctl disable -q --now nginx 2>/dev/null || true   # the system instance stays off: only hostkeep-http runs
  fi
  keep "$ETC/nginx-http.conf" "$ETC/nginx-app.conf" /etc/systemd/system/hostkeep-http.service
  nginx_app_conf "$ETC/nginx-app.conf" 'fastcgi_param HTTPS $hk_https if_not_empty;'
  cat > "$ETC/nginx-http.conf" <<EOF
# Hostkeep behind a reverse proxy: dedicated Nginx instance (hostkeep-http.service), generated by install.sh.
user www-data;
worker_processes 1;
pid /run/hostkeep-http.pid;
error_log /var/log/hostkeep/http-error.log warn;
events { worker_connections 256; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    sendfile on;
    access_log /var/log/hostkeep/http-access.log;
    # The real client address comes from the proxy (trusted: loopback and private networks only).
    set_real_ip_from 127.0.0.0/8;
    set_real_ip_from ::1;
    set_real_ip_from 10.0.0.0/8;
    set_real_ip_from 172.16.0.0/12;
    set_real_ip_from 192.168.0.0/16;
    real_ip_header X-Forwarded-For;
    real_ip_recursive on;
    map \$http_x_forwarded_proto \$hk_https { default ""; https on; }
    server {
        listen $LISTEN;
        server_name _;
        include $ETC/nginx-app.conf;
    }
}
EOF
  install -m 644 "$SRC/system/systemd/hostkeep-http.service" /etc/systemd/system/hostkeep-http.service
  nginx -t -q -c "$ETC/nginx-http.conf" 2>/dev/null || { nginx -t -c "$ETC/nginx-http.conf" || true; web_fail "configuration nginx-http invalide."; }
  systemctl daemon-reload
  systemctl enable -q hostkeep-http
  systemctl restart hostkeep-http || web_fail "hostkeep-http ne démarre pas (journalctl -u hostkeep-http)."
  env_set HOSTKEEP_HTTP_LISTEN "$LISTEN"
  [[ -n $HTTPS ]] || HTTPS=yes
  ok "panel servi en HTTP sur ${B}http://$LISTEN${N} (hostkeep-http.service)"
  proxy_snippets
}
proxy_snippets() {
  local f=$ETC/proxy-snippets.txt host=${DOMAIN:-admin.example.com} target="http://$LISTEN"
  [[ ${LISTEN%:*} != 0.0.0.0 ]] || target="http://$(public_ips | head -n1):${LISTEN##*:}"
  local tdir='' resolver='' c
  for c in /etc/traefik/traefik.yml /etc/traefik/traefik.yaml; do
    [[ -f $c ]] || continue
    tdir=$(awk '/^[[:space:]]*file:/{f=1} f && /directory:/{print $2; exit}' "$c" | tr -d "\"'")
    resolver=$(awk '/^certificatesResolvers:/{r=1; next} r && /^[[:space:]]+[A-Za-z0-9_-]+:[[:space:]]*$/{gsub(/[ :]/,""); print; exit}' "$c")
  done
  {
    echo "Hostkeep écoute en HTTP sur $LISTEN. Le reverse proxy doit transmettre Host et X-Forwarded-For / X-Forwarded-Proto."
    echo
    echo "== Traefik (fournisseur « file ») : fichier dynamique =="
    echo "http:"
    echo "  routers:"
    echo "    hostkeep:"
    echo "      rule: \"Host(\`$host\`)\""
    echo "      service: hostkeep"
    if [[ -n $resolver ]]; then echo "      tls:"; echo "        certResolver: $resolver"; else echo "      tls: {}"; fi
    echo "  services:"
    echo "    hostkeep:"
    echo "      loadBalancer:"
    echo "        servers:"
    echo "          - url: \"$target\""
    echo
    echo "== HAProxy =="
    echo "backend hostkeep"
    echo "    http-request set-header X-Forwarded-Proto https if { ssl_fc }"
    echo "    server hostkeep ${LISTEN} check"
    echo "# et dans le frontend : use_backend hostkeep if { hdr(host) -i $host }"
    echo
    echo "== Nginx Proxy Manager / autre =="
    echo "Proxy Host « $host » → schéma http, hôte ${LISTEN%:*}, port ${LISTEN##*:}, SSL activé (Let's Encrypt)."
  } > "$f"
  if [[ -n $tdir && -d $tdir ]] && [[ -n $DOMAIN ]]; then
    if confirm "Traefik lit ses routes dans $tdir : y créer hostkeep.yml (route $DOMAIN → $target) ?" y; then
      keep "$tdir/hostkeep.yml"
      sed -n '/^http:/,/^$/p' "$f" | sed '/^$/d' > "$tdir/hostkeep.yml"
      ok "route Traefik écrite : $tdir/hostkeep.yml (rechargée automatiquement par Traefik)"
      return 0
    fi
  fi
  echo
  echo "  ${B}À faire dans ton reverse proxy${N} (exemples aussi dans $f) :"
  sed 's/^/    /' "$f" | head -n 16
}
web_none() {
  step "Aucun serveur web configuré"
  nginx_app_conf "$ETC/nginx-app.conf" ''
  echo "  Le panel tourne (hostkeep.service) et attend des requêtes FastCGI sur ${B}$SOCK${N}."
  echo "  Racine web : /var/www/hostkeep/public. Exemple prêt à inclure dans un server block Nginx : $ETC/nginx-app.conf"
  echo "  Apache : SetHandler \"proxy:unix:$SOCK|fcgi://hostkeep\" pour les fichiers .php (voir le README)."
  [[ -n $HTTPS ]] || HTTPS=yes
}

# ---------- admin ----------
cmd_admin() {
  os_check
  [[ -f $APP/bin/user.php ]] || die "application non installée."
  local n; n=$(php "$APP/bin/user.php" count)
  if ((n > 0)); then ok "$n Super Admin actif(s). Gestion des comptes : Administration › Utilisateurs, ou sudo hostkeep users"; return 0; fi
  step "Premier compte Super Admin"
  local u=${ADMIN_USER:-} e=${ADMIN_EMAIL:-}
  if has_tty; then
    [[ -n $u ]] || ask u "Identifiant" admin valid_username
    [[ -n $e ]] || ask e "E-mail (mot de passe oublié, alertes ; facultatif)" "${EMAIL:-}" valid_email
    if confirm "Choisir le mot de passe maintenant ? (sinon un mot de passe temporaire est généré)" y; then
      php "$APP/bin/user.php" setup --username="$u" ${e:+--email="$e"}
      return 0
    fi
  fi
  ADMIN_OUTPUT=$(php "$APP/bin/user.php" setup --username="${u:-admin}" ${e:+--email="$e"} --generate)
  echo "$ADMIN_OUTPUT" | sed 's/^/  /'
}

# ---------- status ----------
cmd_status() {
  os_check
  local d w
  d=$(env_get HOSTKEEP_DOMAIN '-'); w=$(env_get HOSTKEEP_WEBSERVER '-')
  echo "${B}Hostkeep $(cat "$APP/VERSION" 2>/dev/null || echo '(non installé)')${N}"
  detect_web
  row "Adresse" 22 "$(panel_url)"; row "Serveur web" 22 "$w"; row "Détecté sur 80/443" 22 "$DETECTED_WHAT"
  row "Dépôt source" 22 "$(head -n1 /var/lib/hostkeep/source 2>/dev/null || echo '-')"; row "PHP" 22 "$(php_version)"
  local s
  for s in hostkeep hostkeep-http mariadb fail2ban apache2 nginx caddy; do
    svc_installed "$s" || continue
    row "$s" 22 "$(systemctl is-active "$s" 2>/dev/null || true)"
  done
  row "Timers actifs" 22 "$(systemctl list-timers 'hostkeep-*' --no-legend 2>/dev/null | wc -l)/6"
  if [[ -f $APP/bin/user.php ]]; then row "Comptes" 22 "$(php "$APP/bin/user.php" list 2>/dev/null | tail -n +2 | wc -l) (Super Admins actifs : $(php "$APP/bin/user.php" count 2>/dev/null))"; fi
  [[ $d == - ]] || dns_check "$d" || true
}

# ---------- full install ----------
# What the server offers, shown before anything changes (warnings only: the install decides nothing from it).
preflight() {
  local free mem p80 p443
  free=$(df -Pk / | awk 'NR==2 {print int($4/1024)}')
  mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
  p80=$(port_owner 80); p443=$(port_owner 443)
  echo
  echo "  ${B}Vérifications${N}"
  row "  Espace libre (/)" 16 "$((free / 1024)) Go$( ((free < 2048)) && echo " ${Y}— peu : prévois de la place pour les sauvegardes${N}")"
  row "  Mémoire" 16 "$mem Mo$( ((mem < 900)) && echo " ${Y}— juste : 1 Go conseillé${N}")"
  row "  Port 80 / 443" 16 "${p80:-libre} / ${p443:-libre}"
  if [[ -n $DOMAIN ]] && command -v getent >/dev/null; then
    local ips; ips=$(getent ahosts "$DOMAIN" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ')
    row "  DNS $DOMAIN" 16 "${ips:-${Y}ne résout pas encore${N}}"
  fi
}
cmd_install() {
  os_check
  echo
  echo "${B}  Hostkeep — installation${N}  ($(. /etc/os-release; echo "$PRETTY_NAME"), $(hostname))"
  echo "  Panel d'administration de serveur : surveillance, actions, comptes et rôles."
  [[ -f $APP/.env ]] && echo "  Installation existante trouvée : mise à jour, configuration et comptes conservés."
  step "Configuration"
  detect_web
  local detected=$DETECTED
  [[ -n $WEB ]] || WEB=$(env_get HOSTKEEP_WEBSERVER)
  [[ -n $DOMAIN ]] || DOMAIN=$(env_get HOSTKEEP_DOMAIN); [[ $DOMAIN == *example.com ]] && DOMAIN=''
  if [[ -z $DOMAIN ]] && has_tty; then
    echo "  Le panel a besoin d'un (sous-)domaine dont l'enregistrement DNS A/AAAA pointe vers ce serveur."
    ask DOMAIN "Domaine du panel (vide = configurer plus tard)" '' valid_domain
  fi
  [[ -n $EMAIL ]] || EMAIL=$(env_get HOSTKEEP_LETSENCRYPT_EMAIL)
  if [[ -z $HTTPS ]]; then HTTPS=$(env_get HOSTKEEP_HTTPS yes); fi
  echo
  echo "  ${B}Récapitulatif${N}"
  row "  Domaine" 16 "${DOMAIN:-(plus tard)}"; row "  Serveur web" 16 "${WEB:-auto → ${detected:-nginx} ($DETECTED_WHAT)}"
  row "  HTTPS" 16 "$HTTPS"; row "  Dossier" 16 "$APP"; row "  Source" 16 "$SRC"
  preflight
  confirm "Lancer l'installation ?" y || die "annulé."

  cmd_deps
  cmd_app
  if [[ -n $DOMAIN || $WEB == proxy || $WEB == none ]]; then
    cmd_web
  else
    warn "pas de domaine : serveur web non configuré. Plus tard : sudo hostkeep web --domain admin.mondomaine.fr"
  fi
  ADMIN_OUTPUT=''
  cmd_admin
  echo
  echo "${G}${B}  Hostkeep est installé.${N}"
  row "Adresse" 14 "$(panel_url)"; row "Service" 14 "systemctl status hostkeep"; row "Commande" 14 "sudo hostkeep help"
  if [[ -n $ADMIN_OUTPUT ]]; then echo "  ${Y}Note le mot de passe temporaire ci-dessus : il ne sera plus affiché.${N}"; fi
  if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q 'Status: active' && [[ $WEB =~ ^(apache|nginx|caddy)$ ]]; then
    ufw status | grep -qE '^(80|443|80,443)/tcp|Nginx|Apache|WWW' || warn "ufw est actif : ouvre les ports web si besoin (sudo ufw allow 80,443/tcp)."
  fi
}

# ---------- uninstall ----------
cmd_uninstall() {
  os_check
  local PHPV; PHPV=$(php_version)
  step "Désactivation du panel"
  systemctl disable --now hostkeep-{minute,5min,hourly,purge,backup,scan}.timer hostkeep-http hostkeep 2>/dev/null || true
  rm -rf /etc/systemd/system/hostkeep-* /etc/systemd/system/hostkeep.service && systemctl daemon-reload
  if command -v a2dissite >/dev/null; then
    a2dissite -q hostkeep hostkeep-le-ssl 2>/dev/null || true; a2disconf -q hostkeep-traffic 2>/dev/null || true
    rm -f /etc/apache2/sites-available/hostkeep*.conf /etc/apache2/conf-available/hostkeep-traffic.conf
    { apache2ctl configtest && systemctl reload apache2; } >/dev/null 2>&1 || true
  fi
  if [[ -e /etc/nginx/sites-available/hostkeep.conf || -e /etc/nginx/conf.d/hostkeep.conf ]]; then
    rm -f /etc/nginx/sites-enabled/hostkeep.conf /etc/nginx/sites-available/hostkeep.conf /etc/nginx/conf.d/hostkeep.conf
    { nginx -t && systemctl reload nginx; } >/dev/null 2>&1 || true
  fi
  if [[ -e /etc/caddy/hostkeep.caddy ]]; then
    sed -i '/^# Hostkeep panel$/d; \#^import /etc/caddy/hostkeep.caddy$#d' /etc/caddy/Caddyfile
    rm -f /etc/caddy/hostkeep.caddy; systemctl reload caddy 2>/dev/null || true
  fi
  rm -f /etc/sudoers.d/hostkeep /usr/local/sbin/hostkeep-action /usr/local/bin/hostkeep "/etc/php/$PHPV/fpm/pool.d/hostkeep.conf" \
        /etc/fail2ban/jail.d/hostkeep.conf /etc/fail2ban/filter.d/hostkeep.conf /etc/logrotate.d/hostkeep \
        /etc/mysql/mariadb.conf.d/90-hostkeep.cnf /etc/cron.d/hostkeep
  rm -rf "$ETC"
  # /etc/fail2ban/jail.d/00-hostkeep-journal.conf is kept: it fixes the system's own sshd jail.
  fail2ban-client reload >/dev/null 2>&1 || true
  [[ -e /etc/apache2/conf-enabled/hardening.conf ]] && echo "  La protection Apache globale (hardening.conf) est conservée : a2disconf hardening pour la retirer."
  if ((PURGE)); then
    local okp=''
    if has_tty; then read -r -p "  Supprimer aussi la base « hostkeep » (comptes compris), les sauvegardes, les logs et $APP ? Tape OUI : " okp </dev/tty || true; else okp=OUI; fi
    [[ $okp == OUI ]] || die "annulé."
    mysql -e "DROP DATABASE IF EXISTS hostkeep; DROP USER IF EXISTS 'hostkeep'@'localhost';"
    rm -rf "$APP" /var/lib/hostkeep /var/log/hostkeep /var/backups/hostkeep
    userdel hostkeep 2>/dev/null || true
    ok "Hostkeep entièrement supprimé (certificat Let's Encrypt conservé : certbot delete --cert-name <domaine>)."
  else
    ok "Panel désinstallé. Base, comptes, sauvegardes et $APP conservés (--purge pour tout supprimer)."
  fi
}

usage() { sed -n '2,32p' "$SRC/install.sh" 2>/dev/null | sed 's/^# \{0,1\}//'; }

main() {
  parse_args "$@"
  local self=${BASH_SOURCE[0]:-}
  if [[ -z $self || ! -f $self || ! -d $(dirname "$self")/app ]]; then
    [[ $CMD == help ]] && { echo "Usage : curl -fsSL https://raw.githubusercontent.com/ydeloustal/HostKeep/main/install.sh | sudo bash [-s -- options]"; exit 0; }
    bootstrap
  fi
  SRC=$(cd "$(dirname "$self")" && pwd)
  case $CMD in
    help) usage ;;
    install) cmd_install ;;
    deps) cmd_deps ;;
    app) cmd_app ;;
    web) cmd_web ;;
    admin) cmd_admin ;;
    status) cmd_status ;;
    uninstall) cmd_uninstall ;;
  esac
}

# Everything above only defines functions: with « curl | bash », nothing runs before the whole script is read.
main "$@"
