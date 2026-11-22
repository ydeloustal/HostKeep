#!/bin/bash
# The shell scripts run with « set -o pipefail ». There, « cmd | grep -q X » fails at random: grep -q exits at the
# first match, cmd gets SIGPIPE (code 141) and pipefail reports the pipeline as failed. Use « grep X >/dev/null ».
# (This bug kept Caddy from ever being installed, and could hide a domain already served by Caddy.)
set -u
here=$(cd "$(dirname "$0")/../.." && pwd)
found=$(grep -nE '\|[[:space:]]*grep[[:space:]]+-[A-Za-z]*q' "$here"/install.sh "$here"/system/hostkeep-action "$here"/system/hostkeep-cli "$here"/system/restore.sh)
if [[ -n $found ]]; then
  echo "FAIL « | grep -q » dans un script en pipefail :"; echo "$found"
  exit 1
fi
echo "ok   aucun « | grep -q » dans les scripts en pipefail"
echo
echo "Tous les tests passent."
