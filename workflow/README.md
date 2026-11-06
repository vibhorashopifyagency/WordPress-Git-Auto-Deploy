# workflow/

These two files go into **your theme's GitHub repository**, not into WordPress. Together they
tell GitHub what to do after every push: check the PHP, send the theme to your site, and check
your pages.

| File | Put it in your repository as | What it does |
| --- | --- | --- |
| [`deploy-theme.yml`](deploy-theme.yml) | `.github/workflows/deploy-theme.yml` | The steps GitHub runs. Change the lines marked `CHANGE`. |
| [`git-auto-deploy.sh`](git-auto-deploy.sh) | `.github/scripts/git-auto-deploy.sh` | Signs and sends the theme to your site. Don't change it. |

How to copy them, and what to put in each `CHANGE` line:
[setup step 4](../docs/setup-guide.md#step-4-add-the-workflow-to-your-repository).

They sit here, not in this project's own `.github/` folder, so that GitHub doesn't try to run
them inside this project.
