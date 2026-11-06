# Git Auto Deploy

Deploys your WordPress theme from GitHub on every push, with an automatic rollback if the
new code breaks the site.

Full setup guide, the GitHub Actions workflow and the source:
https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy

## What it does

On every push, a GitHub Actions workflow lints the theme's PHP on your server's PHP version,
zips the theme and sends it to this plugin, signed with a shared secret. The plugin then:

- checks the zip (one folder named like the theme, no unsafe paths, no symlinks, a real `style.css`);
- moves the live theme into `wp-content/git-auto-deploy/backups/` and the new one into place;
- loads the home page, and puts the previous version back by itself if the page broke.

GitHub then loads the pages you list and asks for a rollback if one of them fails.
Only theme files change. The database is never touched.

## Quick setup

1. Install and activate this plugin, then open **Tools → Git Auto Deploy**.
2. In your GitHub repository add two Actions secrets: `GIT_AUTO_DEPLOY_URL` (your site
   address) and `GIT_AUTO_DEPLOY_SECRET` (shown on the plugin's page).
3. Add `deploy-theme.yml` and `git-auto-deploy.sh` from the repository above to your
   repository's `.github/` folder, and set the values marked CHANGE.
4. Push.

## Settings (optional, in wp-config.php)

```php
define( 'GIT_AUTO_DEPLOY_THEME', 'my-theme' );    // deploy a theme other than the active one
define( 'GIT_AUTO_DEPLOY_SECRET', '…64 hex…' );   // keep the secret out of the database
define( 'GIT_AUTO_DEPLOY_KEEP', 5 );              // earlier versions kept for rollback
```

License: GPL-2.0-or-later. © 2026 Vibhora Inc.
