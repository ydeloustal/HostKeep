#!/bin/bash
# Attack tests against a DISPOSABLE Hostkeep install (run inside the test container, see tests/integration/README.md).
# The password of the three test accounts comes on stdin.
set -u
PW=$(cat)
H='Host: hostkeep.test'
BASE=http://127.0.0.1
J=/tmp/hk-jars; rm -rf $J; mkdir -p $J
fails=0
ok() { echo "ok   $1"; }
ko() { echo "FAIL $1 -> $2"; fails=$((fails + 1)); }

login() {   # login <user>: cookie jar $J/<user>, CSRF token in $J/<user>.csrf
  local u=$1 t
  t=$(curl -s -c $J/$u -b $J/$u -H "$H" $BASE/login.php | grep -o 'name="csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
  curl -s -o /dev/null -c $J/$u -b $J/$u -H "$H" --data-urlencode "csrf=$t" --data-urlencode "user=$u" --data-urlencode "pass=$PW" $BASE/login.php
  curl -s -c $J/$u -b $J/$u -H "$H" $BASE/ | grep -o 'name="csrf" content="[^"]*"' | cut -d'"' -f4 > $J/$u.csrf
  [[ -s $J/$u.csrf ]] && echo "     connecté : $u" || echo "     ÉCHEC de connexion : $u"
}
get() { curl -s -b $J/$1 -H "$H" -w '\n%{http_code}' "$BASE/api.php?$2"; }
post() { curl -s -b $J/$1 -H "$H" -H "X-CSRF-Token: $(cat $J/$1.csrf)" -H 'Content-Type: application/json' -w '\n%{http_code}' --data "$3" "$BASE/api.php?$2"; }
code() { tail -n1 <<< "$1"; }
body() { sed '$d' <<< "$1"; }
expect_code() {   # expect_code <label> <wanted> <response>
  local c; c=$(code "$3")
  if [[ $c == "$2" ]]; then ok "$1 ($c)"; else ko "$1" "HTTP $c, attendu $2 : $(body "$3" | head -c 160)"; fi
}

for u in lecteur adminx superx; do login $u; done

echo "== Lecteur : aucune action, aucun fichier, aucun journal"
expect_code "Lecteur : redémarrer un service" 403 "$(post lecteur 'r=action' '{"verb":"service","args":["restart","cron"]}')"
expect_code "Lecteur : lire un journal" 403 "$(get lecteur 'r=log&key=fail2ban&lines=10')"
expect_code "Lecteur : explorateur" 403 "$(get lecteur 'r=fs&path=/var/www')"
expect_code "Lecteur : journal des actions" 403 "$(get lecteur 'r=audit')"
expect_code "Lecteur : comptes" 403 "$(get lecteur 'r=users')"
expect_code "Lecteur : télécharger une sauvegarde" 403 "$(get lecteur 'r=backupdl&day=2026-01-01&db=x')"

echo "== CSRF"
r=$(curl -s -b $J/adminx -H "$H" -H 'Content-Type: application/json' -w '\n%{http_code}' --data '{"verb":"collect-now","args":[]}' "$BASE/api.php?r=action")
expect_code "POST sans jeton CSRF" 403 "$r"
r=$(curl -s -b $J/adminx -H "$H" -H 'X-CSRF-Token: faux' -H 'Content-Type: application/json' -w '\n%{http_code}' --data '{"verb":"collect-now","args":[]}' "$BASE/api.php?r=action")
expect_code "POST avec un faux jeton CSRF" 403 "$r"

echo "== S4 : sortie des tâches"
r=$(post adminx 'r=action' '{"verb":"collect-now","args":[]}'); job=$(body "$r" | grep -o '"job":"[^"]*"' | cut -d'"' -f4)
[[ $job =~ ^[0-9]+-[0-9a-f]{16}$ ]] && ok "identifiant de tâche aléatoire ($job)" || ko "identifiant de tâche" "$(body "$r")"
sleep 2
r=$(get lecteur "r=job&id=$job"); [[ $(body "$r") == *"inconnue"* ]] && ok "Lecteur : sortie d'une tâche Administrateur refusée" || ko "Lecteur lit la tâche" "$(body "$r" | head -c 120)"
r=$(get adminx "r=job&id=$job"); [[ $(body "$r") == *'"output"'* ]] && ok "Administrateur : lit sa propre tâche" || ko "auteur" "$(body "$r" | head -c 120)"
r=$(get superx "r=job&id=$job"); [[ $(body "$r") == *'"output"'* ]] && ok "Super Admin : lit la tâche" || ko "superadmin" "$(body "$r" | head -c 120)"
expect_code "Identifiant de tâche invalide (injection)" 200 "$(get adminx 'r=job&id=..%2F..%2Fetc%2Fpasswd')"

echo "== S2 : explorateur"
for p in /var/www/hostkeep/.env /var/www/hostkeep/../hostkeep/.env /var/www/../www/hostkeep/.env /etc/shadow /var/log/../../etc/shadow /etc/hostkeep/rclone.conf /var/lib/hostkeep/state.json; do
  r=$(get adminx "r=fsread&path=$(printf %s "$p" | jq -sRr @uri)")
  [[ $(code "$r") == 400 && $(body "$r") != *'"content"'* ]] && ok "Administrateur : lecture refusée de $p" || ko "lecture de $p" "$(body "$r" | head -c 120)"
done
ln -sfn /var/www/hostkeep/.env /var/www/html/lien-env 2>/dev/null
r=$(get adminx "r=fsread&path=/var/www/html/lien-env"); [[ $(body "$r") != *HOSTKEEP_APP_KEY* ]] && ok "Administrateur : lien symbolique vers le .env refusé" || ko "lien symbolique" "$(body "$r" | head -c 120)"
conf=$(ls /etc/nginx/nginx.conf /etc/apache2/apache2.conf 2>/dev/null | head -n1); r=$(get adminx "r=fsread&path=$conf"); [[ $(body "$r") == *'"content"'* ]] && ok "Administrateur : lit toujours une config normale" || ko "lecture normale" "$(body "$r" | head -c 120)"

echo "== S2 : sauvegardes"
days=(/var/backups/hostkeep/[0-9][0-9][0-9][0-9]-*); day=$(basename "${days[-1]}")
expect_code "Administrateur : base hostkeep" 403 "$(get adminx "r=backupdl&day=$day&db=hostkeep")"
expect_code "Administrateur : archive complète du jour" 403 "$(get adminx "r=backupdl&day=$day&archive=")"
expect_code "Administrateur : archive hostkeep" 403 "$(get adminx "r=backupdl&day=$day&archive=hostkeep")"
expect_code "Administrateur : restaurer la base hostkeep" 403 "$(post adminx 'r=action' "{\"verb\":\"backup-restore\",\"args\":[\"$day\",\"hostkeep\"]}")"
curl -s -b $J/adminx -H "$H" -o /tmp/www.tgz "$BASE/api.php?r=backupdl&day=$day&archive=www"
if tar -tzf /tmp/www.tgz >/dev/null 2>&1; then
  tar -tzf /tmp/www.tgz | grep -q 'var/www/hostkeep/.env' && ko "archive www" "contient le .env du panel" || ok "Administrateur : archive www téléchargée, sans le .env du panel"
else ko "archive www" "téléchargement impossible"; fi
r=$(curl -s -b $J/superx -H "$H" -o /tmp/hk.sql.gz -w '%{http_code}' "$BASE/api.php?r=backupdl&day=$day&db=hostkeep")
[[ $r == 200 ]] && gunzip -t /tmp/hk.sql.gz && ok "Super Admin : base hostkeep téléchargée" || ko "Super Admin base hostkeep" "$r"
curl -s -b $J/superx -H "$H" -o /tmp/hk.tgz "$BASE/api.php?r=backupdl&day=$day&archive=hostkeep"
tar -tzf /tmp/hk.tgz 2>/dev/null | grep -q 'var/www/hostkeep/.env' && ok "Super Admin : le .env du panel est dans l'archive hostkeep" || ko "archive hostkeep" "pas de .env"

echo "== S1 : configuration rclone"
for cfg in 'type = sftp\nhost = h\nssh = sh -c id' 'type = local' 'type = webdav\nurl = https://x\nbearer_token_command = id'; do
  r=$(post adminx 'r=settings.save&group=offsite' "{\"values\":{\"offsite.type\":\"rclone\",\"offsite.rclone_config\":\"$cfg\"}}")
  [[ $(code "$r") == 422 ]] && ok "Administrateur : refus de « $(echo -e "$cfg" | tail -1) »" || ko "rclone $cfg" "$(body "$r" | head -c 160)"
done

echo "== Escalade de rôle"
expect_code "Administrateur : créer un Super Admin" 422 "$(post adminx 'r=users.create' '{"username":"pirate","role":"superadmin","mode":"generate"}')"
uid=$(mysql -N hostkeep -e "SELECT id FROM users WHERE username='superx'")
r=$(post adminx "r=users.update&id=$uid" '{"role":"viewer"}'); [[ $(code "$r") != 200 ]] && ok "Administrateur : rétrograder un Super Admin refusé ($(code "$r"))" || ko "rétrograder superadmin" "$(body "$r")"
expect_code "Administrateur : paramètres de sécurité" 403 "$(post adminx 'r=settings.save&group=security' '{"values":{"security.ip_allowlist":""}}')"
expect_code "Administrateur : mettre à jour le panel" 403 "$(post adminx 'r=action' '{"verb":"self-update","args":[]}')"

echo "== Injection d'arguments"
for a in '["restart","cron;id"]' '["restart","../../bin/sh"]' '["restart","ssh"]' '["stop","cron"]'; do
  r=$(post superx 'r=action' "{\"verb\":\"service\",\"args\":$a}")
  [[ $(code "$r") == 400 ]] && ok "service $a refusé" || ko "service $a" "$(body "$r" | head -c 120)"
done
r=$(post superx 'r=action' '{"verb":"f2b-unban","args":["sshd","1.2.3.4; id"]}'); [[ $(code "$r") == 400 ]] && ok "IP avec injection refusée" || ko "f2b" "$(body "$r")"
r=$(post superx 'r=action' '{"verb":"site","args":["enable","nginx","../../etc/passwd"]}'); [[ $(code "$r") == 400 ]] && ok "vhost avec .. refusé" || ko "site" "$(body "$r")"
r=$(post superx 'r=action' '{"verb":"site","args":["disable","nginx","hostkeep"]}'); [[ $(code "$r") == 400 ]] && ok "désactiver le vhost du panel refusé" || ko "site hostkeep" "$(body "$r")"
r=$(post superx 'r=action' '{"verb":"web-domain","args":["a.com --web none"]}'); [[ $(code "$r") == 400 ]] && ok "domaine avec options refusé" || ko "web-domain" "$(body "$r")"

echo "== Force brute"
for i in 1 2 3 4 5 6; do
  t=$(curl -s -c $J/bf -b $J/bf -H "$H" $BASE/login.php | grep -o 'name="csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
  out=$(curl -s -c $J/bf -b $J/bf -H "$H" --data-urlencode "csrf=$t" --data-urlencode "user=lecteur" --data-urlencode "pass=mauvais$i" $BASE/login.php)
done
[[ $out == *"Trop de tentatives"* || $out == *"verrouillé"* ]] && ok "blocage après des échecs répétés" || ko "force brute" "pas de blocage"
mysql hostkeep -e "DELETE FROM login_attempts; UPDATE users SET locked_until=0, failed_logins=0"

echo
((fails)) && echo "$fails ÉCHEC(S)" || echo "TOUTES LES ATTAQUES ONT ÉTÉ BLOQUÉES"
