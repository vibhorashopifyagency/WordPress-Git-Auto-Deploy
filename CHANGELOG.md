# Changelog

Notable changes to Git Auto Deploy. Versions follow [Semantic Versioning](https://semver.org/).

## 1.0.0 — 2026-11-06

First public release.

- Push-to-deploy for one WordPress theme from GitHub Actions. Requests are signed with
  HMAC-SHA256, expire after 5 minutes, and each signature works only once.
- PHP lint on the live server's PHP version before anything is sent.
- The theme is swapped in by renaming folders, with a backup of every replaced version
  (the last 5 by default).
- After every swap the server loads its own home page and puts the previous version back
  if it broke.
- GitHub then loads the pages you choose and asks for a rollback if one fails.
- Tools → Git Auto Deploy in wp-admin: setup steps, server checks, rollback to any kept
  version, deploy from an uploaded zip, and an activity log.
- Deploys the active theme by default; `GIT_AUTO_DEPLOY_THEME` in wp-config.php picks another.
- Purges LiteSpeed Cache after a swap; the `git_auto_deploy_after_swap` action is there for
  other caches.
- End-to-end tests (real deploys and rollbacks on a throwaway WordPress) run in CI.
