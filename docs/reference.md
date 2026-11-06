# Reference

- [Settings](#settings)
- [Hooks for developers](#hooks-for-developers)
- [API (for other CI systems)](#api-for-other-ci-systems)
- [Files and data the plugin keeps](#files-and-data-the-plugin-keeps)
- [Updating the plugin](#updating-the-plugin)
- [Uninstalling](#uninstalling)

## Settings

### In `wp-config.php` (all optional)

| Setting | What it does | Default |
| --- | --- | --- |
| `GIT_AUTO_DEPLOY_THEME` | The theme folder to update. | The active theme (the child theme, if one is active) |
| `GIT_AUTO_DEPLOY_SECRET` | The shared secret, at least 32 characters. Keeps it out of the database. | Created when the plugin is activated, stored in the option `git_auto_deploy_secret` |
| `GIT_AUTO_DEPLOY_KEEP` | How many earlier versions to keep for rollback. | `5` |

```php
define( 'GIT_AUTO_DEPLOY_THEME', 'my-theme' );
define( 'GIT_AUTO_DEPLOY_SECRET', 'paste-64-hex-characters-here' );
define( 'GIT_AUTO_DEPLOY_KEEP', 5 );
```

### In `.github/workflows/deploy-theme.yml`

| Setting | What it does |
| --- | --- |
| `branches`, `paths` | When the workflow starts: pushes to these branches that change these files. |
| `THEME_SLUG` | The theme folder name on the server. Must match the folder the plugin updates. |
| `THEME_DIR` | Where the theme is in the repository, or `.` if the repository is the theme. |
| `SMOKE_PATHS` | Pages checked after every deploy, separated by spaces. |
| `PHP_VERSION` | The PHP version used to check your code. Use your server's version. |
| `concurrency` | `deploy-live`: one deploy at a time, queued, never cancelled half-way. Leave as is. |

### GitHub repository secrets

| Secret | Value |
| --- | --- |
| `GIT_AUTO_DEPLOY_URL` | Your site address, for example `https://example.com` |
| `GIT_AUTO_DEPLOY_SECRET` | The deploy secret from **Tools → Git Auto Deploy** |

## Hooks for developers

Put this code in a small must-use plugin (`wp-content/mu-plugins/`), not in your theme: the
theme is what gets replaced.

**Filter `git_auto_deploy_health_urls`:** the pages the server loads after an update. Default:
the home page.

```php
add_filter( 'git_auto_deploy_health_urls', function ( $urls ) {
    $urls[] = home_url( '/shop/' );
    return $urls;
} );
```

**Action `git_auto_deploy_after_swap`:** runs after every update and rollback. Clear your own
caches here. The plugin itself also runs `do_action( 'litespeed_purge_all' )`, which clears the
LiteSpeed Cache plugin when it's active.

## API (for other CI systems)

GitLab, Bitbucket, Jenkins or any other system can deploy by doing what
[`git-auto-deploy.sh`](../workflow/git-auto-deploy.sh) does.

```text
POST https://example.com/?rest_route=/git-auto-deploy/v1/<action>
(or  /wp-json/git-auto-deploy/v1/<action>  when pretty permalinks are on)
```

**Actions**

| Action | Body |
| --- | --- |
| `deploy` | The theme zip, `Content-Type: application/octet-stream` |
| `rollback` | Empty. Restores the newest backup; the replaced version goes to `failed/`. |
| `ping` | Empty. Returns status and server checks; changes nothing. |

**Headers**

| Header | Value |
| --- | --- |
| `X-GAD-Timestamp` | Unix time in seconds |
| `X-GAD-Commit` | Commit SHA, 7 to 40 hex characters, or empty |
| `X-GAD-Subject` | base64 of the commit's first line, or empty |
| `X-GAD-Signature` | lowercase hex of `HMAC-SHA256(secret, "<action>\n<timestamp>\n<commit>\n<subject>\n<sha256 hex of the body>")` |

**Signing in bash**

```sh
body_hash=$(openssl dgst -sha256 theme.zip | awk '{print $NF}')
sig=$(printf '%s\n%s\n%s\n%s\n%s' deploy "$ts" "$commit" "$subject_b64" "$body_hash" \
      | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')
```

**Response (JSON)**

| Field | Meaning |
| --- | --- |
| `ok` | `true` or `false` |
| `message` | What happened, in plain words |
| `health` | `{ status: ok \| failed \| unverified, summary, checks: [ { url, status, detail } ] }` |
| `backup` | Name of the backup made by this deploy |
| `rolled_back` | `true` when the new theme failed and the previous one went back |
| `restored` | For rollback: the version that is now live |
| `seconds` | Time taken |

`ping` also returns `site`, `plugin`, `theme`, `current`, `backups` and `checks`.

**HTTP status codes**

| Code | Meaning |
| --- | --- |
| 200 | Done. |
| 400 | Upload arrived empty (bigger than `post_max_size`?), not a zip, or bad `X-GAD-Commit`. |
| 401 | Bad signature, or the time is more than 5 minutes off the server clock. |
| 404 | No backup to roll back to; or the route is missing (plugin inactive, wrong address). |
| 409 | Another deploy or rollback is running, or the same signed request was sent twice. |
| 422 | Zip rejected: wrong folder name, unsafe path, symlink, no `style.css`, no Theme Name, no `index.php`. |
| 500 | Server error, or the new theme failed the health check (`"rolled_back": true`). |
| 503 | No secret set on the site. |

## Files and data the plugin keeps

**Folders**

| Path | What |
| --- | --- |
| `wp-content/git-auto-deploy/.htaccess`, `index.php` | Block web access to the folder |
| `wp-content/git-auto-deploy/incoming/` | Unpacking area, emptied at every deploy |
| `wp-content/git-auto-deploy/upload-*.zip` | A zip being received (deleted right after) |
| `wp-content/git-auto-deploy/backups/<slot>/` | Earlier versions: `meta.json` plus the theme folder. The newest 5 (`GIT_AUTO_DEPLOY_KEEP`) are kept. |
| `wp-content/git-auto-deploy/failed/<slot>/` | Versions rolled back as broken. The newest 3 are kept. |

Folder names (`<slot>`) look like `20261106-093012-482113-1a2b3c4`: the date and time in UTC,
microseconds, and the short commit (`manual` for zip uploads). Sorting by name sorts by time.

**Database (in `wp_options`)**

| Option | What |
| --- | --- |
| `git_auto_deploy_secret` | The secret (unless it's set in `wp-config.php`) |
| `git_auto_deploy_current` | The live version: commit, message, source, time, theme |
| `git_auto_deploy_log` | The last 30 deploys and rollbacks |
| `git_auto_deploy_lock` | Exists only while a deploy runs; treated as stale after 15 minutes |
| `_transient_git_auto_deploy_sig_*` | Signatures already used (kept for 15 minutes) |
| `_transient_git_auto_deploy_notice_<user>` | One-off messages on the plugin's page |

## Updating the plugin

1. Download the new `git-auto-deploy.zip` from the
   [latest release](https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy/releases/latest).
2. In WordPress go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, and click
   **Replace current with uploaded**.

Your secret, backups and activity log stay. Check the [changelog](../CHANGELOG.md) in case the
workflow files changed too.

## Uninstalling

1. **Plugins** → deactivate and delete Git Auto Deploy. Your theme stays as it is.
2. Delete the folder `wp-content/git-auto-deploy/` (the backups), for example in cPanel File
   Manager.
3. Optional: remove its settings with WP-CLI:
   `wp option delete git_auto_deploy_secret git_auto_deploy_current git_auto_deploy_log`
4. In your repository, delete `.github/workflows/deploy-theme.yml` (or the two secrets) so
   GitHub stops trying to deploy.
