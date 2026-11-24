# TaskFlow security

## Supported version

TaskFlow v3 is a self-hosted, single-organization application. Use maintained PHP 8.3+, Oracle MySQL 8+ and fully patched HTTPS hosting. Separate organizations require separate installations and databases; the application is not a multi-tenant SaaS platform.

## Reporting a vulnerability

Report potential vulnerabilities privately to the repository maintainer. The maintainer should enable GitHub private vulnerability reporting under **Security → Advisories** before publication. Do not include credentials, customer data or exploit details in public issues. Only assess systems you are authorized to test.

## Secure operation

- Restrict the installer to authorized operators until setup is complete. Keep the one-time key, configuration, installation lock, environment variables and backups private.
- Require HTTPS. Set the exact trusted proxy IP only when the TLS edge controls forwarded protocol headers. Never run public instances in development mode.
- Deny HTTP access to `config/`, `includes/`, `bin/`, `docs/`, hidden files and SQL. Do not expose application source archives or private attachment storage. Use the supplied Apache or [Nginx configuration](docs/deployment/nginx-taskflow.conf.example).
- Use a unique generated application key and preserve it in protected backups. Losing it can make encrypted MFA, SMTP settings and queued mail unrecoverable. Never commit secrets to source control.
- Use a dedicated database account, InnoDB, strict SQL mode and foreign-key checks. Remove unnecessary DDL privileges after setup and grant them only for controlled migrations.
- Enable [MFA for administrators](docs/ADMIN-MFA.md). MFA is user-driven; enforce it at a trusted identity-aware proxy if mandatory MFA is required. Preserve recovery codes offline.
- Public registration is not supported. Use administrator-issued invitations. Configure trusted SMTP with TLS for recovery and invitations.
- Limit public request rates at the edge, monitor failed sign-ins, application errors and the mail queue, and restrict access to logs.
- New production attachments require private storage and a healthy local ClamAV scanner. Missing or failed scanning blocks uploads rather than allowing an unsafe fallback. See [attachment scanning](docs/deployment/clamav-attachment-scanning.md). Older uploaded files are not automatically rescanned.
- Back up and restore-test the database, private configuration, app key, installer lock and attachments together. Never initialize an established database with the fresh-install schema.

## Verification and known limitations

The deployment preflight and anonymous HTTPS exposure checker are operational tools, not penetration tests. Authentication and team boundaries, install and migration behavior, transaction rollback, backups, real scanner detection, concurrency, accessibility and performance must be verified on a production-like staging host. A separate independent security assessment and owner sign-off are necessary before unrestricted public hosting.

No real MySQL integration, production HTTPS deployment, real ClamAV detection, recovery drill, load certification or independent penetration test was completed as part of the distribution cleanup. Default PHP session storage is local to one node; shared protected sessions are required for multi-node deployment. Monthly reports and large organization views may require load and query profiling. There is no claim of enterprise certification.

See the [deployment guide](docs/DEPLOYMENT.md) for the release acceptance checklist.
