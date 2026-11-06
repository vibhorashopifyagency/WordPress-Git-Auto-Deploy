# Setup guide

The [README](../README.md#setup-in-5-steps-about-15-minutes) has the short version. This guide
is the same 5 steps with every detail and option. New words are explained in the
[glossary](glossary.md).

- [Before you start](#before-you-start)
- [Step 1. Install the plugin](#step-1-install-the-plugin)
- [Step 2. Check the plugin's page](#step-2-check-the-plugins-page)
- [Step 3. Add two secrets on GitHub](#step-3-add-two-secrets-on-github)
- [Step 4. Add the workflow to your repository](#step-4-add-the-workflow-to-your-repository)
- [Step 5. Run the first deploy](#step-5-run-the-first-deploy)
- [Check that it worked](#check-that-it-worked)

## Before you start

**Your WordPress site needs:**

- WordPress 6.4 or newer.
- PHP 8.0 or newer with the `zip` extension. Most cPanel hosts have it switched on. If not:
  cPanel → **Select PHP Version → Extensions** → tick **zip**.
- Permission for PHP to rename folders inside `wp-content/themes/` and to create the folder
  `wp-content/git-auto-deploy/`. This is normal on cPanel. The plugin checks it for you in step 2.
- The WordPress REST API switched on (it is, unless a security plugin turned it off).
  "Pretty permalinks" are not needed.
- HTTPS (an `https://` address). Strongly recommended.

**On GitHub you need:**

- A repository that contains your theme. Two layouts both work:
  - **The repository is the theme:** `style.css` sits at the top of the repository.
  - **The repository is the whole site:** the theme sits in a folder such as
    `wp-content/themes/my-theme/`.
- GitHub Actions switched on (it is by default). Public repositories run it for free. Private
  repositories get free minutes every month, and one deploy takes about a minute.

**On your computer you need:** nothing. Everything below can be done in a web browser.

## Step 1. Install the plugin

1. Download **`git-auto-deploy.zip`** from the
   [latest release](https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy/releases/latest).
   Don't unzip it.
2. In WordPress go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, click
   **Install Now**, then **Activate Plugin**.

> **No upload button?** Use cPanel instead: **File Manager** → `public_html/wp-content/plugins/`
> → **Upload** the zip → right-click it → **Extract**. Then activate the plugin under
> **Plugins** in WordPress.

When you activate it, the plugin creates a random 64-character deploy secret and a folder
`wp-content/git-auto-deploy/` where it keeps backups.

## Step 2. Check the plugin's page

Go to **Tools → Git Auto Deploy** and look at **Server checks**.

1. **Every row with a mark should show a green tick ✅.** If one shows a red cross ❌, find the
   message in [Troubleshooting](troubleshooting.md).
2. **Theme folder** shows which theme gets updated, for example `wp-content/themes/my-theme`.
   The last part (`my-theme`) is your **theme folder name**. Write it down for step 4.
3. Click **Run the health check now**. It should say "Health check passed".
   If it says "skipped", your host stops the site from loading its own pages. Deploys still
   work, but only GitHub's page check (step 4, `SMOKE_PATHS`) can catch a broken update. See
   [Troubleshooting](troubleshooting.md#health-check-skipped).

**Which theme does it update?** The active theme. If a child theme is active, it updates the
child theme. To update a different theme, add this line to `wp-config.php` (cPanel → File
Manager → `public_html` → `wp-config.php` → Edit), above the line
`/* That's all, stop editing! */`:

```php
define( 'GIT_AUTO_DEPLOY_THEME', 'your-theme-folder' );
```

Use the folder name from `wp-content/themes/`, not the theme's display name.

## Step 3. Add two secrets on GitHub

Secrets let GitHub talk to your site without showing the values to anyone.

1. Open your theme's repository on GitHub.
2. Go to **Settings → Secrets and variables → Actions**.
3. Click **New repository secret** and add the first secret. Then do the same for the second.

| Name (type exactly, capitals matter) | Value |
| --- | --- |
| `GIT_AUTO_DEPLOY_URL` | Your site address, copied from WordPress **Settings → General → Site Address (URL)**, for example `https://example.com`. It must match exactly: same `https` or `http`, same `www` or no `www`. |
| `GIT_AUTO_DEPLOY_SECRET` | WordPress **Tools → Git Auto Deploy → Connect GitHub → Show secret**. Copy all 64 characters, with no space or new line. |

> Put them under **Repository secrets**, not under Environments or Dependabot.

## Step 4. Add the workflow to your repository

The workflow is a list of steps GitHub runs after every push. It is made of two files.

| Copy this file from this project | Create it in your repository as |
| --- | --- |
| [`workflow/deploy-theme.yml`](../workflow/deploy-theme.yml) | `.github/workflows/deploy-theme.yml` |
| [`workflow/git-auto-deploy.sh`](../workflow/git-auto-deploy.sh) | `.github/scripts/git-auto-deploy.sh` |

The paths on the right must be exactly like this, including the dot at the start of `.github`.

### Option A: in the browser (easiest)

For each of the two files:

1. Click the link in the left column above. Click **Copy raw file** (the two-squares icon at
   the top right of the file).
2. Open **your** repository on GitHub and click **Add file → Create new file**.
3. In the name box, type the path from the right column. Each `/` you type creates a folder.
4. Paste the content.
5. For `deploy-theme.yml` only: change the settings below before you save.
6. Click **Commit changes**.

### Option B: with the terminal

From the top folder of your repository:

```sh
mkdir -p .github/workflows .github/scripts
curl -fsSL -o .github/workflows/deploy-theme.yml \
  https://raw.githubusercontent.com/vibhorashopifyagency/wordpress-git-auto-deploy/main/workflow/deploy-theme.yml
curl -fsSL -o .github/scripts/git-auto-deploy.sh \
  https://raw.githubusercontent.com/vibhorashopifyagency/wordpress-git-auto-deploy/main/workflow/git-auto-deploy.sh
```

Then edit `deploy-theme.yml` (settings below), commit and push.

### The settings in `deploy-theme.yml`

Every line you may need to change is marked `CHANGE` in the file.

| Setting | What to put | Example |
| --- | --- | --- |
| `branches` | The branch that goes live. | `[main]` |
| `paths` | Your theme folder followed by `/**`. The workflow only starts when files in here change. If the repository is the theme, use `'**'`. | `'wp-content/themes/my-theme/**'` |
| `THEME_SLUG` | The theme folder name from step 2. | `my-theme` |
| `THEME_DIR` | Where the theme is inside the repository. Use `.` (a dot) if the repository is the theme. | `wp-content/themes/my-theme` |
| `SMOKE_PATHS` | Pages that must load after every update, separated by spaces. Only use pages that will not move or be deleted: a missing page makes every update roll back. | `/ /about/ /contact/` |
| `PHP_VERSION` | Your server's PHP version (cPanel → **MultiPHP Manager**). | `'8.2'` |

### Extra file, depending on your layout

**If the repository is the theme**, add a file named `.gitattributes` at the top of the
repository. It keeps files your site doesn't need out of the upload:

```text
.github export-ignore
.gitattributes export-ignore
README.md export-ignore
```

Add a line for anything else your site doesn't need, such as `node_modules`, `tests` or `src`.

**If the repository is the whole site**, add this line to your `.gitignore`, so the plugin's
backups are never committed by mistake:

```text
wp-content/git-auto-deploy/
```

## Step 5. Run the first deploy

The workflow starts by itself only when theme files change, so start the first one by hand:

1. In your repository, open the **Actions** tab.
2. Click **Deploy theme** in the list on the left.
3. Click **Run workflow**, keep the branch as `main`, and click the green **Run workflow**.
4. Click the run that appears to watch it. The steps are: *Lint theme PHP*, *Check deploy
   secrets*, *Build theme zip*, *Deploy to live site*, *Smoke-test live pages*.

A green tick means the theme is live. A red cross means something stopped it. Click the red
step to read why, then look up the message in [Troubleshooting](troubleshooting.md).

The first deploy keeps the theme that was on your site before as a backup called
"Theme as it was before the first Git deploy", so you can always go back to it.

## Check that it worked

In WordPress, open **Tools → Git Auto Deploy**:

- **Live theme** shows the commit that is live, for example `5ce1327 Update header colours`.
- **Activity** shows a green tick and "Deployed. Health check passed (/ HTTP 200)."

You're done. From now on, every push to `main` that changes the theme goes live by itself.
Next: [Everyday use](everyday-use.md).
