# Upgrade to TaskFlow v2.1.0

## v2.0.0 → v2.1.0

1. Back up and restore-test the MySQL database, private configuration, installation lock, encryption key, and private attachments.
2. Deploy the v2.1.0 source while preserving `config/config.local.php`, `config/.installed`, and keys. **No SQL migration is required**: this adds a read-only project overview and client-side project selection.
3. Refresh asset caches. Test overview cards and links as manager and member; confirm a user cannot request another team's project by calling the API directly.
4. Run the disposable MySQL integration workflow and authenticated HTTP tests in staging before production use. Browser tests with mocked APIs do not demonstrate server-side authorization.

## v1.22.0 → v2.0.0

1. Freeze writes. Back up and **restore-test** the existing MySQL database, private configuration, installation lock, application key and any private attachments.
2. Deploy v2 source in place without overwriting `config/config.local.php`, `config/.installed` or the encryption key. The new version changes UI, navigation and documentation only: **there is no new SQL migration**.
3. Clear your reverse proxy/browser caches so clients receive the new CSS and JavaScript. The app uses same-origin asset version hashes for cache busting.
4. Validate manager/member roles, current team context, task list/board/calendar access, chat, notifications and saved themes in staging.
5. Confirm your existing scheduled tasks, notifications and email configuration still work before production rollout.

## Older v1 versions

Apply each missing incremental `docs/migrations/<version>.sql` **in ascending version order** to a backed-up staging clone, using the detailed historical notes in [v1 upgrade guide](history/v1/UPGRADE-v1.md). Never rerun the fresh-install schema on a populated database. Preserve your private application key and installation lock. Run the disposable-MySQL integration workflow and real authenticated permission checks before rollout.

## Fresh v2 installation

Use the normal [`/install/`](../install/) wizard and current [`schema.sql`](../schema.sql); do **not** manually apply the incremental migrations after a fresh schema import.

[Release notes](RELEASE-2.0.0.md) · [Deployment](DEPLOYMENT.md) · [v1 migration history](history/v1/)
