# Upgrade to TaskFlow v3

This distribution cleanup adds no database tables or columns. The existing v3 schema remains unchanged. All earlier SQL migration files are retained because existing installations may still require them.

## Fresh installation

Use the first-run installer on an empty database. It imports the current `schema.sql`; do not additionally run the migration files on a newly installed database.

## Existing installation

1. Schedule downtime or freeze writes. Back up and restore-test the database, application files, private configuration (`config/config.local.php`), installation lock (`config/.installed`), original application key and private attachments.
2. Identify the installed schema version on a separate staging clone. Apply **only missing** migrations, exactly once, with the required database privileges. Do not blindly apply every SQL file or use filename sorting as a migration plan.
3. For an existing latest v2 or earlier v3 installation that already has the v2 schema, apply `migrations/v3-discussions.sql` (if missing), followed by `migrations/v3-task-reviews.sql` (if missing). The Discussions migration adds comment-thread metadata; the Reviews migration adds the review request and event tables. If the installation is already on the current v3 schema, no migration is needed.
4. For older v1 databases, inspect the existing schema and migration prerequisites; apply the relevant numbered migrations in numeric release order (1.1.0, 1.2.0, ..., 1.19.0). For very early or preview-era installs, the `very-early-schema.sql`, `verify-very-early-schema.sql`, and `preview-*.sql` files are retained as compatibility material. Inspect their prerequisites and already-applied DDL before use; these are not a universal sequence. If the starting schema is uncertain, consult the maintainer before changing it.
5. Deploy the replacement application files without deleting or overwriting private configuration, installation lock, key or attachments. Do not rerun first-time setup or re-import `schema.sql`.
6. Clear application asset caches, run `php bin/release-preflight.php` on the installed staging host, and verify real login, roles, project access, comments, reviews, notifications and attachments before production rollout.

[Migration directory](migrations/) · [Deployment](DEPLOYMENT.md) · [Security](../SECURITY.md)

**Rollback:** MySQL DDL may commit independently of transactions. Restore the verified pre-upgrade database, application files, encryption key and matching private files together if an upgrade fails. Do not assume reverting PHP files alone will restore compatibility.
