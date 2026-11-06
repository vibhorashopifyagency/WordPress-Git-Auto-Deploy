# Everyday use

- [Updating your theme](#updating-your-theme)
- [Rolling back (undoing an update)](#rolling-back-undoing-an-update)
- [Deploying without GitHub](#deploying-without-github)
- [The plugin's page in WordPress](#the-plugins-page-in-wordpress)

## Updating your theme

1. Change your theme, commit, and push to `main`.
2. About a minute later it is live.

Good to know:

- **Everything pushed to `main` goes live.** Do unfinished work on another branch and merge it
  into `main` when it's ready.
- **Several pushes in a row** are queued and deployed one at a time, in order.
- **To deploy again without changes:** Actions → Deploy theme → Run workflow.
- **Where to look:**
  - The **Actions** tab on GitHub has a log for every run.
  - **Tools → Git Auto Deploy → Activity** in WordPress lists the last 30 deploys and rollbacks.
- **Emails:** GitHub emails you when a run fails. To change that: your GitHub picture →
  Settings → Notifications → Actions.

## Rolling back (undoing an update)

### Automatic: you don't need to do anything

- Right after an update, your site loads its own home page. If the page shows an error (a
  server error, "There has been a critical error", a PHP "Fatal error"), or stops half-way, the
  previous version is put back immediately.
- Then GitHub loads the pages in `SMOKE_PATHS`. If one of them is broken, GitHub asks the site
  to put the previous version back.

The broken version is moved to `wp-content/git-auto-deploy/failed/` so you can look at it.
The newest 3 are kept.

### By hand in WordPress

**Tools → Git Auto Deploy → Earlier versions → Roll back to this.** The version you replace is
added to the list, so you can switch back the same way.

### From your computer (terminal)

```sh
GIT_AUTO_DEPLOY_URL=https://example.com GIT_AUTO_DEPLOY_SECRET=<your-secret> \
  bash .github/scripts/git-auto-deploy.sh rollback
```

This puts the newest backup back.

### If WordPress itself won't load (cPanel File Manager)

1. Open `public_html/wp-content/git-auto-deploy/backups/`. Each folder is one earlier version.
   The folder names start with the date and time, so the newest is last. Its `meta.json` file
   says which commit it is.
2. Rename `public_html/wp-content/themes/<your-theme>` to `<your-theme>-broken`.
3. Move `backups/<that folder>/<your-theme>` into `public_html/wp-content/themes/`.

The plugin's page won't know about this until the next deploy.

> **Important:** a rollback only changes the files on your site. Your Git repository still has
> the bad change, and the next push sends it again. Fix the code, or undo the commit
> (`git revert <commit>`), and push.

## Deploying without GitHub

### Upload a zip in WordPress

**Tools → Git Auto Deploy → Upload a theme zip.** The zip must contain one folder named exactly
like your theme folder. It gets the same checks, backup and automatic rollback as a deploy from
GitHub. A zip made on a Mac with **Compress** works. The largest size allowed is shown under
Server checks as "Largest zip upload here".

### From your computer (terminal)

This does the same as GitHub, without the PHP check and the page check:

```sh
git archive --format=zip --prefix=my-theme/ -o theme.zip HEAD:wp-content/themes/my-theme
GIT_AUTO_DEPLOY_URL=https://example.com GIT_AUTO_DEPLOY_SECRET=<your-secret> \
  bash .github/scripts/git-auto-deploy.sh deploy theme.zip
```

`git archive` packs your last commit, not changes you haven't committed yet.

### Check the connection

```sh
GIT_AUTO_DEPLOY_URL=https://example.com GIT_AUTO_DEPLOY_SECRET=<your-secret> \
  bash .github/scripts/git-auto-deploy.sh ping
```

It answers with the live version, the number of backups and the server checks. It changes
nothing.

## The plugin's page in WordPress

**Tools → Git Auto Deploy.** Only administrators can see it.

| Section | What it shows |
| --- | --- |
| **Live theme** | The commit that is live, where it came from (GitHub, upload, rollback) and when. |
| **Connect GitHub** | The two secrets to add on GitHub, your site address, the `THEME_SLUG` to use, and the secret itself behind **Show secret**. **Create a new secret** replaces it; deploys fail until GitHub has the new one. |
| **Server checks** | Whether everything the plugin needs is in place, plus **Run the health check now**. |
| **Earlier versions** | The kept backups with **Roll back to this**, and which versions were rolled back as broken. |
| **Upload a theme zip** | Deploy from a zip file ([see above](#upload-a-zip-in-wordpress)). |
| **Activity** | The last 30 deploys and rollbacks: when, from where, which commit, the result, the time taken. ✅ done, ↩️ rolled back, ❌ failed. |
