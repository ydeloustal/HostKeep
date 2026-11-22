#!/bin/bash
# Real installation + attack tests in a disposable systemd container (Docker, privileged: it runs systemd).
#
#   bash tests/integration/run.sh [image] [web mode]
#   bash tests/integration/run.sh jrei/systemd-debian:12 nginx       (default)
#   bash tests/integration/run.sh jrei/systemd-ubuntu:22.04 apache
#   bash tests/integration/run.sh jrei/systemd-debian:13 caddy
#
# Installs this working copy with install.sh, creates three test accounts (Lecteur, Administrateur, Super Admin)
# with a random password that is never shown, makes a backup, then runs tests/integration/attack.sh inside.
# The container is removed at the end (KEEP=1 to keep it for inspection).
set -euo pipefail
img=${1:-jrei/systemd-debian:12}
web=${2:-nginx}
[[ $web =~ ^(nginx|apache|caddy)$ ]] || { echo "mode web : nginx, apache ou caddy" >&2; exit 2; }
root=$(cd "$(dirname "$0")/../.." && pwd)
c="hostkeep-it-$web-$$"
cleanup() { [[ ${KEEP:-0} == 1 ]] && echo "Conteneur gardé : $c" || docker rm -f "$c" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "==> Conteneur $img ($c)"
docker run -d --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --name "$c" --hostname "$c" "$img" >/dev/null
for _ in $(seq 60); do docker exec "$c" systemctl is-system-running 2>/dev/null | grep -E 'running|degraded' >/dev/null && break; sleep 1; done
docker exec "$c" bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update -qq && apt-get install -y -qq git curl ca-certificates iproute2 jq >/dev/null'

echo "==> Copie de la copie de travail"
tar -C "$(dirname "$root")" --exclude="$(basename "$root")/admin-dashboard" -cf - "$(basename "$root")" \
  | docker exec -i "$c" bash -c 'mkdir -p /tmp/src && tar -xf - -C /tmp/src && mv /tmp/src/* /opt/hostkeep && git config --global --add safe.directory /opt/hostkeep'

echo "==> install.sh (web : $web)"
docker exec "$c" bash -c "cd /opt/hostkeep && ./install.sh --domain hostkeep.test --https no --web $web --admin-user admin --admin-email admin@hostkeep.test --yes" \
  | sed 's/\x1b\[[0-9;]*m//g' | grep -E '^==>|✔|!|Erreur'

echo "==> Comptes de test et sauvegarde"
pw=$(head -c 18 /dev/urandom | base64 | tr -d '/+=')
printf '%s' "$pw" | docker exec -i "$c" bash -c 'P=$(cat)
  for u in lecteur:viewer adminx:admin superx:superadmin; do
    printf "%s" "$P" | php /var/www/hostkeep/bin/user.php create "${u%%:*}" --role="${u#*:}" --email="${u%%:*}@hostkeep.test" --password-stdin >/dev/null
  done
  mysql hostkeep -e "REPLACE INTO settings (name, value, updated_at) VALUES (\"security.2fa_required\", \"none\", UNIX_TIMESTAMP()); UPDATE users SET must_change_password = 0"
  mkdir -p /var/www/html && hostkeep backup >/dev/null 2>&1'

echo "==> Attaques"
docker cp "$root/tests/integration/attack.sh" "$c:/root/attack.sh"
printf '%s' "$pw" | docker exec -i "$c" bash /root/attack.sh
