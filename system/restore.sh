#!/bin/bash
# Hostkeep — restauration / migration depuis une « archive de migration ».
# À lancer sur le NOUVEAU serveur (Debian 12/13, Ubuntu 22.04/24.04), dans le dossier extrait de l'archive :
#
#   tar -xzf hostkeep-migration-*.tar.gz && cd hostkeep-migration-*/
#   sudo bash restore.sh [--domain admin.example.com] [--email moi@example.com] [--yes]
#
# Ce qu'il fait : installe Hostkeep (même branche, depuis GitHub, ou le code de l'archive si GitHub est
# injoignable), réimporte les réglages du panel et, si l'archive en contient, les comptes (phrase de passe
# demandée), publie le panel sur le domaine, puis dit ce qu'il reste à faire (secrets à ressaisir, données).
# Rien n'est supprimé ; relancer le script est sans risque.
set -euo pipefail
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CONF=$HERE/config
SRC_DIR=/opt/hostkeep
APP=/var/www/hostkeep
if [[ -t 1 ]]; then B=$'\e[1m'; G=$'\e[32m'; Y=$'\e[33m'; R=$'\e[31m'; N=$'\e[0m'; else B='' G='' Y='' R='' N=''; fi
step() { echo; echo "${B}==> $*${N}"; }
ok() { echo "  ${G}✔${N} $*"; }
warn() { echo "  ${Y}!${N} $*" >&2; }
die() { echo "${R}Erreur :${N} $*" >&2; exit 1; }

DOMAIN='' EMAIL='' YES=0
while (($#)); do
  case $1 in
    --domain) DOMAIN=${2:-}; shift ;; --domain=*) DOMAIN=${1#*=} ;;
    --email) EMAIL=${2:-}; shift ;; --email=*) EMAIL=${1#*=} ;;
    -y|--yes) YES=1 ;;
    -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "option inconnue : $1 (voir --help)" ;;
  esac
  shift
done

