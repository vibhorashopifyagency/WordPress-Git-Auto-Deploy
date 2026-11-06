# Glossary

Words used in these guides, in plain English.

| Word | What it means |
| --- | --- |
| **Branch** | A separate line of work in a repository. `main` is usually the one that goes live. |
| **Cache** | A saved copy of a page that a website or browser shows to be faster. After an update, an old cached page can make it look as if nothing changed. |
| **Child theme** | A theme that builds on another ("parent") theme. If a child theme is active, the plugin updates the child theme. |
| **Commit** | A saved snapshot of your changes in Git, with a short message describing them. |
| **cPanel** | A control panel many hosting companies give you to manage your website (files, PHP version, databases). |
| **Deploy** | Putting a new version of your code on the live site. |
| **Git** | The tool that records the history of your code. |
| **GitHub** | A website that stores Git repositories and can run tasks for you (GitHub Actions). |
| **GitHub Actions** | GitHub's feature that runs a list of steps (a workflow) automatically, for example after every push. |
| **Health check** | The plugin loading your home page right after an update, to make sure it still works. |
| **Lint** | Checking code for mistakes (here: PHP syntax errors) without running it. |
| **Plugin** | An add-on you install in WordPress. Git Auto Deploy is one. |
| **Push** | Sending your commits from your computer to GitHub. |
| **Repository (repo)** | A project on GitHub: your code and its full history. |
| **Rollback** | Putting the previous version back after a bad update. |
| **Secret** | A private value (like a password) stored in a GitHub repository's settings. GitHub hides it in logs. |
| **Signature** | A code made from the request and the secret. It proves the update really came from your GitHub repository and wasn't changed on the way. |
| **Smoke test** | Loading a few important pages after an update to see that nothing is broken. Set with `SMOKE_PATHS`. |
| **SSH / FTP** | Older ways to copy files to a server. This tool doesn't need them. |
| **Theme** | The design and templates of a WordPress site. Its files live in `wp-content/themes/<theme-folder>/`. |
| **Theme folder name (slug)** | The folder name of a theme inside `wp-content/themes/`, for example `my-theme`. Not the same as the theme's display name. |
| **`wp-config.php`** | WordPress's main settings file, in your site's top folder. |
| **Workflow** | A file in `.github/workflows/` that tells GitHub Actions what to do. Here: `deploy-theme.yml`. |
| **Zip** | One compressed file that holds a whole folder. The theme is sent to your site as a zip. |
