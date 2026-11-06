#!/usr/bin/env bash
# Sends a signed request to the Git Auto Deploy plugin on the live site.
#
#   git-auto-deploy.sh deploy <theme.zip>   upload a theme zip and swap it in
#   git-auto-deploy.sh rollback             put the previous theme version back
#   git-auto-deploy.sh ping                 check that the URL and secret work
#
# Needs two environment variables (GitHub Actions passes them from repository secrets):
#   GIT_AUTO_DEPLOY_URL     the site address exactly as WordPress uses it, e.g. https://your-site.com
#   GIT_AUTO_DEPLOY_SECRET  the secret shown in wp-admin → Tools → Git Auto Deploy
#
# Works with macOS bash 3.2 too, so it can be run from a laptop.
# Part of WordPress Git Auto Deploy: https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy
set -euo pipefail

action="${1:-}"
case "$action" in
  deploy | rollback | ping) ;;
  *)
    echo "usage: $0 deploy <theme.zip> | rollback | ping" >&2
    exit 2
    ;;
esac
: "${GIT_AUTO_DEPLOY_URL:?Set GIT_AUTO_DEPLOY_URL, e.g. https://your-site.com}"
: "${GIT_AUTO_DEPLOY_SECRET:?Set GIT_AUTO_DEPLOY_SECRET (wp-admin → Tools → Git Auto Deploy)}"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

body="$tmp/empty"
: > "$body"
if [ "$action" = deploy ]; then
  body="${2:?usage: $0 deploy <theme.zip>}"
  [ -s "$body" ] || { echo "No zip at $body" >&2; exit 2; }
fi

commit="${GITHUB_SHA:-$(git rev-parse HEAD 2>/dev/null || true)}"
subject=""
if [ -n "$commit" ]; then
  subject="$(git log -1 --format=%s "$commit" 2>/dev/null || true)"
fi
subject_b64="$(printf '%s' "$subject" | base64 | tr -d '\n')"
timestamp="$(date +%s)"
body_hash="$(openssl dgst -sha256 "$body" | awk '{print $NF}')"
signature="$(printf '%s\n%s\n%s\n%s\n%s' "$action" "$timestamp" "$commit" "$subject_b64" "$body_hash" \
  | openssl dgst -sha256 -hmac "$GIT_AUTO_DEPLOY_SECRET" | awk '{print $NF}')"

url="${GIT_AUTO_DEPLOY_URL%/}/?rest_route=/git-auto-deploy/v1/$action"
label="$action"
if [ -n "$commit" ]; then label="$label ${commit:0:7}"; fi
if [ -n "$subject" ]; then label="$label \"${subject}\""; fi
echo "-> $label to ${GIT_AUTO_DEPLOY_URL%/}"

code="$(curl -sS -o "$tmp/response" -w '%{http_code}' --max-time 300 \
  -X POST \
  -H 'Content-Type: application/octet-stream' \
  -H 'Expect:' \
  -H "X-GAD-Timestamp: $timestamp" \
  -H "X-GAD-Commit: $commit" \
  -H "X-GAD-Subject: $subject_b64" \
  -H "X-GAD-Signature: $signature" \
  --data-binary @"$body" \
  "$url")" || code="000"

if command -v jq > /dev/null 2>&1 && jq . "$tmp/response" > /dev/null 2>&1; then
  jq . "$tmp/response"
else
  cat "$tmp/response" 2> /dev/null || true
  echo
fi

case "$code" in
  200) exit 0 ;;
  000) echo "::error::Could not reach $url" ;;
  301 | 302 | 307 | 308) echo "::error::$url redirected ($code). Set GIT_AUTO_DEPLOY_URL to the exact site address (https, with or without www as the site uses)." ;;
  404) echo "::error::$url returned 404. Is the Git Auto Deploy plugin installed and active?" ;;
  *) echo "::error::$action failed with HTTP $code" ;;
esac
exit 1
