#!/usr/bin/env bash
# End-to-end tests: real deploys and rollbacks against the throwaway WordPress from tests/setup.sh,
# using the same steps and script as the GitHub Actions workflow. Needs the site to be served:
#
#   bash tests/setup.sh
#   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t tests/.work/wordpress &
#   bash tests/e2e.sh
#
# PHP_CLI_SERVER_WORKERS matters: the plugin's health check loads the site while the deploy
# request is still running, which a single-worker server cannot answer.
#
# Same PHP, PORT and WORK variables as tests/setup.sh. Needs curl, openssl, git and jq.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$ROOT/tests/.work}"
PHP="${PHP:-php}"
PORT="${PORT:-8899}"
W="$WORK/wordpress"
R="$WORK/repo"
OUT="$WORK/out.json"
SCRIPT="$ROOT/github-actions/scripts/git-auto-deploy.sh"
THEMES="$W/wp-content/themes"
BASE="$W/wp-content/git-auto-deploy"
MU="$W/wp-content/mu-plugins"

wp() { "$PHP" "$WORK/dl/wp-cli.phar" --path="$W" "$@" 2> /dev/null; }

# The deploy script takes the commit from GITHUB_SHA when it is set. On GitHub Actions that is
# this repository's commit, not the test repository's, so leave it to `git rev-parse HEAD`.
unset GITHUB_SHA

export GIT_AUTO_DEPLOY_URL="http://127.0.0.1:$PORT"
GIT_AUTO_DEPLOY_SECRET="$(wp option get git_auto_deploy_secret)"
export GIT_AUTO_DEPLOY_SECRET

pass=0
fail=0

# On GitHub Actions, failures also become annotations: they show on the run's summary page
# and can be read through the API without signing in, unlike the full log.
annotate() { # annotate <title> <text>
  [ "${GITHUB_ACTIONS:-}" = true ] || return 0
  local msg
  msg="$(printf '%s' "$2" | tail -c 1500 | sed -e 's/%/%25/g' -e 's/\r/%0D/g' | awk '{ printf "%s%%0A", $0 }')"
  echo "::error title=$1::$msg"
}

check() { # check "<what should be true>" <command...>
  local name="$1"
  shift
  if "$@"; then
    echo "PASS  $name"
    pass=$((pass + 1))
  else
    echo "FAIL  $name"
    fail=$((fail + 1))
    annotate "FAIL: $name" "$(cat "$OUT" 2> /dev/null)"
  fi
}
says() { grep -q -- "$1" "$OUT"; }
lacks() { ! grep -q -- "$1" "$2" 2> /dev/null; }
live_says() { grep -q "$1" "$THEMES/e2e-theme/index.php"; }
current_says() { wp option get git_auto_deploy_current --format=json | grep -q -- "$1"; }
count() { find "$BASE/$1" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' '; }
listing() { find "$1" -mindepth 1 -maxdepth 1 -exec basename {} \; | sort; }
same_list() { listing "$1" | diff -q - "$2" > /dev/null; }

commit() { # commit "<new index.php>" "<commit message>"
  printf '%s\n' "$1" > "$R/wp-content/themes/e2e-theme/index.php"
  git -C "$R" -c user.name=test -c user.email=test@example.com commit -qam "$2"
}

# The workflow's "Lint theme PHP" and "Build theme zip" steps, as written there.
lint_and_build() {
  (
    cd "$R" || exit 1
    THEME_DIR=wp-content/themes/e2e-theme
    THEME_SLUG=e2e-theme
    status=0
    while IFS= read -r -d '' file; do
      "$PHP" -l "$file" > /dev/null 2>&1 || status=1
    done < <(git ls-files -z -- "$THEME_DIR/*.php")
    [ "$status" = 0 ] || exit 1
    tree="${THEME_DIR#./}"
    git archive --format=zip --prefix="$THEME_SLUG/" -o "$WORK/theme.zip" "HEAD:$tree"
  )
}

zip_as() { # zip_as <top folder name> <git revision>
  (cd "$R" && git archive --format=zip --prefix="$1/" -o "$WORK/theme.zip" "$2:wp-content/themes/e2e-theme")
}

send() { (cd "$R" && bash "$SCRIPT" "$@" > "$OUT" 2>&1); }
deploy() { send deploy "$WORK/theme.zip"; }

# The workflow's "Smoke-test live pages" check for one path.
smoke() {
  local code
  code="$(curl -sS -L -o "$WORK/page.html" -w '%{http_code}' --max-time 30 "$GIT_AUTO_DEPLOY_URL$1?git_auto_deploy_smoke=1")"
  [ "$code" = 200 ] && ! grep -q 'There has been a critical error' "$WORK/page.html" && grep -q '</html>' "$WORK/page.html"
}

signed_ping() { # signed_ping <timestamp>: a ping signed by hand, as the API reference describes
  local body_hash sig
  body_hash="$(printf '' | openssl dgst -sha256 | awk '{print $NF}')"
  sig="$(printf '%s\n%s\n%s\n%s\n%s' ping "$1" '' '' "$body_hash" | openssl dgst -sha256 -hmac "$GIT_AUTO_DEPLOY_SECRET" | awk '{print $NF}')"
  curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-GAD-Timestamp: $1" -H 'X-GAD-Commit:' -H 'X-GAD-Subject:' \
    -H "X-GAD-Signature: $sig" "$GIT_AUTO_DEPLOY_URL/?rest_route=/git-auto-deploy/v1/ping"
}

echo "== ping"
send ping
check "ping answers 200" test $? = 0
check "ping reports the active theme" says '"theme": *"e2e-theme"'
check "ping reports the plugin version" says '"plugin": *"1\.0\.0"'

