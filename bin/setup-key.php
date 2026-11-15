<?php
/** Generate a private one-time installation token on the server. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir = dirname(__DIR__) . '/config';
if (is_file($dir . '/.installed') || is_file($dir . '/config.local.php')) {
    fwrite(STDERR, "TaskFlow already configured; refusing to issue an install key.\n");
    exit(1);
}
$path = $dir . '/.setup-key';
if (file_exists($path)) {
    fwrite(STDERR, "A setup key already exists. Delete it only if deliberately rotating the key.\n");
    exit(1);
}
$key = bin2hex(random_bytes(32));
$handle = @fopen($path, 'x');
if (!$handle) { fwrite(STDERR, "Cannot create config/.setup-key. Check file permissions.\n"); exit(1); }
chmod($path, 0600);
fwrite($handle, $key . "\n");
fclose($handle);
echo "One-time TaskFlow setup key (keep private):\n$key\n\n";
echo "Open https://YOUR_DOMAIN/install/ and enter this key in the Security step.\n";
