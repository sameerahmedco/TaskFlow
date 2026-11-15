<?php
/** TaskFlow runtime settings: secrets belong in environment or config.local.php (gitignored). */
declare(strict_types=1);
$localFile = __DIR__ . '/config.local.php';
$local = is_file($localFile) ? require $localFile : [];
if (!is_array($local)) {
    throw new RuntimeException('Invalid local configuration.');
}
$setting = static function (string $key, string $fallback = '') use ($local): string {
    $env = getenv('TASKFLOW_' . $key);
    return $env !== false ? (string) $env : (string) ($local[strtolower($key)] ?? $fallback);
};

define('DB_HOST', $setting('DB_HOST', 'localhost'));
define('DB_PORT', $setting('DB_PORT', '3306'));
define('DB_NAME', $setting('DB_NAME'));
define('DB_USER', $setting('DB_USER'));
define('DB_PASS', $setting('DB_PASS'));
define('APP_URL', rtrim($setting('APP_URL'), '/'));
define('APP_KEY', $setting('APP_KEY'));
define('APP_ENV', $setting('APP_ENV', 'production'));
define('SESSION_LIFETIME', 60 * 60 * 8);
define('REMEMBER_ME_DAYS', 30);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
define('APP_INSTALLED', is_file(__DIR__ . '/.installed'));

define('APP_NAME', 'TaskFlow');
date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Fail closed even if development mode is configured on a public host.
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_name('taskflow_sid');

/** Do not trust X-Forwarded-Proto from arbitrary clients. Set HTTPS at the edge. */
function taskflow_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $trusted = getenv('TASKFLOW_TRUSTED_PROXY_IP');
    return $trusted !== false && $trusted !== ''
        && hash_equals($trusted, $_SERVER['REMOTE_ADDR'] ?? '')
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
function taskflow_is_localhost(): bool {
    // A local upstream proxy must not silently turn an external HTTP site into
    // an insecure development exception. The request host must also be local.
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) return false;
    $host = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    return in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
}
// Show useful exceptions only on an explicitly local development host.
if (APP_ENV === 'development' && taskflow_is_localhost()) ini_set('display_errors', '1');
$isHttps = taskflow_is_https();
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'domain' => '',
    'secure' => $isHttps || !taskflow_is_localhost(),
    'httponly' => true, 'samesite' => 'Strict',
]);

if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: no-store, private');
    if ($isHttps) header('Strict-Transport-Security: max-age=31536000');
    if (!$isHttps && !taskflow_is_localhost()) {
        http_response_code(403);
        exit('TaskFlow requires HTTPS. Configure TLS or a trusted reverse proxy.');
    }
}

set_exception_handler(static function (Throwable $e): void {
    error_log('TaskFlow uncaught ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'An internal error occurred. Contact your administrator.']);
    exit;
});
