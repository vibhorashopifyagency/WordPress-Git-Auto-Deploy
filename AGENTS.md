# Instructions for AI coding agents

**Setting this up for a user's site?** Don't change this repository. Follow
[docs/ai-setup.md](docs/ai-setup.md): it installs the plugin on their WordPress site and adds
the workflow to *their* theme repository. Never ask for, print, or commit the deploy secret.

**Changing this project's code or docs?**

- Layout: `plugin/git-auto-deploy/` is the WordPress plugin (logic in
  `includes/class-git-auto-deploy.php`), `workflow/` holds the files users copy into their
  repository, `docs/` the guides, `tests/` the end-to-end tests.
- Run the checks in [CONTRIBUTING.md](CONTRIBUTING.md#running-the-tests) before you finish:
  PHP lint, `shellcheck`, and `tests/setup.sh` then `tests/e2e.sh`.
- Keep the plugin (PHP 8.0+) and the workflow compatible; users update them separately.
- If users would see or do something differently, update the guides in `docs/` and add a line
  under **Unreleased** in `CHANGELOG.md`.
- Don't put `deploy-theme.yml` in this repository's own `.github/workflows/`: it would try to
  deploy from here.
