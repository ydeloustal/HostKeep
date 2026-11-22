#!/bin/bash
# Signed updates of the root helper (system/hostkeep-action): only a release tag signed by a key of
# allowed_signers is chosen. Real git and ssh-keygen, in a temporary folder, without root.
set -u
here=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=../../system/hostkeep-action
source "$here/../../system/hostkeep-action"
set +e

tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
export HOME=$tmp   # the helper sets HOME=/root for its own commands
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
ssh-keygen -q -t ed25519 -N '' -C good -f "$tmp/good"
ssh-keygen -q -t ed25519 -N '' -C bad -f "$tmp/bad"
# shellcheck disable=SC2034  # read by the sourced helper functions
SIGNERS=$tmp/allowed_signers
signtag() { git -C "$repo" -c gpg.format=ssh -c user.signingkey="$tmp/$1" tag -s "$2" -m "$2" "${3:-HEAD}"; }
commit() { echo "$1" > "$repo/f"; git -C "$repo" add f; git -C "$repo" commit -qm "$1"; }

repo=$tmp/repo
git init -q "$repo"
commit one; signtag good v1.0.0
installed=$(git -C "$repo" rev-parse HEAD)
commit two; signtag good v1.1.0
commit three; git -C "$repo" tag -a v1.2.0 -m unsigned
commit four; signtag bad v1.3.0
git -C "$repo" checkout -q "$installed"

fails=0
check() { if [[ $2 == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1 (obtenu « $2 », attendu « $3 »)"; fails=$((fails + 1)); fi; }

: > "$SIGNERS"
if signed_mode; then check "fichier vide : mode non signé" signed unsigned; else check "fichier vide : mode non signé" unsigned unsigned; fi
echo "# comment only" > "$SIGNERS"
if signed_mode; then check "commentaires seuls : mode non signé" signed unsigned; else check "commentaires seuls : mode non signé" unsigned unsigned; fi

echo "good namespaces=\"git\" $(cat "$tmp/good.pub")" > "$SIGNERS"
if signed_mode; then check "clé présente : mode signé" signed signed; else check "clé présente : mode signé" unsigned signed; fi
check "tag signé par la bonne clé : vérifié" "$(verify_tag "$repo" v1.1.0 && echo yes || echo no)" yes
check "tag non signé : refusé" "$(verify_tag "$repo" v1.2.0 && echo yes || echo no)" no
check "tag signé par une clé inconnue : refusé" "$(verify_tag "$repo" v1.3.0 && echo yes || echo no)" no
check "version choisie : la plus récente signée par une clé autorisée" "$(latest_signed_tag "$repo" 2>/dev/null)" v1.1.0

git -C "$repo" checkout -q v1.1.0
check "déjà sur la dernière version signée : rien à installer" "$(latest_signed_tag "$repo" 2>/dev/null)" v1.1.0
git -C "$repo" checkout -q v1.3.0
check "tags plus anciens que la version installée ignorés" "$(latest_signed_tag "$repo" 2>/dev/null)" ""

echo
if ((fails)); then echo "$fails échec(s)"; exit 1; fi
echo "Tous les tests passent."
