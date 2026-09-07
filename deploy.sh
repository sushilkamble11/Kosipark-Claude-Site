#!/bin/bash
# Deploy the current commit to Hostinger.
#
# The site is served from the `deploy` branch, whose root is public_html — the
# repo root itself holds docs/ and test/, which must not be web-served. This
# rebuilds that branch from public_html, pushes it, then pokes Hostinger's
# webhook so it pulls immediately instead of waiting for a manual Deploy click.
#
# Run from the repo root on a machine that can reach github.com.
set -euo pipefail
cd "$(dirname "$0")"

: "${GH_TOKEN:?set GH_TOKEN}"
HOOK="https://webhooks.hostinger.com/deploy/153a60eb6668f6049da0af62cd90dff6"
REPO="github.com/sushilkamble11/Kosipark-Claude-Site.git"

git remote set-url origin "https://x-access-token:${GH_TOKEN}@${REPO}" 2>/dev/null \
  || git remote add origin "https://x-access-token:${GH_TOKEN}@${REPO}"

echo "→ pushing main"
git push -q origin main 2>&1 | sed "s/${GH_TOKEN}/***/g"

echo "→ rebuilding deploy branch from public_html"
git branch -D deploy >/dev/null 2>&1 || true
git subtree split --prefix=public_html -b deploy >/dev/null

echo "→ pushing deploy"
git push -qf origin deploy 2>&1 | sed "s/${GH_TOKEN}/***/g"

echo "→ triggering Hostinger pull"
curl -sS -o /dev/null -w "   webhook: HTTP %{http_code}\n" -X POST "$HOOK"

echo "done"
