# Troubleshooting

## First, find the error message

- **On GitHub:** open the **Actions** tab, click the red run, then click the red step. The
  message is near the bottom, often with an HTTP code such as `401` or `404`.
- **In WordPress:** **Tools → Git Auto Deploy → Activity** shows what happened on your site.

Then find the message below. Your live site is safe in almost every case: when something goes
wrong the plugin either doesn't change anything or puts the previous version back.

**Problems on GitHub**

- [The workflow did not start after a push](#the-workflow-did-not-start-after-a-push)
- [Nothing was deployed: add the secrets](#nothing-was-deployed-add-the-secrets)
- [Lint theme PHP failed](#lint-theme-php-failed)
- [HTTP 301 or 302: redirected](#http-301-or-302-redirected)
- [HTTP 400: the upload arrived empty](#http-400-the-upload-arrived-empty)
- [HTTP 401: bad signature](#http-401-bad-signature)
- [HTTP 401: timestamp too far from the server clock](#http-401-timestamp-too-far-from-the-server-clock)
- [HTTP 403, or a web page instead of a message](#http-403-or-a-web-page-instead-of-a-message)
- [HTTP 404: is the plugin installed and active?](#http-404-is-the-plugin-installed-and-active)
- [HTTP 409: another deploy is running](#http-409-another-deploy-is-running)
- [HTTP 409: this signed request was already used](#http-409-this-signed-request-was-already-used)
- [HTTP 422: everything in the zip must sit inside a folder](#http-422-everything-in-the-zip-must-sit-inside-a-folder)
- [HTTP 500: no valid theme folder name](#http-500-no-valid-theme-folder-name)
- [HTTP 500: could not move the theme](#http-500-could-not-move-the-theme)
- [The smoke test fails but the site looks fine](#the-smoke-test-fails-but-the-site-looks-fine)

**Problems in WordPress**

- [A red cross under Server checks](#a-red-cross-under-server-checks)
- [Health check skipped](#health-check-skipped)
- [The deploy is green but the site still looks old](#the-deploy-is-green-but-the-site-still-looks-old)
- [Deploys are slow or time out](#deploys-are-slow-or-time-out)

---

## Problems on GitHub

### The workflow did not start after a push

**Why:** your push didn't change any file inside the `paths` folder, or it went to a different
branch than the one in `branches`.
**Fix:** start it by hand (**Actions → Deploy theme → Run workflow**), or correct `paths` and
`branches` in `.github/workflows/deploy-theme.yml`.

### Nothing was deployed: add the secrets

The run shows: *"Nothing was deployed: add the GIT_AUTO_DEPLOY_URL and GIT_AUTO_DEPLOY_SECRET
repository secrets."*
**Why:** a secret is missing or its name is misspelt.
**Fix:** add them under **Settings → Secrets and variables → Actions → Repository secrets**
(not Environments, not Dependabot). See [setup step 3](setup-guide.md#step-3-add-two-secrets-on-github).

### Lint theme PHP failed

**Why:** a PHP file has a syntax error. The log names the file and the line. It can also mean
your code needs a newer PHP than `PHP_VERSION`.
**Fix:** correct the code and push again. Nothing was sent to your site.

### HTTP 301 or 302: redirected

**Why:** `GIT_AUTO_DEPLOY_URL` isn't exactly your site address, for example `http` instead of
`https`, or `www` when your site doesn't use it.
**Fix:** copy the address from WordPress **Settings → General → Site Address (URL)** into the
secret.

### HTTP 400: the upload arrived empty

The run shows: *"The upload arrived empty. Is the zip bigger than post_max_size?"*
**Why:** your server only accepts uploads up to a certain size, and the theme zip is bigger.
**Fix:** cPanel → **MultiPHP INI Editor** → choose your domain → raise `post_max_size` and
`upload_max_filesize` (for example to `64M`) → **Apply**.

### HTTP 401: bad signature

**Why:** the secret on GitHub isn't the same as the one in WordPress. Often a space or new line
was pasted with it, or a new secret was created in WordPress.
**Fix:** copy it again from **Tools → Git Auto Deploy → Show secret** into the
`GIT_AUTO_DEPLOY_SECRET` secret on GitHub.

### HTTP 401: timestamp too far from the server clock

The run shows: *"… more than 5 minutes from the server clock."*
**Why:** your server's clock is wrong.
**Fix:** ask your host to fix the server time.

### HTTP 403, or a web page instead of a message

**Why:** a firewall blocked the upload, for example ModSecurity, Imunify360 or Cloudflare.
**Fix:** ask your host to allow requests to `/?rest_route=/git-auto-deploy/v1/`. With
Cloudflare, look under **Security → Events** and add a rule that skips that path.

### HTTP 404: is the plugin installed and active?

**Why:** the plugin isn't active, `GIT_AUTO_DEPLOY_URL` points to the wrong site, or a security
plugin blocks the WordPress REST API.
**Fix:** activate the plugin, check the address, or allow `/git-auto-deploy/v1/` in your
security plugin.

### HTTP 409: another deploy is running

**Why:** a deploy or rollback is still running, or one crashed less than 15 minutes ago.
**Fix:** wait a few minutes and run it again.

### HTTP 409: this signed request was already used

**Why:** the same request reached your site twice (for example a proxy retried it). Each signed
request works only once, for safety.
**Fix:** run the workflow again.

### HTTP 422: everything in the zip must sit inside a folder

The run shows: *"Everything in the zip must sit inside a "my-theme" folder …"*
**Why:** `THEME_SLUG` in the workflow isn't the theme folder the plugin updates.
**Fix:** set `THEME_SLUG` to the folder shown in **Tools → Git Auto Deploy → Server checks →
Theme folder**.

### HTTP 500: no valid theme folder name

**Why:** `GIT_AUTO_DEPLOY_THEME` in `wp-config.php` isn't a plain folder name.
**Fix:** use the name exactly as in `wp-content/themes/<folder>`: only letters, numbers, `.`,
`_` and `-`.

### HTTP 500: could not move the theme

The run shows: *"Could not move the live theme aside"* or *"… the new theme into place"*.
**Why:** PHP isn't allowed to rename folders in `wp-content/themes/`.
**Fix:** folders should have permission 755 and files 644, owned by your cPanel user. If your
host runs PHP as "nobody", ask them to switch to LSAPI or PHP-FPM.

### The smoke test fails but the site looks fine

**Why:** a page listed in `SMOKE_PATHS` was moved or deleted, so it returns "not found".
**Fix:** update `SMOKE_PATHS` in `.github/workflows/deploy-theme.yml`.

---

## Problems in WordPress

### A red cross under Server checks

| Row | Fix |
| --- | --- |
| Deploy secret | Click **Create secret** on the same page. |
| PHP zip extension | cPanel → **Select PHP Version → Extensions** → tick **zip**. |
| Theme folder | Not there yet is fine: the first deploy creates it. If it says the name is not a plain folder name, see [HTTP 500: no valid theme folder name](#http-500-no-valid-theme-folder-name). |
| Themes folder writable / Backup folder writable | See [HTTP 500: could not move the theme](#http-500-could-not-move-the-theme). |

### Health check skipped

The message says *"Health check skipped (not reachable from the server …)"* or
*"(HTTP 401/403 …)"*.
**Why:** your host stops the site from loading its own pages, or the site is behind a
password, maintenance mode or a firewall.
**What it means:** deploys still work, but only GitHub's page check (`SMOKE_PATHS`) can catch a
broken update.
**Fix:** ask your host to allow the server to reach its own domain.

### The deploy is green but the site still looks old

**Why:** a cache is showing the old page: a cache plugin, Cloudflare, or your browser.
**Fix:** clear those caches. The plugin already clears the LiteSpeed Cache plugin by itself.
Developers can clear other caches automatically with the `git_auto_deploy_after_swap` hook
([reference](reference.md#hooks-for-developers)). For CSS and JS files, add a version number
that changes with the file (for example with `filemtime()` in `wp_enqueue_style`) so browsers
fetch the new file.

### Deploys are slow or time out

**Why:** usually a very large theme, with big images or videos inside it.
**Fix:** keep large media in the WordPress Media Library, not in the theme.

---

Still stuck? [Open an issue](https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy/issues)
and include the error message from the Actions log. Never paste your secret.
