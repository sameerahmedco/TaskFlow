# TaskFlow security policy

## Supported deployments

The current version is a security-hardening release, not a claim of formal certification. Deploy with maintained PHP 8.2+, MySQL 8+, a valid TLS configuration and patched server packages. PHP's default on-disk session storage is appropriate for one node; configure shared session handling if scaling to several web nodes.

## Report vulnerabilities privately

Avoid public issues containing exploit details, user data, database dumps or credentials. The repository owner should enable **GitHub private vulnerability reporting** under Security → Advisories. Until a security contact is configured, communicate privately with the repository maintainer. Do not test against a public organization without authorization.

## Security baseline

- Never enable public signup unless a complete invitation, verification and role flow is implemented.
- Run the one-time setup on a private/restricted host; the CLI-generated 256-bit setup key is required.
- Keep `config/config.local.php`, `config/.setup-key`, `.installed` and environment variables private.
- Keep the web root read-only where possible after setup. Never expose `.git`, `config`, `includes`, `docs`, or `bin` over HTTP.
- Use HTTPS; only trust forwarding headers from a proxy you explicitly allowlisted via `TASKFLOW_TRUSTED_PROXY_IP`.
- Use unique long passwords for all administrators and rotate all known credentials after upgrading much older installations. Enable additional identity protection at your reverse proxy if available.
- Set a unique `APP_KEY`; preserve it securely across backups. Changing it makes encrypted SMTP settings unreadable.
- Use a dedicated DB user and the least privilege needed. Remove DDL privileges after setup.
- Monitor application/HTTP logs, backups, failed logins and outbox status; run maintenance regularly.
- Do not share a MySQL database across untrusted organizations. Separate deployments and credentials are required.
- Do not publish without a successful MySQL integration run, tested restore procedure, OWASP-style application assessment and load testing.

## Known limitations / next security milestones

- **Built-in optional TOTP MFA with recovery codes** is available, but no organization-wide MFA enforcement, WebAuthn/passkeys, SSO/SAML, verified invitations, or administrator approval workflow. Put sensitive deployments behind an identity-aware proxy with enforced MFA until those exist.
- **No formal penetration test** or independent review has been completed.
- Login throttling uses DB counts per IP and account; distributed rate control should be enforced by an edge proxy or WAF for high-risk public deployments.
- Session storage is host-local by default. Multi-node deployments must centralize session storage and protect shared keys.
- Task lists and search now paginate, but monthly/organization views are not yet fully paginated; heavy organizations need query and load profiling. The search uses SQL LIKE, which can be slow on large databases.
- Existing SMTP plaintext from old installs must be **resaved** after upgrade to encrypt it. New saves use sodium secretbox with your private APP_KEY.
- Email contents are plaintext in the optional database queue before delivery and are retained temporarily; protect database backups and worker accounts accordingly.
- If using Nginx, `.htaccess` is ignored: apply the deny rules in `docs/nginx-example.conf`.
- A true multitenant SaaS offering needs per-org isolation checks on every entity, tenant-aware auth and adversarial tests. Currently this is one org per DB.

## MFA and recovery codes

TOTP secrets are encrypted using the private APP_KEY, and recovery codes are HMAC-hashed, displayed exactly once and consumed atomically. Verification enforces replay checks and bounded challenges. Enrolling/disabling MFA invalidates remembered devices. Keep APP_KEY backed up securely; losing it will prevent decryption of existing MFA registrations as well as SMTP secrets. See [`docs/ADMIN-MFA.md`](docs/ADMIN-MFA.md).

**Upgrade warning:** Do not assume v1.0.0 is a SQL rollback. Refer to [`docs/UPGRADE.md`](docs/UPGRADE.md) for one-time preview migrations and never run the installer on an existing database.
