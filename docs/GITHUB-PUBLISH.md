# Publish TaskFlow v2 to GitHub

**Target repository:** https://github.com/sameerahmedco/taskflow · Default branch `main` · Source `VERSION=2.0.0`.

TaskFlow v2 is a unified, GitHub-ready source tree (not 22 consecutive ZIP uploads). The **GitHub-ready ZIP is already flattened to the repository root** (`README.md`, `.github/`, `api/`, `assets/`, etc.). Do not add an enclosing `TaskFlow/` folder. An optional traditional source archive also contains a `TaskFlow/` parent folder for manual deployment. The historic v1 release notes are archived in `docs/history/v1/`; all SQL migrations remain available.

## Safe workflow: feature branch + pull request

Clone the current repository into a separate working folder, then extract the **flattened** `TaskFlow-v2.0.0-GitHub-Ready.zip` into that clone:

```bash
git clone https://github.com/sameerahmedco/taskflow.git taskflow-v2-release
cd taskflow-v2-release
git switch -c release/v2.0.0
# Move tracked v1 release notes out of the active docs location.
# Their v2 copies live under docs/history/v1/.
git rm -- docs/RELEASE-1*.md
# Extract the flat GitHub-ready ZIP at the repository root.
# Adjust /path/to/ to the downloaded ZIP location.
unzip -o /path/to/TaskFlow-v2.0.0-GitHub-Ready.zip -d .
# Verify no local secrets or environment-specific files are staged.
git status --short
git add -A
git diff --cached --stat
git diff --cached --check
git commit -m "TaskFlow v2.0.0: unified features and workspace redesign"
git push -u origin release/v2.0.0
```

Open a pull request from `release/v2.0.0` into `main`. Review changed files and screenshots, wait for GitHub CI (lint, security, browser contrast, MySQL integration), then merge using the repository's normal safeguards. Finally tag `v2.0.0` and create a GitHub Release.

**Do not force-push `main`** or replace history; v2 consolidation is in the source/docs, not a rewrite of the Git commit DAG. Avoid committing private config, setup keys, installation locks, `.env` or logs.

## Before merging

- [ ] `VERSION`, README, installer and app header all show v2.0.0.
- [ ] README screenshots are white-background Light previews (verified with `tests/readme_screenshots_unit.py`).
- [ ] All v1 feature modules and 25 prior migrations are present.
- [ ] PHPUnit-style checks, PHP lint and JS check pass.
- [ ] Browser regression passes in mobile, light and dark mode.
- [ ] Real MySQL staging upgrade and role authorization checks pass.
- [ ] Fresh installation, persistent sessions, password recovery, MFA, attachments and cron jobs tested.
- [ ] GitHub authentication has write rights; the repository owner approves the PR.

**GitHub connector state (2026-11-20):** the active integration can read `sameerahmedco/taskflow` but has `push=false`; therefore no direct remote write was performed by ChatGPT.
