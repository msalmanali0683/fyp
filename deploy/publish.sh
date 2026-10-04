#!/usr/bin/env bash
# Publishes the production tree to the `deploy` branch, which Hostinger watches.
#
# Run from a checkout that is already built:
#   composer install --no-dev --optimize-autoloader   (vendor/)
#   npm ci && npm run build                           (public/build/)
#
# The branch holds exactly what the server needs (source + vendor/ + public/build/),
# never .env or runtime storage files. History is append-only so a plain
# `git pull` on the server always fast-forwards.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE_URL="${DEPLOY_REMOTE_URL:-$(git -C "$ROOT" remote get-url origin)}"
SRC_SHA="$(git -C "$ROOT" rev-parse HEAD)"
GIT_NAME="${DEPLOY_GIT_NAME:-fyp-deploy}"
GIT_EMAIL="${DEPLOY_GIT_EMAIL:-deploy@users.noreply.github.com}"

[ -f "$ROOT/vendor/autoload.php" ] || { echo "vendor/ missing - run composer install --no-dev first" >&2; exit 1; }
[ -f "$ROOT/public/build/manifest.json" ] || { echo "public/build missing - run npm run build first" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
OUT="$WORK/out"

if git ls-remote --exit-code --heads "$REMOTE_URL" deploy >/dev/null 2>&1; then
  git clone --quiet --depth 1 --branch deploy "$REMOTE_URL" "$OUT"
else
  git init --quiet -b deploy "$OUT"
  git -C "$OUT" remote add origin "$REMOTE_URL"
fi

# Replace the tree with the fresh build (history in .git is kept).
find "$OUT" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +

cd "$ROOT"
{
  git ls-files -z | grep -zvE '^(tests/|\.github/|\.gitattributes$|\.editorconfig$|phpunit\.xml$)' || true
  find vendor public/build -type f -print0
} | tar --null -T - -cf - | tar -C "$OUT" -xf -

cp "$ROOT/deploy/htaccess.production" "$OUT/.htaccess"

# vendor/ and public/build/ are ignored in the source repo but must be tracked here.
cat > "$OUT/.gitignore" <<'EOF'
.env
.env.*
!.env.example
/storage/*.key
EOF

cd "$OUT"
git add -A
if git diff --cached --quiet; then
  echo "Nothing changed since the last deploy - skipping."
  exit 0
fi

echo "$SRC_SHA" > .deploy-revision
git add .deploy-revision
git -c user.name="$GIT_NAME" -c user.email="$GIT_EMAIL" commit --quiet -m "Deploy ${SRC_SHA:0:7}"
git push --quiet origin deploy
echo "Published ${SRC_SHA:0:7} to the deploy branch."
