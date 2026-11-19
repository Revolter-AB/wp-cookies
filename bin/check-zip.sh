#!/usr/bin/env bash
# Verify a release zip contains only runtime files.
set -euo pipefail

ZIP="${1:-build/revolter-cookies.zip}"
[ -f "$ZIP" ] || { echo "Zip not found: $ZIP" >&2; exit 1; }

LIST="$(unzip -Z1 "$ZIP")"
fail=0

bad="$(grep -E '(^|/)(docs|tests|node_modules|\.github|planning|\.claude|\.git)(/|$)|\.md$|composer\.json|phpunit|CLAUDE|(^|/)\.DS_Store$|(^|/)phpcs\.xml|(^|/)package\.json$' <<<"$LIST" || true)"
if [ -n "$bad" ]; then
	echo "Forbidden entries in zip:" >&2
	printf '%s\n' "$bad" >&2
	fail=1
fi

for need in \
	'revolter-cookies/revolter-cookies.php' \
	'revolter-cookies/readme.txt' \
	'revolter-cookies/vendor/yahnis-elsts/' \
	'revolter-cookies/languages/revolter-cookies-sv_SE.mo'; do
	if ! grep -qF -- "$need" <<<"$LIST"; then
		echo "Missing required entry: $need" >&2
		fail=1
	fi
done

[ "$fail" -eq 0 ] || exit 1
echo "zip OK"
