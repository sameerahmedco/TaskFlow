# Attachment malware scanning — TaskFlow v3 operations

TaskFlow's private-file upload endpoint now uses the local **ClamAV `clamd` Unix-domain socket** and the `INSTREAM` protocol. **On an installed production application, attachment uploads fail closed unless a reachable scanner reports `stream: OK`.** A scan reporting malware returns HTTP 422; a missing/unreachable/overloaded scanner or an unrecognized response returns HTTP 503. Task lists, messages and already stored attachments remain available when the scanner is down.

This is a release-hardening change, not a new user-facing feature. There are no database changes.

## Configure on a production server

1. Install and maintain a supported ClamAV daemon and virus-signature updater using the operating system's trusted packages. Configure scheduled definition updates and monitor their success/failure. Use a dedicated scanner service account. TaskFlow does **not** ship ClamAV or signature data.
2. Configure `clamd` with a **local Unix-domain socket**, such as `LocalSocket /run/clamav/clamd.ctl`. The actual pathname varies by operating system and packaging. Configure a stream limit **larger than TaskFlow's 8 MiB maximum upload** (e.g., 10 MiB or more), so ClamAV does not silently reject uploads at its own limit.
3. Allow the PHP worker to connect to this socket using a restricted owner/group; never make it world-writable. Ensure the socket is mounted into the PHP container/namespace if ClamAV runs elsewhere. A TCP scanner URL is not accepted.
4. In the **PHP runtime environment**, set `TASKFLOW_CLAMD_SOCKET=/run/clamav/clamd.ctl`. If PHP-FPM clears environment variables, pass the setting through the trusted pool configuration. Optionally set `TASKFLOW_CLAMD_TIMEOUT_SECONDS=12` (valid range 1–30; default 12). Do not set `TASKFLOW_APP_ENV=development` on public servers.
5. Configure `TASKFLOW_ATTACHMENTS_DIR` as a private writable directory outside the web root. Its existence alone does not bypass scanning.
6. Run `php bin/release-preflight.php --json` **as the PHP worker identity** on the installed production host. When private storage is enabled, **`attachment_malware_scanner` must pass**. The preflight sends a `PING` to the daemon, **not** a malware test.
7. In disposable staging, upload an ordinary allowed file and a recognized antivirus test specimen using the approved test procedure (e.g., the harmless official EICAR test string in an allowed `.txt` file). Confirm clean uploads succeed, the test specimen returns HTTP 422 without creating a file or database row, and switching off `clamd` returns HTTP 503. Verify no plain HTTP or web-root upload fallback is enabled.

> Production safety: absence of a scanner **disables new uploads** rather than silently falling back to MIME-only validation. Explicit local development (`TASKFLOW_APP_ENV=development`) may omit the socket for developer convenience; this mode must never be used for the public host. If a socket is configured even in development, it is used and errors still fail closed.

## Operational requirements

- The API scans the PHP temporary upload *before* private storage and the metadata/audit transaction. It does not invoke shell commands or send the file to a remote URL. Socket I/O is bounded by one overall deadline, so a stalled scanner cannot indefinitely occupy an upload worker.
- Restrict file types and sizes as before. Antivirus cannot guarantee a file is safe and should be combined with updated definitions, patching, private storage, least privilege, backups, HTTPS, and download-only `Content-Disposition: attachment`.
- **Existing uploads from before this change are not retroactively scanned.** Evaluate whether to scan historical private storage offline under an administrator-approved procedure; do not serve or expose the private bytes through HTTP to do so.
- Monitor 422/503 upload rates without logging attachment contents. Alert operators on scanner unavailability, outdated signatures, low disk space, or high scan latency. Keep `clamd` and PHP compatible with each other after upgrades.
- Offline regression tests use a **simulated** ClamAV daemon to check wire framing and failure responses. They do not prove actual detection. Real staging with updated ClamAV and MySQL-backed upload tests remain required before unrestricted public deployment.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Preflight `attachment_malware_scanner` fails | Socket path exists, is a Unix socket, permissions allow PHP worker to connect, daemon answers `PING`, and PHP-FPM inherited the environment variable |
| Upload returns 503 | Scanner down, temporary scan errors, invalid response, stream-size restriction or timeout; check daemon logs and socket permissions |
| Upload returns 422 | Scanner reported a detected threat; do not retry the same file until it has been investigated |
| PHP development uploads work but production uploads do not | This is expected unless a production scanner is configured; never switch the public app to development mode to bypass it |
