<?php
/** Explicit CLI-only lock for existing sites, after the new migration and config are applied. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
if (!is_file($root . '/config/config.local.php') && (DB_NAME === '' || DB_USER === '')) {
    fwrite(STDERR, "Configure DB settings via environment or private config.local.php first.\n");
    exit(1);
}
$pdo = get_pdo();
try {
    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.is_active=1 AND r.role='administrator'")->fetchColumn();
    $pdo->query('SELECT auth_version FROM users LIMIT 1');
    $pdo->query('SELECT organization_name FROM app_settings LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Migration missing or database invalid. Follow docs/UPGRADE.md and apply only missing preview migrations.\n");
    exit(1);
}
if ($adminCount < 1) { fwrite(STDERR, "No active administrator found; refusing to lock incomplete install.\n"); exit(1); }
$marker = $root . '/config/.installed';
$handle = @fopen($marker, 'x');
if (!$handle && !is_file($marker)) { fwrite(STDERR, "Cannot write install marker.\n"); exit(1); }
if ($handle) { chmod($marker, 0600); fwrite($handle, "installed\n"); fclose($handle); }
@unlink($root . '/config/.setup-key');
echo "Existing TaskFlow installation secured and locked.\n";
