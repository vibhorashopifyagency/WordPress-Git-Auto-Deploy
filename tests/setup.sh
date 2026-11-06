#!/usr/bin/env bash
# Builds a throwaway WordPress for the end-to-end tests: latest WordPress on SQLite, a test theme
# that is active, and this repository's plugin installed and active. Run it before tests/e2e.sh.
#
#   bash tests/setup.sh
#   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t tests/.work/wordpress &
#   bash tests/e2e.sh
#
# Environment (all optional):
#   PHP    PHP binary, 8.0+ with zip and pdo_sqlite (default: php)
#   PORT   port the test site will be served on (default: 8899)
#   WORK   folder for downloads and the test site (default: tests/.work, ignored by git)
#
# Running it again starts from a fresh site; downloads are reused.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$ROOT/tests/.work}"
PHP="${PHP:-php}"
PORT="${PORT:-8899}"
W="$WORK/wordpress"
R="$WORK/repo"

wp() { "$PHP" "$WORK/dl/wp-cli.phar" --path="$W" "$@"; }

mkdir -p "$WORK/dl"
download() { [ -s "$WORK/dl/$1" ] || curl -sSfL -o "$WORK/dl/$1" "$2"; }
download wordpress.zip https://wordpress.org/latest.zip
download sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
download wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

rm -rf "$W" "$R"
unzip -q "$WORK/dl/wordpress.zip" -d "$WORK"
unzip -q "$WORK/dl/sqlite.zip" -d "$W/wp-content/plugins/"

# SQLite instead of MySQL, so the tests need no database server.
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$W/wp-content/plugins/sqlite-database-integration#" \
  -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
  "$W/wp-content/plugins/sqlite-database-integration/db.copy" > "$W/wp-content/db.php"

cat > "$W/wp-config.php" << 'EOF'
<?php
define( 'DB_NAME', 'wp' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );
$table_prefix = 'wp_';
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) {
	define( $key, 'test-only-' . $key );
}
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
EOF

wp core install --url="http://127.0.0.1:$PORT" --title="Git Auto Deploy tests" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email > /dev/null

# A Git repository holding a whole site, with the theme in a subfolder, as most users have it.
T="$R/wp-content/themes/e2e-theme"
mkdir -p "$T"
printf '/*\nTheme Name: E2E Theme\n*/\n' > "$T/style.css"
printf '<!doctype html><html><body><h1>version one</h1></body></html>\n' > "$T/index.php"
git -C "$R" init -q -b main
git -C "$R" -c user.name=test -c user.email=test@example.com add -A
git -C "$R" -c user.name=test -c user.email=test@example.com commit -qm "Version one"

# The same theme is live on the site before the first deploy.
cp -R "$T" "$W/wp-content/themes/"
wp theme activate e2e-theme > /dev/null

cp -R "$ROOT/plugin/git-auto-deploy" "$W/wp-content/plugins/"
wp plugin activate git-auto-deploy > /dev/null

echo "Test site ready in $W (theme e2e-theme active, Git Auto Deploy $(wp plugin get git-auto-deploy --field=version) active)."
echo "Serve it with:  PHP_CLI_SERVER_WORKERS=4 $PHP -S 127.0.0.1:$PORT -t $W"
