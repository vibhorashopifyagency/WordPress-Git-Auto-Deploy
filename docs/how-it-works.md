# How it works

- [The two parts](#the-two-parts)
- [What happens on every push, step by step](#what-happens-on-every-push-step-by-step)
- [Why it works this way](#why-it-works-this-way)
- [How it compares with other tools](#how-it-compares-with-other-tools)
- [What it does not do](#what-it-does-not-do)
- [Security](#security)

## The two parts

| Part | Where it lives | What it does |
| --- | --- | --- |
| **The plugin**, Git Auto Deploy | On your WordPress site | Receives the theme, keeps a backup, switches to the new theme, checks the home page, and puts the old version back if something broke. |
| **The workflow** | In your GitHub repository (`.github/`) | After every push: checks the PHP, zips the theme, sends it to the plugin with a signature, then checks your pages. |

## What happens on every push, step by step

```text
You:  git push  (to main)
        |
        v
GITHUB ACTIONS  (deploy-theme.yml)
  1. Check every theme PHP file with your server's PHP version.
       Syntax error?  -> stop here. The live site is not touched. The run turns red.
  2. Zip the theme from the pushed commit (only committed files).
  3. Send the zip to  https://example.com/?rest_route=/git-auto-deploy/v1/deploy
     with the time and a signature made with the shared secret.
        |
        v
YOUR SITE  (Git Auto Deploy plugin)
  4. Check the signature, that the time is within 5 minutes, and that this exact request
     was not used before.
  5. Unpack into wp-content/git-auto-deploy/incoming/ and check the zip: one folder named like
     the theme, no paths that climb out of it, no symlinks, a real style.css and index.php.
  6. Move the live theme folder to  wp-content/git-auto-deploy/backups/<time>-<commit>/
  7. Move the new theme folder into wp-content/themes/<theme>/
     (two folder renames: a split second, no half-copied files)
  8. Clear caches: PHP's opcache for the theme's files, and LiteSpeed Cache if it is active.
  9. Load the home page from the server itself.
       Server error, "There has been a critical error", a PHP fatal error, or a page that
       stops before </html>?  -> put the backup back and answer "rolled back". Run turns red.
        |
        v
GITHUB ACTIONS
 10. Load each page in SMOKE_PATHS from the internet.
       Any page not OK?  -> ask the site to roll back. The previous version goes back.
                            Run turns red.
 11. All good -> run turns green. The new theme is live.
```

Only one deploy runs at a time. If you push twice quickly, the second waits for the first.

## Why it works this way

- **No FTP or SSH passwords in GitHub.** The only shared thing is a deploy secret that can do
  one job: replace this one theme on this one site.
- **Works on cheap shared hosting** with no SSH access. It only needs HTTPS and PHP.
- **Your site never connects to GitHub** and stores no GitHub token, so private repositories
  work without extra setup.
- **The switch is instant.** The theme is replaced by renaming folders, not by uploading files
  one at a time, so visitors never see a half-updated theme.
- **Every replaced version is kept**, with one-click rollback, and automatic rollback when the
  new code breaks the home page.
- **PHP syntax errors are caught on GitHub** before anything reaches your site.

## How it compares with other tools

There are three common ways to deploy a WordPress theme from Git. Each covers part of what this
project does.

**WordPress plugins that pull from Git**, such as [WP Pusher](https://wppusher.com),
[Deployer for Git](https://wordpress.org/plugins/deployer-for-git/) and
[Git Updater](https://github.com/afragen/git-updater). A webhook tells the site that the
repository changed, and the site downloads the code from GitHub, GitLab or Bitbucket. A private
repository means the site stores an access token, and in WP Pusher and Deployer for Git private
repositories need the paid version. Here it works the other way round: GitHub sends a signed
zip, the site never contacts GitHub, and private repositories need nothing extra.

**Copying files over FTP from GitHub Actions**, such as
[FTP-Deploy-Action](https://github.com/SamKirkland/FTP-Deploy-Action). Free and widely used.
The FTP password is stored in GitHub, files are uploaded one at a time, so a visitor can load a
half-updated theme, and no copy of the old version is kept.

**cPanel's built-in Git Version Control**, with a `.cpanel.yml` file
([cPanel's guide](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/)).
It deploys when the copy of the repository on the cPanel server is updated. A push to GitHub
does not reach it by itself: someone clicks "Update from Remote", or you automate that step
yourself. A private GitHub repository needs an SSH key set up on the server.

None of the free tools we looked at does all of the following together:

- checks the PHP on your server's PHP version before anything is sent;
- switches the theme in one step and keeps a backup of every version;
- checks the site twice after the switch (the server loads its own home page, then GitHub loads
  your pages) and puts the previous version back by itself if either fails;
- runs on shared cPanel hosting with nothing but HTTPS and PHP.

Pick one of the plugins above instead if you also want to deploy plugins from Git, not only a
theme.

This comparison was made in November 2026 from each tool's public pages. Features and prices
change, so check a tool's own site before relying on what is said here about it.

## What it does not do

- **It updates one theme folder only.** Not plugins, mu-plugins, uploads, `wp-config.php`,
  `.htaccess`, WordPress itself, or the database. Content and settings you change in WordPress
  are safe.
- **No build step.** It deploys exactly the files you committed. If your theme compiles CSS or
  JS (npm, Sass, webpack), either commit the compiled files, or add a build step to the
  workflow before "Build theme zip" and zip the result with `zip -r` instead of `git archive`.
- **Data your theme needs** (for example default content) must ship inside the theme, with code
  that installs it once, or be entered in WordPress.
- **It doesn't update itself.** To update the plugin, see
  [Updating the plugin](reference.md#updating-the-plugin).
- **Not tested on WordPress multisite.**
- **A split-second gap.** During the switch the theme folder is missing for a split second. A
  visit at exactly that moment can get an error page. The plugin stops WordPress from switching
  to a default theme during that moment.
- **If you change the active theme**, deploys follow the new active theme (unless
  `GIT_AUTO_DEPLOY_THEME` is set). Update `THEME_SLUG` in the workflow to match.

## Security

- **Every request is signed** with HMAC-SHA256 using the shared secret, over the action, the
  time, the commit, the commit message and a fingerprint (SHA-256) of the zip. A wrong secret,
  a changed zip, a signature made for another action, or a time more than 5 minutes off is
  refused (HTTP 401). Each signature works once; the same request sent again is refused (409).
- **Your site never contacts GitHub** and stores no GitHub token.
- **Treat the secret like a password.** It can only deploy or roll back the theme on that one
  site. But a theme is PHP code, so whoever has the secret can run code on your site.
- **To change the secret:** **Tools → Git Auto Deploy → Create a new secret**, then paste it
  into `GIT_AUTO_DEPLOY_SECRET` on GitHub. The old one stops working at once.
- **To keep the secret out of the database**, put it in `wp-config.php` instead (at least 32
  characters; you can make one with `openssl rand -hex 32`):

  ```php
  define( 'GIT_AUTO_DEPLOY_SECRET', 'paste-64-hex-characters-here' );
  ```

  The plugin's page then says "set in wp-config.php".
- **Every zip is checked:** everything inside one folder named like the theme; no `..`,
  absolute paths, backslashes or symlinks; a `style.css` with a `Theme Name:` line; an
  `index.php` (or `templates/index.html` for block themes); at most 10,000 files and 200 MB
  unpacked.
- **The theme folder name must be a plain folder name** (letters, numbers, `.`, `_`, `-`).
  Anything else stops deploys before a file is touched.
- **Backups are hidden from visitors.** `wp-content/git-auto-deploy/` gets an `.htaccess` file
  that blocks web access (Apache and LiteSpeed obey it). On nginx, add:
  `location ^~ /wp-content/git-auto-deploy/ { deny all; }`
- **The plugin's buttons** are for administrators only and use WordPress nonces.
- **Use `https://`** in `GIT_AUTO_DEPLOY_URL`. The signature stops anyone changing a deploy, but
  over plain `http` others on the network could read your theme's code.
- Wrong-secret attempts are not rate-limited. With a 256-bit secret, guessing is not feasible.

Found a security problem? Please report it privately, as [SECURITY.md](../SECURITY.md) explains.
