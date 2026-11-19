#!/usr/bin/env bash
# Prepare the WordPress core copy and database used by the PHPUnit suite.
# Idempotent: safe to re-run. Overridable env: RC_DB_NAME, RC_DB_USER, RC_DB_PASS, RC_DB_HOST, RC_MYSQL, RC_WP_VERSION.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_NAME="${RC_DB_NAME:-revolter_cookies_tests}"
DB_USER="${RC_DB_USER:-root}"
DB_PASS="${RC_DB_PASS:-}"
DB_HOST="${RC_DB_HOST:-127.0.0.1}"
MYSQL_BIN="${RC_MYSQL:-/Users/Shared/DBngin/mysql/8.0.33/bin/mysql}"

# Match the WordPress core version to the installed wp-phpunit package (major.minor).
if [ -n "${RC_WP_VERSION:-}" ]; then
	WP_VERSION="$RC_WP_VERSION"
else
	WP_VERSION="$(cd "$ROOT" && composer show wp-phpunit/wp-phpunit 2>/dev/null | awk '/^versions/ {gsub(/[*, ]/, "", $0); sub(/^versions:/, "", $0); print $0}' | cut -d. -f1,2)"
fi
if [ -z "$WP_VERSION" ]; then
	echo "Could not determine the wp-phpunit version. Run composer install first." >&2
	exit 1
fi

echo "Downloading WordPress $WP_VERSION to tests/.wp-core"
wp core download --version="$WP_VERSION" --path="$ROOT/tests/.wp-core" --skip-content --force

echo "Creating database $DB_NAME"
MYSQL_ARGS=(-h"$DB_HOST" -u"$DB_USER")
if [ -n "$DB_PASS" ]; then
	MYSQL_ARGS+=(-p"$DB_PASS")
fi
"$MYSQL_BIN" "${MYSQL_ARGS[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4;"
echo "Done."
