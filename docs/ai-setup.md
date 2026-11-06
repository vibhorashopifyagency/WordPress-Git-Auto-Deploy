# Set it up with an AI assistant

This page has two parts:

- **[For people](#for-people)**: what to tell your AI assistant, and what you'll still do
  yourself.
- **[For AI assistants](#for-ai-assistants)**: exact instructions an assistant follows to set
  this project up for someone.

---

## For people

### The prompt

Copy this into your AI assistant (ChatGPT, Claude, Copilot, Cursor, Codex, or similar):

```text
Set up WordPress Git Auto Deploy for my WordPress theme by following
https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy/blob/main/docs/ai-setup.md
Ask me for anything you need, one step at a time.
Never ask me to paste the deploy secret into this chat.
```

If your assistant works inside your theme's project folder (Claude Code, Codex, Cursor and
similar), open that folder first, so it can add the files for you.

### What your assistant can do

| Your assistant can… | Then it can do this for you | You do this yourself |
| --- | --- | --- |
| only chat | Explain every step and check your answers | All the clicks in WordPress and GitHub |
| use a terminal in your project, with the `gh` GitHub tool | Add the workflow files, fill in the settings, commit, push, start and watch the first deploy | Install the plugin in WordPress, read two values from the plugin's page, and add the deploy secret on GitHub |
| also reach your server with WP-CLI (SSH) | Also install the plugin and pass the secret to GitHub without showing it | Approve each step |

### Keep the deploy secret out of the chat

The deploy secret lets anyone who has it put code on your website. **Don't paste it into a
chat with an AI.** Add it to GitHub yourself (the assistant tells you where), or let an
assistant with server access pass it straight to GitHub without showing it. If you pasted it
somewhere by mistake, make a new one: **Tools → Git Auto Deploy → Create a new secret**, then
update it on GitHub.

---

## For AI assistants

You are setting up WordPress Git Auto Deploy for a user. When you are done, every push to their
live branch updates the theme on their live WordPress site, with automatic rollback if the site
breaks. Read these rules first, then follow the steps in order.

### Rules

1. **Never ask for, print, log, or commit the deploy secret** (`GIT_AUTO_DEPLOY_SECRET`). Don't
   put it in a command line, a file, or your reply. The user adds it to GitHub, or you pipe it
   directly from the server into `gh secret set` (step 3).
2. **Don't guess values that only the user knows.** Ask for them (step 0). Wrong values make
   deploys fail or roll back.
3. **Ask before pushing to the live branch.** In some layouts the push itself starts a deploy
   to the live site (step 5).
4. **Copy `git-auto-deploy.sh` unchanged.** In `deploy-theme.yml` change only the lines marked
   `CHANGE`.
5. **The user needs admin access** to the WordPress site and to the GitHub repository's settings.
   If they don't have both, stop and tell them.
6. Work one step at a time. Tell the user what you did and what comes next.

### What you need to know

| Value | Where it comes from |
| --- | --- |
| Repository layout | You decide, from the repository (step 0) |
| Live branch | The repository's default branch, confirmed by the user |
| `GIT_AUTO_DEPLOY_URL` | The user: WordPress **Settings → General → Site Address (URL)**, exactly, e.g. `https://example.com` |
| `THEME_SLUG` | The user, after step 1: **Tools → Git Auto Deploy → Server checks → Theme folder** (the last part of the path) |
| `THEME_DIR` | You, from the layout: `.` or `wp-content/themes/<THEME_SLUG>` |
| `PHP_VERSION` | The user: WordPress **Tools → Site Health → Info → Server → PHP version**, or cPanel **MultiPHP Manager**. Use `major.minor`, e.g. `'8.2'` |
| `SMOKE_PATHS` | Suggest `/` plus one or two pages that won't move (e.g. `/contact/`); the user confirms. Each must load without logging in. |
| `GIT_AUTO_DEPLOY_SECRET` | WordPress **Tools → Git Auto Deploy → Show secret**. You never see it (rule 1). |

### Step 0. Look at the repository and ask the user

Work out the layout:

- **Layout A, the repository is the theme:** a `style.css` containing `Theme Name:` is at the
  top of the repository.
- **Layout B, the repository is the whole site:** the theme is at
  `wp-content/themes/<folder>/style.css`. If there are several themes, ask which one is live.
- Neither? Ask the user where the theme is.

Find the default branch: `git symbolic-ref --short refs/remotes/origin/HEAD` prints for
example `origin/main`, so the branch is `main`. That is usually the live branch.

Then ask the user, in one message:

1. Their site address (Settings → General → Site Address).
2. Their server's PHP version (Tools → Site Health → Info → Server).
3. Which pages must always load (suggest `/` and one more).
4. To confirm the live branch, and the theme (layout B with several themes).

### Step 1. Install the plugin

**If you have WP-CLI on the server:**

```sh
wp plugin install https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy/releases/latest/download/git-auto-deploy.zip --activate
```

**Otherwise, ask the user to:**

1. Download `git-auto-deploy.zip` from
   https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy/releases/latest
2. In WordPress: **Plugins → Add New Plugin → Upload Plugin** → choose the zip → **Install
   Now** → **Activate Plugin**.

### Step 2. Check the server and get the theme folder

Ask the user to open **Tools → Git Auto Deploy** and tell you:

- whether every row under **Server checks** has a green tick. For any red cross, use
  [troubleshooting](troubleshooting.md#a-red-cross-under-server-checks) and fix it before going on;
- the **Theme folder** shown there. Its last part is `THEME_SLUG`.

With WP-CLI you can read the theme yourself: `wp option get stylesheet` (unless
`GIT_AUTO_DEPLOY_THEME` is defined in `wp-config.php`).

In layout B, `THEME_SLUG` should match the theme's folder in the repository. If it doesn't, ask
the user which one is right before going on.

### Step 3. Add the two GitHub secrets

**`GIT_AUTO_DEPLOY_URL`** is not sensitive. With `gh`:

```sh
gh secret set GIT_AUTO_DEPLOY_URL --body "https://example.com"
```

**`GIT_AUTO_DEPLOY_SECRET`** must not pass through you (rule 1). Pick one:

- **The user adds it** (works everywhere): ask them to copy it from **Tools → Git Auto Deploy →
  Connect GitHub → Show secret**, then in the repository on GitHub go to **Settings → Secrets
  and variables → Actions → New repository secret**, name `GIT_AUTO_DEPLOY_SECRET`, paste,
  **Add secret**. Or they can run `gh secret set GIT_AUTO_DEPLOY_SECRET` in their own terminal,
  which asks for the value without showing it.
- **You have shell access to the server:** pipe it straight across, so it never appears:

  ```sh
  ssh <server> 'wp option get git_auto_deploy_secret --path=<wordpress-path>' | gh secret set GIT_AUTO_DEPLOY_SECRET
  ```

  If the site defines `GIT_AUTO_DEPLOY_SECRET` in `wp-config.php`, the option is empty: use the
  first way.

Check that both names exist (this shows names only, never values): `gh secret list`.

### Step 4. Add the workflow files

From the top folder of the repository:

```sh
mkdir -p .github/workflows .github/scripts
curl -fsSL -o .github/workflows/deploy-theme.yml \
  https://raw.githubusercontent.com/vibhorashopifyagency/wordpress-git-auto-deploy/main/workflow/deploy-theme.yml
curl -fsSL -o .github/scripts/git-auto-deploy.sh \
  https://raw.githubusercontent.com/vibhorashopifyagency/wordpress-git-auto-deploy/main/workflow/git-auto-deploy.sh
```

No terminal? Ask the user to create both files on GitHub (**Add file → Create new file**) with
the content of [`workflow/deploy-theme.yml`](../workflow/deploy-theme.yml) and
[`workflow/git-auto-deploy.sh`](../workflow/git-auto-deploy.sh), at those two paths.

In `.github/workflows/deploy-theme.yml`, edit only the lines marked `CHANGE`:

| Line | Layout A (repository is the theme) | Layout B (theme in a subfolder) |
| --- | --- | --- |
| `branches:` | `[<live branch>]` | `[<live branch>]` |
| `paths:` item | `'**'` | `'wp-content/themes/<THEME_SLUG>/**'` |
| `THEME_SLUG:` | `<THEME_SLUG>` | `<THEME_SLUG>` |
| `THEME_DIR:` | `.` | `wp-content/themes/<THEME_SLUG>` |
| `SMOKE_PATHS:` | `/ /contact/` (the user's pages) | same |
| `PHP_VERSION:` | `'8.2'` (the user's version, quoted) | same |

Then add the extra file for the layout:

- **Layout A:** create or extend `.gitattributes` at the top of the repository, so these files
  are not sent to the site:

  ```text
  .github export-ignore
  .gitattributes export-ignore
  README.md export-ignore
  ```

  Add a line for other files the site doesn't need, such as `node_modules`, `tests`, `src`.
- **Layout B:** add `wp-content/git-auto-deploy/` to `.gitignore`.

Check your edits: the YAML still parses, and `THEME_DIR` really contains `style.css`.

### Step 5. Commit, and ask before pushing

Commit the new files, for example with the message "Add Git Auto Deploy workflow".

**Before you push, tell the user what will happen and wait for a yes:**

- **Layout A** (`paths: '**'`): pushing this commit to the live branch starts a deploy right
  away. The current theme in the repository goes live, if the secrets are set.
- **Layout B:** pushing only the `.github` files doesn't start a deploy. Start the first one in
  step 6.

### Step 6. Run and watch the first deploy

If the push didn't start one, start it:

```sh
gh workflow run deploy-theme.yml --ref <live branch>
```

Or ask the user to click **Actions → Deploy theme → Run workflow → Run workflow**.

Watch it:

```sh
gh run list --workflow deploy-theme.yml --limit 1
gh run watch <run-id> --exit-status
```

- **Green:** the step "Deploy to live site" ends with "Deployed. Health check passed". Ask the
  user to check **Tools → Git Auto Deploy → Live theme**, which now shows the commit.
- **Red:** read the failed step (`gh run view <run-id> --log-failed`), find the message in
  [troubleshooting](troubleshooting.md) and fix it. A red run never leaves a broken site live:
  the deploy either didn't start or was rolled back.

### Step 7. Tell the user how to use it

Finish with a short summary for the user:

- what you set up: the layout, the live branch, `THEME_SLUG`, and the pages checked after
  every deploy;
- from now on, every push to the live branch that changes the theme goes live in about a
  minute;
- to undo an update: **Tools → Git Auto Deploy → Earlier versions → Roll back to this**, then
  fix or revert the commit in Git;
- the guides: [everyday use](everyday-use.md) and [troubleshooting](troubleshooting.md).

### If you only have chat

Guide the user through the [5 steps in the README](../README.md#setup-in-5-steps-about-15-minutes)
one at a time. Ask for the values in step 0 first, give them the exact lines to paste for
step 4 (from the table above), and wait for them to confirm each step before the next. Never ask
them to paste the deploy secret to you.
