#!/bin/bash
# Refuses tool-generated attribution lines (AI co-author trailers, "Generated with …" footers) in commit messages
# and tracked files. Used by the commit-msg hook (tools/git-hooks/commit-msg) and by the CI.
#
#   tools/check-attribution.sh --message FILE      a commit message (commit-msg hook)
#   tools/check-attribution.sh --range A..B        every commit message of a range (CI)
#   tools/check-attribution.sh --files             every tracked file (CI)
#
# The product's own AI assistant (an optional extension, see docs/strategy) may name providers: only attribution
# patterns are refused, not the words themselves.
set -euo pipefail
PATTERN='co-authored-by:.*(claude|anthropic|openai|chatgpt|copilot|gemini|cursor|codeium|devin)|noreply@anthropic\.com|generated (with|by) .*(claude|chatgpt|copilot|gemini|cursor|\bai\b)|\[claude code\]|claude\.com/claude-code|🤖'

check_text() {   # check_text <label> < text
  local found
  found=$(grep -inE "$PATTERN" || true)
  if [[ -n $found ]]; then
    echo "Refusé ($1) : mention d'un outil de génération dans le texte :" >&2
    echo "$found" >&2
    return 1
  fi
}

case ${1:-} in
  --message)
    grep -v '^#' "$2" | check_text "message de commit" ;;
  --range)
    rc=0
    for c in $(git rev-list --no-merges "$2"); do
      git log -1 --format=%B "$c" | check_text "commit $(git rev-parse --short "$c")" || rc=1
    done
    exit "$rc" ;;
  --files)
    here=$(git rev-parse --show-toplevel)
    found=$(git -C "$here" grep -inE "$PATTERN" -- . ':!tools/check-attribution.sh' ':!tests/helper/attribution_test.sh' || true)
    if [[ -n $found ]]; then echo "Refusé (fichiers suivis) :" >&2; echo "$found" >&2; exit 1; fi ;;
  *)
    sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//' >&2; exit 2 ;;
esac
