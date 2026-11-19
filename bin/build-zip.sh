#!/usr/bin/env bash
# Build build/revolter-cookies.zip from a temp copy; the dev checkout is never modified
# (except build/, which is git-ignored).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

rm -rf "$ROOT/build"
mkdir -p "$ROOT/build"

# 1. Temp copy without dev-only trees, then production dependencies only.
rsync -a --exclude=vendor --exclude=node_modules --exclude=build --exclude=tests/.wp-core --exclude=.git "$ROOT/" "$TMP/src/"
(cd "$TMP/src" && composer install --no-dev --optimize-autoloader --no-interaction -n --no-progress)

# 2. Compiled translations (also tracked in git, regenerated in the copy only).
(cd "$TMP/src" && wp i18n make-mo languages && wp i18n make-php languages)

# 3. Stage runtime files only.
mkdir -p "$TMP/stage/revolter-cookies"
rsync -a --exclude-from="$TMP/src/.distignore" "$TMP/src/" "$TMP/stage/revolter-cookies/"

# 4. Zip.
(cd "$TMP/stage" && zip -rq "$ROOT/build/revolter-cookies.zip" revolter-cookies)

echo "Built $ROOT/build/revolter-cookies.zip"
