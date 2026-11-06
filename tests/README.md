# tests/

Automated tests. You only need these if you change the code.

| File | What it does |
| --- | --- |
| [`setup.sh`](setup.sh) | Builds a throwaway WordPress site (latest WordPress, on SQLite, so no database server is needed) with a test theme and this project's plugin. |
| [`e2e.sh`](e2e.sh) | Runs real deploys and rollbacks against that site, using the same steps and script as the workflow, and checks the results. |

How to run them: [CONTRIBUTING.md](../CONTRIBUTING.md#running-the-tests). GitHub also runs them
on every push and pull request.
