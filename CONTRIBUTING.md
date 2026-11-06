# Contributing

Thanks for helping. Bug reports, ideas, documentation fixes and code are all welcome.

- [Reporting a bug or asking a question](#reporting-a-bug-or-asking-a-question)
- [Where things are](#where-things-are)
- [Running the tests](#running-the-tests)
- [Sending a change](#sending-a-change)
- [Releasing a new version (maintainers)](#releasing-a-new-version-maintainers)

## Reporting a bug or asking a question

[Open an issue](https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy/issues) with:

- what you did, what you expected, and what happened instead;
- the error message from the Actions log, or from **Tools → Git Auto Deploy → Activity**;
- your WordPress and PHP versions and your hosting (for example cPanel with LiteSpeed).

**Never paste your deploy secret.** Security problems go privately, as
[SECURITY.md](SECURITY.md) explains.

## Where things are

| Folder | What's in it |
| --- | --- |
| [`plugin/git-auto-deploy/`](plugin/) | The WordPress plugin. Most of the logic is in `includes/class-git-auto-deploy.php`. |
| [`workflow/`](workflow/) | The GitHub Actions workflow and the script users copy into their own repositories. |
| [`docs/`](docs/) | The guides. |
| [`tests/`](tests/) | End-to-end tests. |
| [`.github/workflows/`](.github/workflows/) | This project's own CI (`ci.yml`) and release builder (`release.yml`). |

## Running the tests

You need PHP 8.0 or newer with the `zip` and `pdo_sqlite` extensions, plus `curl`, `openssl`,
`git` and `jq`. No database server.

```sh
bash tests/setup.sh                                                   # build a throwaway WordPress
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t tests/.work/wordpress &   # serve it
bash tests/e2e.sh                                                     # run the tests
```

`PHP_CLI_SERVER_WORKERS=4` matters: the plugin's health check loads the site while the deploy
request is still running, which a single-worker server can't answer.

Options: set `PHP` to use another PHP binary (for example the one that comes with Local),
`PORT` for another port, and `WORK` for another work folder. Everything is created in
`tests/.work/`, which git ignores. Running `setup.sh` again starts from a fresh site.

Also run before sending a change:

```sh
find plugin -name '*.php' -print0 | xargs -0 -n1 php -l   # PHP syntax
shellcheck workflow/git-auto-deploy.sh tests/*.sh         # shell scripts
```

GitHub runs all of this on every push and pull request (PHP 8.0 to 8.4).

## Sending a change

1. Fork the repository and create a branch.
2. Keep the change small and focused on one thing.
3. Run the tests above. If you fix a bug, add a check to `tests/e2e.sh` that would have caught it.
4. Update the guides in `docs/` if what users see or do changes, and add a line under
   **Unreleased** in [CHANGELOG.md](CHANGELOG.md).
5. Open a pull request and describe what you changed and how you tested it.

Keep the plugin and the workflow compatible: users update them separately. If a change needs
both, say so in the changelog.

## Releasing a new version (maintainers)

1. Set the new version in `plugin/git-auto-deploy/git-auto-deploy.php` in two places: the
   `Version:` line in the header and `GIT_AUTO_DEPLOY_VERSION`.
2. In [CHANGELOG.md](CHANGELOG.md), rename **Unreleased** to the version and date, for example
   `## 1.1.0 — 2026-12-01`.
3. Commit, push, and wait for CI to pass.
4. Tag and push the tag:

   ```sh
   git tag -a v1.1.0 -m "Git Auto Deploy 1.1.0"
   git push origin v1.1.0
   ```

GitHub then builds `git-auto-deploy.zip` and publishes the release, with the changelog section
as release notes. The tag must match the `Version:` line, or the release stops.
