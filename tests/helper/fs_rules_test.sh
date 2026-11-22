#!/bin/bash
# File explorer rules of the root helper (system/hostkeep-action): allowed roots, Hostkeep's own files,
# private keys. Runs without root, on a temporary tree. bash tests/helper/fs_rules_test.sh
set -u
here=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=../../system/hostkeep-action
source "$here/../../system/hostkeep-action"
set +e

tmp=$(mktemp -d); tmp=$(realpath "$tmp")
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/www/site/sub" "$tmp/www/hostkeep" "$tmp/outside"
touch "$tmp/www/site/index.php" "$tmp/www/hostkeep/.env" "$tmp/outside/secret.txt"
ln -s "$tmp/www/hostkeep/.env" "$tmp/www/site/link-to-panel-env"
ln -s "$tmp/outside/secret.txt" "$tmp/www/site/link-outside"
# read by the sourced helper functions
# shellcheck disable=SC2034
FS_ROOTS="$tmp/www "
# shellcheck disable=SC2034
PANEL_DIRS="$tmp/www/hostkeep /nonexistent/etc/hostkeep"

fails=0
ok() { echo "ok   $1"; }
ko() { echo "FAIL $1"; fails=$((fails + 1)); }
allowed() { if fs_path "$2" >/dev/null 2>&1; then ok "$1"; else ko "$1"; fi; }
refused() { if fs_path "$2" >/dev/null 2>&1; then ko "$1"; else ok "$1"; fi; }

allowed "fichier d'un site" "$tmp/www/site/index.php"
allowed "racine autorisée" "$tmp/www"
refused "dossier de Hostkeep" "$tmp/www/hostkeep"
refused ".env de Hostkeep" "$tmp/www/hostkeep/.env"
refused "lien symbolique vers le .env de Hostkeep" "$tmp/www/site/link-to-panel-env"
refused "remontée par .. vers Hostkeep" "$tmp/www/site/../hostkeep/.env"
refused "hors des racines" "$tmp/outside/secret.txt"
refused "lien symbolique hors des racines" "$tmp/www/site/link-outside"
refused "remontée par .. hors des racines" "$tmp/www/../outside/secret.txt"
refused "chemin relatif" "www/site/index.php"
refused "fichier inexistant" "$tmp/www/site/nope"

is() { if "$2" "$3"; then ok "$1"; else ko "$1"; fi; }
not() { if "$2" "$3"; then ko "$1"; else ok "$1"; fi; }
is  "clé privée : privkey1.pem" fs_private /etc/letsencrypt/archive/x/privkey1.pem
is  "clé privée : server.key" fs_private /etc/ssl/server.key
is  "clé privée : id_ed25519" fs_private /home/u/.ssh/id_ed25519
is  "clé privée : id_dsa" fs_private /home/u/.ssh/id_dsa
not "pas une clé : index.php" fs_private /var/www/site/index.php
not "pas une clé : fullchain.pem" fs_private /etc/letsencrypt/live/x/fullchain.pem

echo
if ((fails)); then echo "$fails échec(s)"; exit 1; fi
echo "Tous les tests passent."