echo "== deploy a new version (theme in a subfolder of the repository)"
commit '<!doctype html><html><body><h1>version two</h1></body></html>' "Version two"
lint_and_build
deploy
check "deploy succeeds" test $? = 0
check "health check passed" says 'Health check passed'
check "live theme is version two" live_says 'version two'
check "first backup is the theme from before Git" grep -rq 'Theme as it was before the first Git deploy' "$BASE/backups"
check "live version records the commit message" current_says 'Version two'
check "smoke test passes" smoke /

echo "== a PHP syntax error stops at the lint, before anything is sent"
commit '<?php echo "broken" ' "Syntax error"
if lint_and_build 2> /dev/null; then check "lint fails" false; else check "lint fails" true; fi
check "live theme is still version two" live_says 'version two'

echo "== a theme that fatals is rolled back by the plugin"
commit '<?php undefined_function_on_purpose(); ?><html></html>' "Fatal on purpose"
lint_and_build
deploy
check "deploy reports failure" test $? != 0
check "response says rolled back" says '"rolled_back": *true'
check "live theme is still version two" live_says 'version two'
check "broken version kept in failed/" test "$(count failed)" = 1
check "smoke test passes after the rollback" smoke /

echo "== a zip with the wrong top folder is refused"
zip_as other-name HEAD
deploy
check "refused with 422" says 'failed with HTTP 422'
check "says which folder it wanted" says 'must sit inside a'
check "live theme untouched" live_says 'version two'

echo "== signatures"
GIT_AUTO_DEPLOY_SECRET=wrong-secret-wrong-secret-wrong-secret send ping
check "wrong secret refused (401)" says 'failed with HTTP 401'
now="$(date +%s)"
check "hand-signed request accepted" test "$(signed_ping "$now")" = 200
check "the same request again is refused (409)" test "$(signed_ping "$now")" = 409
check "10-minute-old timestamp refused (401)" test "$(signed_ping $((now - 600)))" = 401

echo "== rollback from GitHub (what the workflow does when the smoke test fails)"
send rollback
check "rollback succeeds" test $? = 0
check "live theme is version one again" live_says 'version one'
check "replaced version two went to failed/" test "$(count failed)" = 2

echo "== GIT_AUTO_DEPLOY_THEME that is not a plain folder name is refused"
mkdir -p "$MU"
echo "<?php define( 'GIT_AUTO_DEPLOY_THEME', '../plugins' );" > "$MU/test-theme-constant.php"
listing "$THEMES" > "$WORK/themes-before.txt"
listing "$W/wp-content/plugins" > "$WORK/plugins-before.txt"
# Version one, never sent before: the fatal deploy above sent the same commit with HEAD's zip,
# and on a fast machine an identical request in the same second is (rightly) refused as a replay.
zip_as e2e-theme HEAD~3
deploy
check "deploy refused" says 'No valid theme folder name'
check "themes folder untouched" same_list "$THEMES" "$WORK/themes-before.txt"
check "plugins folder untouched" same_list "$W/wp-content/plugins" "$WORK/plugins-before.txt"
send ping
check "ping still works and flags the setting" says 'not a plain folder name'

echo "== GIT_AUTO_DEPLOY_THEME naming a theme that is not active and not there yet"
echo "<?php define( 'GIT_AUTO_DEPLOY_THEME', 'second-theme' );" > "$MU/test-theme-constant.php"
zip_as second-theme HEAD~3
deploy
check "first deploy of a new theme folder succeeds" test $? = 0
check "second-theme folder created" test -f "$THEMES/second-theme/style.css"
check "active theme untouched" live_says 'version one'
rm -f "$MU/test-theme-constant.php"

echo "== back on the active theme: deploy, then roll back from wp-admin"
commit '<!doctype html><html><body><h1>version three</h1></body></html>' "Version three"
lint_and_build
deploy
check "deploy succeeds" test $? = 0
check "live theme is version three" live_says 'version three'
slot="$(find "$BASE/backups" -mindepth 1 -maxdepth 1 -type d -exec basename {} \; | sort | tail -1)"
check "backup is not labelled with the other theme's commit" lacks 'Fatal on purpose' "$BASE/backups/$slot/meta.json"
check "backup records its theme" grep -q '"theme": "e2e-theme"' "$BASE/backups/$slot/meta.json"
wp eval "print_r( Git_Auto_Deploy::rollback( '$slot', 'backups', array( 'source' => 'wp-admin (test)' ) ) );" > "$OUT"
check "wp-admin rollback succeeds" grep -qF '[ok] => 1' "$OUT"
check "live theme is version one" live_says 'version one'
check "replaced version three stays in backups (can switch back)" grep -rq 'Version three' "$BASE/backups"

echo "== admin page"
PAGE="$WORK/admin.html" wp eval '
  wp_set_current_user( 1 );
  require_once ABSPATH . "wp-admin/includes/admin.php";
  require_once WP_PLUGIN_DIR . "/git-auto-deploy/includes/admin.php";
  ob_start();
  git_auto_deploy_admin_page();
  file_put_contents( getenv( "PAGE" ), ob_get_clean() );
' > /dev/null
check "renders" grep -q '<h1>Git Auto Deploy</h1>' "$WORK/admin.html"
check "shows the THEME_SLUG to use" grep -q 'THEME_SLUG: e2e-theme' "$WORK/admin.html"
check "says the theme is the active theme" grep -q '(the active theme)' "$WORK/admin.html"
check "lists earlier versions" grep -q 'Roll back to this' "$WORK/admin.html"
check "shows the activity log" grep -q 'Version two' "$WORK/admin.html"

echo "== PHP warnings"
check "none from the plugin" lacks 'git-auto-deploy' "$W/debug.log"

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
