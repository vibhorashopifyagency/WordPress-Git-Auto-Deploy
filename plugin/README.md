# plugin/

This folder holds the source code of the WordPress plugin, **Git Auto Deploy**.

**Just want to use it?** You don't need anything from this folder. Download the ready-made
**`git-auto-deploy.zip`** from the
[latest release](https://github.com/vibhorashopifyagency/wordpress-git-auto-deploy/releases/latest)
and follow the [setup steps](../README.md#setup-in-5-steps-about-15-minutes).

## What's inside

```text
git-auto-deploy/
├── git-auto-deploy.php               Plugin header, settings, and loading the other files
├── README.md                         Short description (ships inside the zip)
└── includes/
    ├── class-git-auto-deploy.php     The work: unpack, check, back up, switch, health check, roll back
    ├── rest.php                      The signed web addresses GitHub calls (deploy, rollback, ping)
    └── admin.php                     The page at Tools → Git Auto Deploy in WordPress
```

Changing the code? See [CONTRIBUTING.md](../CONTRIBUTING.md) for how to test it.
