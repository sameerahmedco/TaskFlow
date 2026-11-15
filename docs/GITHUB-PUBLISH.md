# Before publishing TaskFlow on GitHub

1. Check `git status`, `git diff` and `git ls-files` for secrets or personal data. `.gitignore` excludes `config/config.local.php`, `.installed`, `.setup-key`, `.env*`, logs and vendor assets. **Gitignore does not remove already-tracked secrets or prior commit history.** Scan the full history if previously committed.
2. Never include a production database export, screenshot with personal data, SMTP password, private keys or setup token.
3. Keep `config/config.local.php.example` populated only with non-working placeholders. Review sample configs before every release.
4. Enable branch protection, pull-request reviews, secret scanning, Dependabot and private vulnerability reporting in GitHub repository settings.
5. Choose and add a legally appropriate `LICENSE` before calling the repository open-source (none was selected automatically).
6. Tag releases and attach tested ZIP assets with SHA-256 checksums. Avoid sharing production runtime config.
7. Check CI green, MySQL integration tests successful, security assessment complete and deployment checklist signed off before claiming production readiness.

To publish *your own* repository:

```bash
cd TaskFlow
git init
git add .
git commit -m "TaskFlow 1.0.0: unified first release"
# Create your own empty GitHub repository in the GitHub UI, then:
git remote add origin https://github.com/YOUR-ORG/YOUR-REPO.git
git branch -M main
git push -u origin main
```

No remote repository is created by the source ZIP.

## TaskFlow GitHub checks

Keep both workflow files in `.github/workflows/`: `ci.yml` checks syntax, source regression and no-DB feature vectors; `mysql-integration.yml` provisions a disposable MySQL 8 service and checks fresh schema/MFA storage. The latter has **not been run locally** and should be observed on your first pull request. Choose a LICENSE before making the project public, and never commit `.installed`, `.setup-key` or private DB configs.
