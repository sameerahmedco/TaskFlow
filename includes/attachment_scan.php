<?php
/**
 * TaskFlow v3: fail-closed attachment malware scanning over a local ClamAV
 * clamd Unix-domain socket. No shell commands, external URLs, or public file
 * paths are involved. PHP temporary upload bytes are scanned before storage.
 */
declare(strict_types=1);

/** Production cannot disable scanning for uploads; development without a socket is explicit. */
function taskflow_attachment_scan_required(?string $environment = null): bool {
    $environment ??= defined('APP_ENV') ? APP_ENV : (getenv('TASKFLOW_APP_ENV') ?: 'production');
    return $environment !== 'development';
}

/** Only allow a local Unix-domain socket path, never a TCP endpoint or a symlink. */
function taskflow_clamd_socket_path(mixed $value): string {
    if (!is_string($value) || $value === '' || strlen($value) > 200 || $value[0] !== '/'
        || str_contains($value, "\0") || preg_match('~(?:^|/)\.\.?(/|$)~', $value)) {
        throw new InvalidArgumentException('Configure an absolute ClamAV Unix socket path.');
    }
    return $value;
}

function taskflow_clamd_timeout(): int {
    $value = getenv('TASKFLOW_CLAMD_TIMEOUT_SECONDS');
    if ($value === false || $value === '') return 12;
    if (!preg_match('/\A(?:[1-9]|[12][0-9]|30)\z/D', $value)) {
        throw new RuntimeException('ClamAV timeout must be between 1 and 30 seconds.');
    }
    return (int)$value;
}

/** A deterministic parser: unknown responses or scan errors never mean clean. */
function taskflow_clamd_scan_response(string $response): void {
    $response = rtrim($response, "\r\n");
    if (str_ends_with($response, "\0")) $response = substr($response, 0, -1);
    if (str_contains($response, "\0") || str_contains($response, "\n") || str_contains($response, "\r")) {
        throw new RuntimeException('Attachment scanner returned multiple responses.');
    }
    if ($response === 'stream: OK') return;
    if (preg_match('/\Astream: [^\x00\r\n]{1,300} FOUND\z/D', $response) === 1) {
        throw new DomainException('The attachment was rejected by the malware scanner.');
    }
    throw new RuntimeException('Attachment scanner returned an unexpected response.');
}

function taskflow_clamd_socket(string $path, int $seconds) {
    taskflow_clamd_socket_path($path);
    clearstatcache(true, $path);
    if (is_link($path) || @filetype($path) !== 'socket') {
        throw new RuntimeException('Local ClamAV socket is not available.');
    }
    $errno = 0;
    $error = '';
    $socket = @stream_socket_client('unix://' . $path, $errno, $error, min(2, $seconds));
    if (!is_resource($socket)) throw new RuntimeException('Local ClamAV connection failed.');
    if (!stream_set_blocking($socket, false)) {
        fclose($socket);
        throw new RuntimeException('Local ClamAV socket configuration failed.');
    }
    return $socket;
}

/** I/O deadline is global, not reset per chunk, preventing indefinite PHP workers. */
function taskflow_clamd_wait($socket, bool $writable, float $deadline): void {
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) throw new RuntimeException('Attachment scanner timed out.');
    $seconds = (int)$remaining;
    $microseconds = max(1, (int)(($remaining - $seconds) * 1_000_000));
    $read = $writable ? [] : [$socket];
    $write = $writable ? [$socket] : [];
    $except = [];
    $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
    if ($ready === false) throw new RuntimeException('Attachment scanner socket error.');
    if ($ready === 0) throw new RuntimeException('Attachment scanner timed out.');
}

function taskflow_clamd_write($socket, string $data, float $deadline): void {
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        taskflow_clamd_wait($socket, true, $deadline);
        $written = @fwrite($socket, substr($data, $offset));
        if ($written === false) throw new RuntimeException('Attachment scanner write failed.');
        if ($written === 0 && feof($socket)) throw new RuntimeException('Attachment scanner connection closed.');
        $offset += $written;
    }
}

function taskflow_clamd_reply($socket, float $deadline): string {
    $reply = '';
    while (!str_contains($reply, "\0") && !str_contains($reply, "\n")) {
        taskflow_clamd_wait($socket, false, $deadline);
        $part = @fread($socket, 1024);
        if ($part === false || ($part === '' && feof($socket))) {
            throw new RuntimeException('Attachment scanner did not complete the scan.');
        }
        $reply .= $part;
        if (strlen($reply) > 4096) throw new RuntimeException('Attachment scanner response too large.');
    }
    return $reply;
}

/** Liveness probe for deployment preflight. Not a malware detection test. */
function taskflow_attachment_scanner_ping(?string $socketPath = null): bool {
    $socketPath ??= getenv('TASKFLOW_CLAMD_SOCKET') ?: '';
    try {
        $socket = taskflow_clamd_socket($socketPath, min(3, taskflow_clamd_timeout()));
        try {
            $deadline = microtime(true) + min(3, taskflow_clamd_timeout());
            taskflow_clamd_write($socket, "zPING\0", $deadline);
            return trim(taskflow_clamd_reply($socket, $deadline), "\0\r\n") === 'PONG';
        } finally { fclose($socket); }
    } catch (Throwable) { return false; }
}

/**
 * Scan a private PHP upload temp file with ClamAV's length-framed INSTREAM.
 * Success returns normally; detection raises DomainException (422); an
 * unavailable/ambiguous engine raises RuntimeException (503). Never save
 * the private attachment until this returns successfully.
 */
function taskflow_attachment_scan_file(string $file, ?string $socketPath = null): void {
    $socketPath ??= getenv('TASKFLOW_CLAMD_SOCKET') ?: '';
    if ($socketPath === '' && !taskflow_attachment_scan_required()) return;
    $socketPath = taskflow_clamd_socket_path($socketPath);
    if (!is_file($file) || is_link($file)) throw new RuntimeException('Invalid attachment upload file.');
    $size = @filesize($file);
    $maxBytes = defined('TASKFLOW_MAX_ATTACHMENT_BYTES') ? TASKFLOW_MAX_ATTACHMENT_BYTES : 8 * 1024 * 1024;
    if (!is_int($size) || $size < 1 || $size > $maxBytes) {
        throw new RuntimeException('Invalid attachment upload size.');
    }
    $input = @fopen($file, 'rb');
    if (!is_resource($input)) throw new RuntimeException('Attachment upload cannot be read.');
    try {
        $socket = taskflow_clamd_socket($socketPath, taskflow_clamd_timeout());
        try {
            $deadline = microtime(true) + taskflow_clamd_timeout();
            taskflow_clamd_write($socket, "zINSTREAM\0", $deadline);
            $total = 0;
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) throw new RuntimeException('Attachment upload read failed.');
                if ($chunk === '') break;
                $total += strlen($chunk);
                if ($total > $maxBytes) throw new RuntimeException('Attachment upload exceeds the scan limit.');
                taskflow_clamd_write($socket, pack('N', strlen($chunk)) . $chunk, $deadline);
            }
            if ($total !== $size) throw new RuntimeException('Attachment upload changed during scanning.');
            taskflow_clamd_write($socket, pack('N', 0), $deadline);
            taskflow_clamd_scan_response(taskflow_clamd_reply($socket, $deadline));
        } finally { fclose($socket); }
    } finally { fclose($input); }
}
