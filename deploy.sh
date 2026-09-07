#!/bin/bash
# Deploy the current commit to Hostinger.
#
# The site is served from the `deploy` branch, whose tree is public_html — the
# repo root holds docs/ and test/, which must not be web-served.
#
# That branch is built with commit-tree rather than `git subtree split`, and the
# difference matters: subtree split regenerates the whole branch each run, so
# every deploy was a force-push that rewrote history. Hostinger's end does a
# plain `git pull`, which refuses a non-fast-forward — so after the first deploy
# the server silently stopped updating while every push looked fine from here.
#
# commit-tree hangs one new commit off the previous deploy commit with the
# current public_html as its tree. Always a fast-forward, always one commit per
# deploy, and the server pulls it without complaint.
set -euo pipefail
cd "$(dirname "$0")"

: "${GH_TOKEN:?set GH_TOKEN}"
REPO="github.com/sushilkamble11/Kosipark-Claude-Site.git"
REMOTE="https://x-access-token:${GH_TOKEN}@${REPO}"
MSG="${1:-Deploy $(git rev-parse --short HEAD)}"

git remote set-url origin "$REMOTE" 2>/dev/null || git remote add origin "$REMOTE"

echo "→ pushing main"
git push -q origin main 2>&1 | sed "s/${GH_TOKEN}/***/g" | grep -vi "unable to unlink" || true

echo "→ building deploy commit from public_html"
git fetch -q origin deploy 2>/dev/null || true
TREE=$(git rev-parse HEAD:public_html)
PARENT=$(git rev-parse FETCH_HEAD 2>/dev/null || echo "")

if [ -n "$PARENT" ] && [ "$(git rev-parse "$PARENT^{tree}")" = "$TREE" ]; then
  echo "   public_html unchanged since the last deploy — nothing to push"
  exit 0
fi

if [ -n "$PARENT" ]; then
  NEW=$(git commit-tree "$TREE" -p "$PARENT" -m "$MSG")
else
  NEW=$(git commit-tree "$TREE" -m "$MSG")
fi

echo "→ pushing deploy ($NEW)"
git push -q origin "$NEW:refs/heads/deploy" 2>&1 \
  | sed "s/${GH_TOKEN}/***/g" | grep -vi "unable to unlink\|update_ref\|Another git process\|remove the file" || true

echo "done — GitHub's webhook tells Hostinger to pull"
