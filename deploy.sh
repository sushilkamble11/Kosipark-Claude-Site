#!/bin/bash
# Publish V2 while preserving V1 on the remote main branch.
# The public Hostinger checkout follows the deploy branch.
set -euo pipefail
cd "$(dirname "$0")"

REMOTE="https://github.com/sushilkamble11/Kosipark-Claude-Site.git"
MSG="${1:-Publish Kosipark V2}"

if grep -q 'coachmans-eden\.bookus\.direct' public_html/booking-config.js; then
  echo "Refusing to publish: replace the temporary demonstration booking URL first."
  exit 1
fi

git remote get-url origin >/dev/null 2>&1 || git remote add origin "$REMOTE"

echo "Publishing the preserved V2 source"
git push -q origin HEAD:refs/heads/v2

echo "Preparing the public website update"
git fetch -q origin deploy
TREE="$(git rev-parse HEAD:public_html)"
PARENT="$(git rev-parse origin/deploy)"

if [ "$(git rev-parse "$PARENT^{tree}")" = "$TREE" ]; then
  echo "The public website already matches V2."
  exit 0
fi

NEW="$(git commit-tree "$TREE" -p "$PARENT" -m "$MSG")"
git push -q origin "$NEW:refs/heads/deploy"
echo "Published. Hostinger will pull the update through its existing webhook."