[[ $(id -u) -eq 0 ]] || die "lance la restauration en root : sudo bash restore.sh"
[[ -f $CONF/manifest.json && -f $HERE/source/install.sh ]] || die "lance ce script depuis le dossier extrait de l'archive de migration."
# a value of the manifest, without jq (python3 may not be installed yet)
mget() { sed -n "s/^[[:space:]]*\"$1\":[[:space:]]*\"\{0,1\}\([^\",]*\)\"\{0,1\},\{0,1\}[[:space:]]*$/\1/p" "$CONF/manifest.json" | head -n1; }
[[ -n $DOMAIN ]] || DOMAIN=$(mget domain)
[[ -n $EMAIL ]] || EMAIL=$(mget letsencrypt_email)
BRANCH=$(mget branch); REPO=$(mget repo)
[[ $BRANCH =~ ^[A-Za-z0-9._/-]+$ && $BRANCH != HEAD ]] || BRANCH=main
[[ $REPO =~ ^https://[A-Za-z0-9./_-]+$ ]] || REPO=https://github.com/ydeloustal/HostKeep.git
tty() { ((YES == 0)) && { true </dev/tty; } 2>/dev/null; }

echo
echo "${B}  Hostkeep — restauration${N}"
echo "  Archive du $(date -d "@$(mget created)" '+%d/%m/%Y %H:%M' 2>/dev/null || echo '?'), serveur $(mget host), version $(mget version) ($(mget commit | cut -c1-7))"
echo "  Domaine du panel : ${DOMAIN:-(aucun : à configurer plus tard)}"
if [[ -n $DOMAIN ]]; then echo "  ${Y}Avant de continuer, l'enregistrement DNS A/AAAA de $DOMAIN doit pointer vers CE serveur.${N}"; fi
if tty; then
  read -r -p "  Domaine (Entrée pour garder « ${DOMAIN:-aucun} ») : " d </dev/tty || true
  [[ -z ${d:-} ]] || DOMAIN=$d
  read -r -p "  Lancer la restauration ? [O/n] " a </dev/tty || true
  [[ ${a:-o} =~ ^[oOyY] ]] || die "annulé."
fi

step "Code de Hostkeep"
if [[ -d $SRC_DIR/.git ]]; then
  ok "dépôt existant : $SRC_DIR"
elif [[ -f $SRC_DIR/install.sh && -d $SRC_DIR/app ]]; then
  ok "code déjà en place dans $SRC_DIR (copie hors ligne d'une restauration précédente)"
else
  command -v git >/dev/null || { apt-get update -qq </dev/null && apt-get install -y -qq git ca-certificates </dev/null >/dev/null; } || true
  if [[ ! -e $SRC_DIR ]] && timeout 120 git clone -q --branch "$BRANCH" "$REPO" "$SRC_DIR" 2>/dev/null; then
    ok "cloné depuis $REPO (branche $BRANCH) : les mises à jour depuis le panel fonctionneront"
  else
    [[ -e $SRC_DIR ]] && die "$SRC_DIR existe et n'est pas un dépôt git : déplace-le puis relance."
    cp -a "$HERE/source" "$SRC_DIR"
    warn "GitHub injoignable : code de l'archive copié dans $SRC_DIR (version $(mget version)). La mise à jour depuis le panel demandera un vrai clone git plus tard."
  fi
fi
chown -R root:root "$SRC_DIR"
INSTALL=$SRC_DIR/install.sh

step "Prérequis et application"
"$INSTALL" deps
"$INSTALL" app

step "Réglages et comptes"
args=("$CONF")
if [[ -f $CONF/accounts.json.enc ]]; then
  if [[ -t 0 ]] || { true </dev/tty; } 2>/dev/null; then
    args+=(--accounts)
  else
    warn "l'archive contient des comptes, mais pas de terminal pour demander la phrase de passe : comptes non importés."
  fi
fi
php "$APP/bin/user.php" import-config "${args[@]}"

if [[ -n $DOMAIN ]]; then
  step "Publication du panel sur $DOMAIN"
  web=(web --domain "$DOMAIN" --yes)
  [[ -z $EMAIL ]] || web+=(--email "$EMAIL")
  [[ $(mget https) != no ]] || web+=(--https no)
  "$INSTALL" "${web[@]}" || warn "publication web en échec : corrige le DNS puis lance « sudo hostkeep web --domain $DOMAIN »."
fi
"$INSTALL" admin   # creates a Super Admin only if none was imported

echo
echo "${G}${B}  Restauration terminée.${N}"
echo
echo "  ${B}Il reste à faire, dans le panel :${N}"
secrets=$(sed -n '/"secrets_to_reenter"/,/]/p' "$CONF/manifest.json" | grep -o '"[a-z_]*\.[a-z_0-9]*"' | tr -d '"' | tr '\n' ' ')
if [[ -n $secrets ]]; then
  echo "  - ressaisir les secrets, jamais exportés : $secrets"
  echo "    (Administration › Paramètres : E-mails, Alertes, Surveillance externe, Sauvegarde externe)"
fi
echo "  - chaque compte réactive sa double authentification (QR code) à sa prochaine connexion si son rôle l'exige."
echo
echo "  ${B}Et pour les données du serveur (sites, bases, certificats) :${N}"
echo "  1. Paramètres › Sauvegarde externe : mêmes réglages et même phrase de chiffrement qu'avant, puis « Tester la connexion »."
echo "  2. sudo hostkeep restore fetch            # liste les jours disponibles"
echo "     sudo hostkeep restore fetch AAAA-MM-JJ # rapatrie un jour"
echo "  3. sudo hostkeep restore db AAAA-MM-JJ <base>           # chaque base"
echo "     sudo hostkeep restore files AAAA-MM-JJ www --in-place # puis nginx / apache2 / letsencrypt"
echo
