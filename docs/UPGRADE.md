# Upgrading an existing TaskFlow preview to 1.0.0

**Do not run the first-time installer on a populated database.** The v1.0.0 label is a public-version reset, **not** a SQL downgrade or an instruction to delete existing user data.

1. Schedule maintenance and back up the full database and application directory, including any existing `config/config.local.php`, `app_key` and `config/.installed` lock. Test that the backup can be restored.
2. Compare the existing database schema with `schema.sql`. **Apply only migrations your existing deployment is missing**, in original progression order. Run each migration once; several operations are not idempotent. Test on a clone first.
   - Very early installs: inspect `docs/migrations/very-early-schema.sql` and `verify-very-early-schema.sql` cautiously.
   - Security foundation: `docs/migrations/preview-foundation.sql`.
   - Authenticator MFA / search: `docs/migrations/preview-authentication.sql`.
   - Personal workspace / inbox: `docs/migrations/preview-workspace.sql`.
   - Dependencies / reminders: `docs/migrations/preview-workflows.sql`.
3. If upgrading from the original PHP configuration, move live credentials into ignored `config/config.local.php` (see `config/config.local.php.example`), generate/retain a unique `app_key` and run `php bin/lock-existing-install.php` after validating current administrators. **Preserve the existing `app_key` in modern installs** to keep encrypted MFA and SMTP data readable.
4. Copy v1.0.0 source without overwriting your private `config.local.php` or installer lock. Retain HTTPS and web-server rules; ensure `config/`, `includes/`, `bin/`, `tests/` and `docs/` are blocked from HTTP access.
5. Test administrator and member logins, MFA, team boundaries, tasks, dependencies, batch changes, notifications, device revocation, and exports against real MySQL. Resave any plaintext SMTP credentials to encrypt them in the current format.
6. Configure daily cron `php bin/due-reminders.php` and optional mail-worker schedule. Validate backups and user permission boundaries before restoring traffic.

If migrating from an unrecognized schema, **stop and reconcile it manually**. Never clear the installer lock as a repair shortcut; that can expose an account takeover path on a partially configured system.
