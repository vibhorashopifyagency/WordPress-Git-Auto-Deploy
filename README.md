# WordPress Git Auto Deploy

[![CI](https://github.com/vibhorashopifyagency/wordpressgitautodeploy/actions/workflows/ci.yml/badge.svg)](https://github.com/vibhorashopifyagency/wordpressgitautodeploy/actions/workflows/ci.yml)

**Push to GitHub, and the theme on your live WordPress site updates itself. If the new code
breaks the site, the previous version goes back automatically.**

Built for cheap shared cPanel hosting: no FTP, no SSH, no GitHub token on the server.
Free and open source (GPL-2.0-or-later).

|              |                                                                               |
| ------------ | ----------------------------------------------------------------------------- |
| Plugin       | Git Auto Deploy 1.0.0 (`git-auto-deploy`)                                     |
| Workflow     | GitHub Actions, "Deploy theme" (`deploy-theme.yml`)                           |
| Needs        | WordPress 6.4+, PHP 8.0+ with the zip extension, HTTPS                        |
| Hosting      | Any host where PHP can write to `wp-content`; built for cPanel shared hosting |
| Tested       | End-to-end on the latest WordPress with PHP 8.2 in CI, lint on PHP 8.0–8.4; before release, used on a live cPanel + LiteSpeed + PHP 8.2 site |

## Contents

- [What it does](#what-it-does)
- [How it works](#how-it-works)
- [Why this approach, and how it compares with other tools](#why-this-approach-and-how-it-compares-with-other-tools)
- [Requirements](#requirements)
- [Setup, step by step](#setup-step-by-step)
- [Everyday use](#everyday-use)
- [Rolling back](#rolling-back)
- [Deploying without GitHub](#deploying-without-github)
- [The admin page](#the-admin-page)
- [What it does not do](#what-it-does-not-do)
- [Security](#security)
- [Configuration reference](#configuration-reference)
- [API reference](#api-reference)
- [Files and data the plugin keeps](#files-and-data-the-plugin-keeps)
- [Troubleshooting](#troubleshooting)
- [Updating and uninstalling](#updating-and-uninstalling)
- [How it is tested](#how-it-is-tested)
- [Contributing](#contributing)
- [License](#license)

## What it does

Two parts work together:

- **A WordPress plugin, Git Auto Deploy**, installed on the live site. It receives a theme zip,
  keeps a backup of the theme that is live, swaps the new one in, loads the home page to check
  nothing broke, and puts the previous version back by itself if something did.
- **A GitHub Actions workflow** in your repository. On every push to your live branch it checks
  the theme's PHP for syntax errors, zips the theme, sends it to the plugin over HTTPS with a
  signature, then loads a few live pages and asks for a rollback if one of them fails.

You edit the theme, commit, push, and about a minute later it is live. No zipping, no cPanel
File Manager, no FTP. Only theme files are ever changed. The database (posts, pages, orders,
settings) is never touched.

### What is in this repository

| Path                                        | What it is                                                      |
| ------------------------------------------- | --------------------------------------------------------------- |
| `git-auto-deploy/`                          | The plugin (download it ready-zipped from [Releases])           |
| `github-actions/workflows/deploy-theme.yml` | The workflow: copy to `.github/workflows/` in *your* repository |
| `github-actions/scripts/git-auto-deploy.sh` | Signs and sends: copy to `.github/scripts/` in *your* repository |
| `tests/`                                    | End-to-end tests (see [How it is tested](#how-it-is-tested))    |
| `.github/workflows/`                        | This repository's own CI and release workflows                  |

The deploy workflow sits in `github-actions/`, not `.github/`, on purpose: in `.github/` it
would try to deploy from this repository too.

[Releases]: https://github.com/vibhorashopifyagency/wordpressgitautodeploy/releases/latest

## How it works

```text
You:  git push  (to main)
        |
        v
GITHUB ACTIONS  (deploy-theme.yml)
  1. Lint every theme PHP file with the live server's PHP version.
       Syntax error?  -> stop here. The live site is not touched. The run turns red.
  2. Zip the theme from the pushed commit (git archive: committed files only).
  3. POST the zip to  https://your-site.com/?rest_route=/git-auto-deploy/v1/deploy
     with a timestamp and an HMAC-SHA256 signature made with the shared secret.
        |
        v
YOUR SITE  (Git Auto Deploy plugin)
  4. Check the signature, the timestamp (within 5 minutes) and that this exact request
     has not been used before.
  5. Unpack into wp-content/git-auto-deploy/incoming/ and check the zip: one folder named like
     the theme, no paths that climb out of it, no symlinks, a real style.css and index.php.
  6. Move the live theme folder to  wp-content/git-auto-deploy/backups/<time>-<commit>/
  7. Move the new theme folder into wp-content/themes/<theme>/
     (two folder renames: a split second, no half-copied files)
  8. Clear caches: PHP's opcache for the theme's files, and LiteSpeed Cache if it is active.
  9. Load the home page from the server itself.
       5xx error, "There has been a critical error", a visible PHP fatal error, or a page that
       stops before </html>?  -> put the backup back and answer "rolled back". Run turns red.
        |
        v
GITHUB ACTIONS
 10. Load each smoke-test page from the internet.
       Any page not 200, or broken?  -> call /rollback: the previous version goes back.
                                        Run turns red.
 11. All good -> run turns green. The new theme is live.
```

One deploy runs at a time. If you push twice quickly, the second waits for the first.

## Why this approach, and how it compares with other tools

- **No FTP or SSH passwords in GitHub.** The only shared thing is a deploy secret that can do
  one job: replace this one theme on this one site.
- **Works on cheap shared hosting with no SSH access.** It only needs HTTPS and PHP.
- **The server never connects to GitHub** and holds no GitHub token, so private repositories
  work without extra setup.
- **Atomic swap:** the theme is replaced by renaming folders, not by uploading files one at a
  time, so visitors never see a half-updated theme. FTP deploys copy file by file.
- **A backup of every replaced version**, one-click rollback, and an automatic rollback when
  the new code breaks the home page.
- **PHP syntax errors are caught on GitHub** before anything reaches the server.

### Other ways to deploy a WordPress theme from Git

There are three common routes. Each one covers part of what this project does.

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

- checks the PHP on the live server's version before anything is sent;
- swaps the theme in one step and keeps a backup of every version;
- checks the site twice after the swap (the server loads its own home page, then GitHub loads
  the smoke-test pages) and puts the previous version back by itself if either fails;
- runs on shared cPanel hosting with nothing but HTTPS and PHP.

Pick one of the plugins above instead if you also want to deploy plugins from Git, not only a
theme. This project deploys one theme folder ([What it does not do](#what-it-does-not-do)).

This comparison was made in November 2026 from each tool's public pages. Features and prices
change, so check a tool's own site before relying on what is said here about it.

## Requirements

**The live site**

- WordPress 6.4 or newer.
- PHP 8.0 or newer with the `zip` extension (ZipArchive). cPanel hosts have it; if not:
  cPanel → Select PHP Version → Extensions → zip.
- PHP must be allowed to rename folders inside `wp-content/themes/` and to create
  `wp-content/git-auto-deploy/`. This is normal on cPanel, where PHP runs as your own account
  user (LiteSpeed LSAPI, PHP-FPM, suPHP). The plugin's server checks tell you.
- The WordPress REST API must be reachable (it is by default). Pretty permalinks are not
  needed: the endpoints are called as `/?rest_route=...`
- PHP's `post_max_size` must be bigger than your theme zip. The default 8M is plenty for a
  typical 3 MB theme. The plugin shows the value.
- HTTPS, strongly recommended.

**GitHub**

- A repository that contains the theme, either in a subfolder (for example the whole WordPress
  site, with the theme in `wp-content/themes/<theme>/`) or as the whole repository. Public or
  private both work.
- GitHub Actions turned on (it is by default). Public repositories run free; private ones get
  free minutes every month, and one deploy takes about a minute.

**Your computer**

- Nothing special. Optional deploys from a laptop need bash, curl, openssl and git (all on a Mac).

## Setup, step by step

### Step 1. Install the plugin on the live site

1. Download `git-auto-deploy.zip` from the [latest release][Releases].
2. wp-admin → Plugins → Add New Plugin → Upload Plugin → choose `git-auto-deploy.zip` →
   Install Now → Activate Plugin.
   (Or: cPanel → File Manager → `public_html/wp-content/plugins/` → Upload the zip → Extract,
   then activate it in wp-admin → Plugins.)

Activating creates a 64-character deploy secret and the folder `wp-content/git-auto-deploy/`.

### Step 2. Check which theme it will replace

Open **Tools → Git Auto Deploy**. Under "Server checks", "Theme folder" shows the folder that
deploys replace: the active theme, or the child theme if one is active.

To deploy a theme that is not the active one, add this line to `wp-config.php`
(cPanel → File Manager → `public_html` → `wp-config.php` → Edit), above
`/* That's all, stop editing! */`:

```php
define( 'GIT_AUTO_DEPLOY_THEME', 'your-theme-folder' );
```

Use the folder name, as in `wp-content/themes/<folder>`, not the theme's display name.

### Step 3. Check the server

Every row under "Server checks" with a mark should show a green tick. If one shows a red cross,
see [Troubleshooting](#troubleshooting).

Then click **Run the health check now**. It should say "Health check passed". If it says
"skipped", the server cannot load its own pages; deploys still work, but only GitHub's smoke
test can catch a broken deploy (see [Troubleshooting](#troubleshooting)).

### Step 4. Add two secrets to GitHub

Repository → Settings → Secrets and variables → Actions → New repository secret. Add both
(names are case-sensitive):

| Name                     | Value                                                                                                                                                                          |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GIT_AUTO_DEPLOY_URL`    | The site address exactly as WordPress uses it, from wp-admin → Settings → General → Site Address (URL). Same http/https and same www or no www, or the request is redirected and fails. |
| `GIT_AUTO_DEPLOY_SECRET` | Tools → Git Auto Deploy → Connect GitHub → Show secret. Copy all 64 characters, with no space or line break.                                                                    |

GitHub stores these encrypted and hides them in workflow logs.

### Step 5. Add the workflow to your repository

Copy two files into your repository, keeping these exact paths. From the root of your
repository:

```sh
mkdir -p .github/workflows .github/scripts
curl -fsSL -o .github/workflows/deploy-theme.yml \
  https://raw.githubusercontent.com/vibhorashopifyagency/wordpressgitautodeploy/v1.0.0/github-actions/workflows/deploy-theme.yml
curl -fsSL -o .github/scripts/git-auto-deploy.sh \
  https://raw.githubusercontent.com/vibhorashopifyagency/wordpressgitautodeploy/v1.0.0/github-actions/scripts/git-auto-deploy.sh
```

Then edit the lines marked `CHANGE` in `deploy-theme.yml`:

| Setting       | What to put                                                                                                                                                       |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `branches`    | The branch that goes live. Usually `main`.                                                                                                                        |
| `paths`       | `THEME_DIR` followed by `/**`. The workflow only runs when these files change. If the repository is the theme, use `'**'`.                                         |
| `THEME_SLUG`  | The theme's folder name on the server, as shown in Tools → Git Auto Deploy.                                                                                        |
| `THEME_DIR`   | Where the theme sits in the repository, e.g. `wp-content/themes/my-theme`. Use `.` if the repository is the theme.                                                 |
| `SMOKE_PATHS` | Pages that must load after every deploy, separated by spaces, e.g. `/ /about/ /contact/`. Only list pages whose address will not change: a page that later moves or 404s makes every deploy roll back. |
| `PHP_VERSION` | The live server's PHP version (cPanel → MultiPHP Manager), e.g. `'8.2'`.                                                                                           |

If the repository **is** the theme, add a file named `.gitattributes` at its root so files
that are only for the repository stay out of the zip:

```text
.github export-ignore
.gitattributes export-ignore
README.md export-ignore
```

Add any other file or folder the server does not need, such as `node_modules`, `tests`, `src`.

If the repository holds the **whole WordPress site**, also add this line to `.gitignore` so a
local copy of the plugin's backups never gets committed:

```text
wp-content/git-auto-deploy/
```

### Step 6. First deploy

Commit and push the `.github` folder. The workflow only starts by itself when theme files
change, so start the first run by hand: Repository → Actions → Deploy theme → Run workflow →
(branch: main) → Run workflow.

Click the run to watch it. The steps are: Lint theme PHP, Check deploy secrets, Build theme
zip, Deploy to live site, Smoke-test live pages. Green means it is live.

The first deploy keeps the theme that was already on the server as a backup named
"Theme as it was before the first Git deploy".

### Step 7. Confirm

Tools → Git Auto Deploy → "Live theme" now shows the commit, for example
`5ce1327 Test successful for auto deploy`, and "Activity" shows a green tick with
"Deployed. Health check passed (/ HTTP 200)."

Done. From now on, every push to `main` that changes the theme goes live.

## Everyday use

- Edit the theme, commit, push to `main`. It is live in about a minute.
- Everything pushed to `main` goes live. Do unfinished work on another branch and merge it
  into `main` when it is ready.
- Deploy again without changes: Actions → Deploy theme → Run workflow.
- Several pushes in a row are queued and deployed one at a time, in order.
- Where to look: the Actions tab (a log for every run) and Tools → Git Auto Deploy → Activity
  (the last 30 deploys and rollbacks).
- GitHub emails you when a run fails. Settings for that: your GitHub avatar → Settings →
  Notifications → Actions.

## Rolling back

**Automatic (nothing to do)**

- Right after the swap the server loads its home page. If it answers 5xx, shows "There has
  been a critical error", shows a PHP "Fatal error" or "Parse error", or stops before
  `</html>`, the previous version goes back before the request even returns. The run fails
  with: "The new theme broke the site, so the previous version was put back."
- Any unexpected error after the swap also puts the previous version back.
- If GitHub's smoke test then finds a broken page, the workflow calls `/rollback` and the
  previous version goes back.

The broken version is moved to `wp-content/git-auto-deploy/failed/` (the newest 3 are kept) so
you can look at it. It is never offered as a rollback target.

**By hand in wp-admin**

Tools → Git Auto Deploy → Earlier versions → "Roll back to this". The version you replace is
added to the list, so you can switch back the same way.

**From a laptop**

```sh
GIT_AUTO_DEPLOY_URL=https://your-site.com GIT_AUTO_DEPLOY_SECRET=<secret> \
  bash .github/scripts/git-auto-deploy.sh rollback
```

This restores the newest backup.

**Last resort, if wp-admin will not load (cPanel File Manager)**

1. Open `public_html/wp-content/git-auto-deploy/backups/`. Each folder is one earlier version,
   named `<date>-<time>-<microseconds>-<commit>`; the newest has the highest number. Its
   `meta.json` says which commit it is.
2. Rename `public_html/wp-content/themes/<theme>` to `<theme>-broken`.
3. Move `backups/<that folder>/<theme>` into `public_html/wp-content/themes/`.

The "Live theme" panel does not know about moves made by hand until the next deploy.

> **Important:** a rollback only changes the files on the server. Git still has the bad
> commit, and the next push deploys `main` again, bad commit included. Fix it, or undo it in
> Git (`git revert <commit>`), and push.

## Deploying without GitHub

**Upload a zip in wp-admin.** Tools → Git Auto Deploy → "Upload a theme zip". The zip must
contain one folder named exactly like the theme folder. It gets the same checks, backup, health
check and automatic rollback. A zip made with the Mac Finder's "Compress" works (the Mac's
hidden extra files are ignored). The largest size allowed is shown under Server checks as
"Largest zip upload here".

**From a laptop** (same path as GitHub, without the lint and smoke test):

```sh
git archive --format=zip --prefix=your-theme/ -o theme.zip HEAD:wp-content/themes/your-theme
GIT_AUTO_DEPLOY_URL=https://your-site.com GIT_AUTO_DEPLOY_SECRET=<secret> \
  bash .github/scripts/git-auto-deploy.sh deploy theme.zip
```

`git archive` packs the last commit, not edits you have not committed yet.

**Check the connection:**

```sh
GIT_AUTO_DEPLOY_URL=https://your-site.com GIT_AUTO_DEPLOY_SECRET=<secret> \
  bash .github/scripts/git-auto-deploy.sh ping
```

It answers with the live version, the number of backups and the server checks. It changes
nothing.

## The admin page

Tools → Git Auto Deploy. Only administrators (users who can manage options) see it.

| Section          | What it shows                                                                                                                                         |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Live theme       | The commit and message that is live, where it came from (GitHub Actions, wp-admin upload, rollback) and when, plus the last thing that happened.       |
| Connect GitHub   | The two secrets to add on GitHub, the site URL and `THEME_SLUG` to use, the secret behind "Show secret", and "Create a new secret" (old deploys stop working until GitHub has the new one). |
| Server checks    | Secret set, PHP zip extension, theme folder, themes folder writable, backup folder writable, `post_max_size`, largest upload. Plus "Run the health check now", which loads the home page the way a deploy does. |
| Earlier versions | The kept backups with "Roll back to this", and a note listing versions that were rolled back as broken.                                               |
| Upload a theme   | Deploy from a zip ([Deploying without GitHub](#deploying-without-github)).                                                                            |
| Activity         | The last 30 deploys and rollbacks: when, from where, which commit, result, time taken. ✅ done, ↩️ rolled back, ❌ failed.                               |

## What it does not do

- It deploys one theme folder. Not plugins, mu-plugins, uploads, `wp-config.php`,
  `.htaccess`, WordPress itself, or the database. Content and settings changed in wp-admin are
  safe.
- Data the theme needs (for example default content) must ship inside the theme, with code
  that installs it once, or be entered in wp-admin.
- No build step: it deploys exactly the committed files. If your theme compiles CSS or JS
  (npm, Sass, webpack), either commit the compiled files, or add a build step before
  "Build theme zip" and zip the build output with `zip -r` instead of `git archive`.
- It does not update itself. To update the plugin, upload the new zip in Plugins → Add New →
  Upload Plugin; WordPress offers "Replace current with uploaded".
- Not tested on WordPress multisite.
- During the swap the theme folder is missing for a split second (two folder renames). A visit
  that lands in exactly that moment can get an error page. The plugin stops WordPress from
  switching to a default theme during that moment.
- If you switch the active theme, deploys follow the new active theme (unless
  `GIT_AUTO_DEPLOY_THEME` is set). Update `THEME_SLUG` in the workflow to match.

## Security

- Every request is signed with HMAC-SHA256 using the shared secret, over the action name, a
  timestamp, the commit, the commit message and the SHA-256 of the zip. A wrong secret, an
  altered zip, a signature made for another action, or a timestamp more than 5 minutes off is
  refused (401). Each signature works once; sending the same request again is refused (409).
- The server never contacts GitHub and stores no GitHub token.
- The secret can only deploy or roll back the theme on that one site. **But a theme is PHP
  code, so whoever has the secret can run code on your site. Treat it like a password.**
- To change the secret: Tools → Git Auto Deploy → "Create a new secret", then paste it into
  `GIT_AUTO_DEPLOY_SECRET` on GitHub. The old one stops working at once.
- To keep the secret out of the database, put it in `wp-config.php` instead (at least 32
  characters; make one with `openssl rand -hex 32`):

  ```php
  define( 'GIT_AUTO_DEPLOY_SECRET', 'paste-64-hex-characters-here' );
  ```

  The admin page then says "set in wp-config.php".
- Zip checks: everything inside one folder named like the theme; no `..`, absolute paths,
  backslashes or symlinks; `style.css` with a `Theme Name:` line; `index.php` (or
  `templates/index.html` for block themes); at most 10,000 files and 200 MB unpacked.
- The theme folder name must be a plain folder name (letters, digits, `.`, `_`, `-`);
  anything else stops deploys before a file is touched.
- `wp-content/git-auto-deploy/` gets an `.htaccess` that blocks web access (Apache and
  LiteSpeed obey it). On nginx add: `location ^~ /wp-content/git-auto-deploy/ { deny all; }`
- The admin page's buttons are for administrators only and use WordPress nonces.
- Use `https://` in `GIT_AUTO_DEPLOY_URL`. The signature stops anyone changing a deploy, but
  over plain http others on the network could read the theme's code.
- Wrong-secret attempts are not rate-limited. With a 256-bit secret, guessing is not feasible.

Found a security problem? See [SECURITY.md](SECURITY.md).

## Configuration reference

**wp-config.php** (all optional)

| Constant                 | Meaning                                | Default                                                        |
| ------------------------ | -------------------------------------- | -------------------------------------------------------------- |
| `GIT_AUTO_DEPLOY_THEME`  | Theme folder to replace                | The active theme (the child theme, if one is active)           |
| `GIT_AUTO_DEPLOY_SECRET` | Shared secret, 32+ characters          | Generated on activation, stored in the option `git_auto_deploy_secret` |
| `GIT_AUTO_DEPLOY_KEEP`   | Earlier versions kept for rollback     | 5                                                              |

**deploy-theme.yml**

| Setting            | Meaning                                                              |
| ------------------ | -------------------------------------------------------------------- |
| `branches`, `paths`| When the workflow runs ([Step 5](#step-5-add-the-workflow-to-your-repository)) |
| `THEME_SLUG`       | Folder name on the server, as shown in Tools → Git Auto Deploy        |
| `THEME_DIR`        | Folder in the repository, or `.` for the repository root             |
| `SMOKE_PATHS`      | Pages checked after each deploy                                      |
| `PHP_VERSION`      | PHP version used for the lint                                        |
| `concurrency`      | `deploy-live`: one deploy at a time, queued, never cancelled mid-swap |

**GitHub repository secrets:** `GIT_AUTO_DEPLOY_URL` (site address, e.g.
`https://your-site.com`) and `GIT_AUTO_DEPLOY_SECRET` (the deploy secret).

**Hooks for developers.** Put this code in a small mu-plugin, not in the theme: the theme is
what gets replaced.

- Filter `git_auto_deploy_health_urls`: pages the server loads after a swap. Default: the home
  page.

  ```php
  add_filter( 'git_auto_deploy_health_urls', function ( $urls ) {
      $urls[] = home_url( '/shop/' );
      return $urls;
  } );
  ```

- Action `git_auto_deploy_after_swap`: runs after every swap and rollback. Clear your own
  caches here. The plugin also fires `do_action( 'litespeed_purge_all' )`, which purges the
  LiteSpeed Cache plugin when it is active.

## API reference

Any CI system (GitLab, Bitbucket, Jenkins) can deploy by doing what `git-auto-deploy.sh` does.

```text
POST https://your-site.com/?rest_route=/git-auto-deploy/v1/<action>
(or  /wp-json/git-auto-deploy/v1/<action>  when pretty permalinks are on)
```

**Actions**

| Action     | Body                                                                         |
| ---------- | ---------------------------------------------------------------------------- |
| `deploy`   | The theme zip, `Content-Type: application/octet-stream`                      |
| `rollback` | Empty. Restores the newest backup; the replaced version goes to `failed/`.   |
| `ping`     | Empty. Returns status and server checks; changes nothing.                    |

**Headers**

| Header            | Value                                                                                   |
| ----------------- | --------------------------------------------------------------------------------------- |
| `X-GAD-Timestamp` | Unix time in seconds                                                                    |
| `X-GAD-Commit`    | Commit SHA, 7 to 40 hex characters, or empty                                            |
| `X-GAD-Subject`   | base64 of the commit's first line, or empty                                             |
| `X-GAD-Signature` | lowercase hex of `HMAC-SHA256(secret, "<action>\n<timestamp>\n<commit>\n<subject>\n<sha256 hex of the body>")` |

**Signing in bash**

```sh
body_hash=$(openssl dgst -sha256 theme.zip | awk '{print $NF}')
sig=$(printf '%s\n%s\n%s\n%s\n%s' deploy "$ts" "$commit" "$subject_b64" "$body_hash" \
      | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')
```

**Response (JSON)**

| Field         | Meaning                                                                      |
| ------------- | ---------------------------------------------------------------------------- |
| `ok`          | `true` or `false`                                                            |
| `message`     | What happened, in plain words                                                |
| `health`      | `{ status: ok \| failed \| unverified, summary, checks: [ { url, status, detail } ] }` |
| `backup`      | Name of the backup made by this deploy                                       |
| `rolled_back` | `true` when the new theme failed and the previous one went back              |
| `restored`    | For rollback: the version that is now live                                   |
| `seconds`     | Time taken                                                                   |

`ping` also returns `site`, `plugin`, `theme`, `current`, `backups` and `checks`.

**HTTP status**

| Code | Meaning                                                                                              |
| ---- | ---------------------------------------------------------------------------------------------------- |
| 200  | Done.                                                                                                |
| 400  | Upload arrived empty (bigger than `post_max_size`?), not a zip, or bad `X-GAD-Commit`.               |
| 401  | Bad signature, or the timestamp is more than 5 minutes off the server clock.                        |
| 404  | No backup to roll back to; or the route is missing (plugin inactive, wrong URL).                     |
| 409  | Another deploy or rollback is running, or the same signed request was sent twice.                   |
| 422  | Zip rejected: wrong folder name, unsafe path, symlink, no `style.css`, no Theme Name, no `index.php`. |
| 500  | Server error, or the new theme failed the health check (`"rolled_back": true`).                     |
| 503  | No secret set on the site.                                                                           |

## Files and data the plugin keeps

| Path                                              | What                                                                     |
| ------------------------------------------------- | ------------------------------------------------------------------------ |
| `wp-content/git-auto-deploy/.htaccess`, `index.php` | Block web access                                                       |
| `wp-content/git-auto-deploy/incoming/`            | Unpacking area, emptied at every deploy                                  |
| `wp-content/git-auto-deploy/upload-*.zip`         | A zip being received (deleted right after)                               |
| `wp-content/git-auto-deploy/backups/<slot>/`      | Earlier versions: `meta.json` + the theme folder. The newest `GIT_AUTO_DEPLOY_KEEP` (5) are kept. |
| `wp-content/git-auto-deploy/failed/<slot>/`       | Versions rolled back as broken. Newest 3 kept.                           |

Slot names look like `20261106-093012-482113-1a2b3c4`: UTC date, time, microseconds and the
short commit (`manual` for zip uploads). Sorting by name is sorting by time.

**Database (wp_options)**

| Option                                     | What                                                       |
| ------------------------------------------ | ---------------------------------------------------------- |
| `git_auto_deploy_secret`                   | The secret (unless it is set in `wp-config.php`)           |
| `git_auto_deploy_current`                  | The live version: commit, message, source, time, theme     |
| `git_auto_deploy_log`                      | The last 30 deploys and rollbacks                          |
| `git_auto_deploy_lock`                     | Exists only while a deploy runs; treated as stale after 15 minutes |
| `_transient_git_auto_deploy_sig_*`         | Signatures already used (kept 15 minutes)                  |
| `_transient_git_auto_deploy_notice_<user>` | One-off messages on the admin page                         |

## Troubleshooting

**The workflow did not run after a push.**
The push did not change files under `paths`, or went to another branch than `branches`.
Run it by hand (Actions → Deploy theme → Run workflow) or fix those two lines.

**"Nothing was deployed: add the GIT_AUTO_DEPLOY_URL and GIT_AUTO_DEPLOY_SECRET repository secrets."**
A secret is missing or misspelt. They belong under Settings → Secrets and variables → Actions →
Repository secrets (not Environments, not Dependabot).

**"Lint theme PHP" failed.**
A PHP syntax error; the log names the file and line. Or the code needs a newer PHP than
`PHP_VERSION`. Fix and push again. The live site was not touched.

**HTTP 404 "Is the Git Auto Deploy plugin installed and active?"**
The plugin is not active, `GIT_AUTO_DEPLOY_URL` is wrong, or a security plugin blocks the REST
API. Activate it, check the URL, or allow `/git-auto-deploy/v1/` in the security plugin.

**HTTP 301 / 302 "redirected".**
`GIT_AUTO_DEPLOY_URL` is not the exact site address (http instead of https, www instead of no
www). Use the address from wp-admin → Settings → General.

**HTTP 401 "Bad signature".**
The secret on GitHub is not the one on the site: a space or line break got pasted, or a new
secret was created. Copy it again from Tools → Git Auto Deploy.

**HTTP 401 "timestamp ... more than 5 minutes from the server clock".**
The server's clock is wrong. Ask the host to fix it (NTP).

**HTTP 409 "Another deploy or rollback is running".**
One is in progress, or one crashed less than 15 minutes ago. Wait, then run again.

**HTTP 409 "This signed request was already used".**
The same request arrived twice (a proxy retried it). Run the workflow again.

**HTTP 400 "The upload arrived empty. Is the zip bigger than post_max_size?"**
cPanel → MultiPHP INI Editor → choose the domain → raise `post_max_size` and
`upload_max_filesize` (e.g. 64M) → Apply.

**HTTP 403, or an HTML error page instead of JSON.**
A firewall (ModSecurity, Imunify360, Cloudflare) blocked the upload. Ask the host to whitelist
requests to `/?rest_route=/git-auto-deploy/v1/`. With Cloudflare, look under Security → Events
and add a skip rule for that path.

**HTTP 422 "Everything in the zip must sit inside a "x" folder".**
`THEME_SLUG` in the workflow is not the theme folder the site deploys to. Make it match the
folder shown in Tools → Git Auto Deploy (or set `GIT_AUTO_DEPLOY_THEME`).

**HTTP 500 "No valid theme folder name".**
`GIT_AUTO_DEPLOY_THEME` in `wp-config.php` is not a plain folder name. Use the name exactly as
in `wp-content/themes/<folder>`.

**HTTP 500 "Could not move the live theme aside" (or "... the new theme into place").**
PHP cannot rename folders in `wp-content/themes/`. Folders should be 755 and files 644, owned by
your cPanel user. If PHP runs as "nobody", ask the host for LSAPI or PHP-FPM.

**"Health check skipped (not reachable from the server ...)" or "(HTTP 401/403 ...)".**
The host blocks the server from loading its own site, or the site is behind a password,
maintenance mode or a firewall. The deploy still went through; GitHub's smoke test is now the
only check. Ask the host to allow the server to reach its own domain.

**The smoke test fails but the site looks fine.**
A page in `SMOKE_PATHS` moved or was deleted. Update `SMOKE_PATHS`.

**A green deploy, but the old design still shows.**
A page cache (LiteSpeed, Cloudflare, a cache plugin) or the browser cache. The plugin purges
the LiteSpeed Cache plugin by itself. Purge other caches, or hook `git_auto_deploy_after_swap`.
For CSS and JS, give files a version that changes when the file changes (e.g. `filemtime()` in
`wp_enqueue_style`), so browsers fetch the new file.

**"This server has no PHP zip extension".**
cPanel → Select PHP Version → Extensions → tick zip.

**Deploys are slow or time out.**
Usually a very large theme (big images or videos). Keep large media in the Media Library, not
in the theme.

## Updating and uninstalling

**Update the plugin:** download the new `git-auto-deploy.zip` from [Releases], then Plugins →
Add New Plugin → Upload Plugin → "Replace current with uploaded". The secret, backups and log
stay.

**Uninstall:**

1. Plugins → deactivate and delete Git Auto Deploy. Your theme stays as it is.
2. Delete `wp-content/git-auto-deploy/` (the backups) in cPanel File Manager.
3. Optional, remove its settings with WP-CLI:
   `wp option delete git_auto_deploy_secret git_auto_deploy_current git_auto_deploy_log`
4. Delete `.github/workflows/deploy-theme.yml` (or the two secrets) so GitHub stops trying.

## How it is tested

**In CI, on every push and pull request** ([ci.yml](.github/workflows/ci.yml)):

- The plugin's PHP is linted on PHP 8.0, 8.1, 8.2, 8.3 and 8.4, and the shell scripts are
  checked with ShellCheck.
- [tests/e2e.sh](tests/e2e.sh) builds a throwaway WordPress (latest release, on SQLite) and runs
  real deploys against it with the same steps and script as the deploy workflow:
  - a normal deploy from a theme in a subfolder of the repository, with the theme that was
    there before kept as the first backup;
  - a PHP syntax error stopped by the lint before anything is sent;
  - a theme that fatals, rolled back automatically by the plugin's health check;
  - a zip with the wrong top folder refused, with the live theme untouched;
  - a wrong secret, a replayed request and an expired timestamp refused;
  - a rollback from GitHub and a rollback from wp-admin, which can be switched back;
  - a `GIT_AUTO_DEPLOY_THEME` that is not a plain folder name refused before any file is
    touched, and one naming a theme that is not active;
  - the admin page rendering without PHP warnings.

**Run the tests yourself** (needs PHP 8.0+ with the zip and pdo_sqlite extensions, curl,
openssl, git and jq):

```sh
bash tests/setup.sh
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t tests/.work/wordpress &
bash tests/e2e.sh
```

**On a live site.** Before its public release it ran on a client's live site (cPanel,
LiteSpeed, PHP 8.2, WordPress 7.1), deploying the theme on every push to `main`.

## Contributing

Issues and pull requests are welcome.

- Keep changes small and focused, and describe what you tested.
- Run the tests above before opening a pull request; CI runs them too.
- Report security problems privately, as [SECURITY.md](SECURITY.md) explains, not in a public
  issue.

## License

GPL-2.0-or-later, the same license as WordPress. See [LICENSE](LICENSE).
Copyright © 2026 Vibhora Inc.
