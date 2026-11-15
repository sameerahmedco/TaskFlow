<?php
/** RFC 6238-compatible TOTP and one-time recovery code helpers; no external packages. */
declare(strict_types=1);
require_once __DIR__ . '/secrets.php';

function mfa_base32_encode(string $bytes): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    $output = '';
    foreach (str_split($bits, 5) as $part) {
        $output .= $alphabet[bindec(str_pad($part, 5, '0', STR_PAD_RIGHT))];
    }
    return $output;
}

function mfa_base32_decode(string $secret): string {
    if ($secret === '' || !preg_match('/\A[A-Z2-7]+\z/', $secret)) return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($secret) as $character) {
        $idx = strpos($alphabet, $character);
        if ($idx === false) return '';
        $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
    }
    $binary = '';
    foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $octet) {
        $binary .= chr(bindec($octet));
    }
    return $binary;
}

function mfa_totp_at(string $secret, int $step): string {
    $key = mfa_base32_decode($secret);
    if (strlen($key) < 16 || $step < 0) return '';
    $counter = pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff);
    $hmac = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hmac[19]) & 0x0f;
    $num = ((ord($hmac[$offset]) & 0x7f) << 24)
        | (ord($hmac[$offset + 1]) << 16)
        | (ord($hmac[$offset + 2]) << 8)
        | ord($hmac[$offset + 3]);
    return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Returns the unused time step, or false; prevents TOTP replay across concurrent requests. */
function mfa_matching_step(string $secret, string $code, int $lastUsedStep = 0, ?int $now = null): int|false {
    if (!preg_match('/\A[0-9]{6}\z/', $code)) return false;
    $step = intdiv($now ?? time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        $candidate = $step + $offset;
        if ($candidate > $lastUsedStep && hash_equals(mfa_totp_at($secret, $candidate), $code)) {
            return $candidate;
        }
    }
    return false;
}

function mfa_recovery_code(): string {
    $raw = strtoupper(bin2hex(random_bytes(10)));
    return implode('-', str_split($raw, 4));
}

function mfa_recovery_hash(string $code): string {
    $normalized = strtoupper(str_replace(['-', ' '], '', trim($code)));
    if (!preg_match('/\A[A-F0-9]{20}\z/', $normalized)) return '';
    return hash_hmac('sha256', $normalized, taskflow_secret_key());
}

/** Try the one-time TOTP first, then a one-time recovery code; both are consumed atomically. */
function mfa_consume_code(PDO $pdo, int $userId, string $code): bool {
    $stmt = $pdo->prepare('SELECT secret_ciphertext,last_used_step FROM user_mfa WHERE user_id=?');
    $stmt->execute([$userId]);
    $record = $stmt->fetch();
    if (!$record) return false;
    if (!str_starts_with($record['secret_ciphertext'], 'enc:v1:')) throw new RuntimeException('MFA secret encryption is invalid.');
    if (preg_match('/\A[0-9]{6}\z/', $code)) {
        $step = mfa_matching_step(secret_decrypt($record['secret_ciphertext']), $code, (int) $record['last_used_step']);
        if ($step === false) return false;
        $update = $pdo->prepare('UPDATE user_mfa SET last_used_step=? WHERE user_id=? AND last_used_step < ?');
        $update->execute([$step, $userId, $step]);
        return $update->rowCount() === 1;
    }
    $hash = mfa_recovery_hash($code);
    if ($hash === '') return false;
    $use = $pdo->prepare('UPDATE mfa_recovery_codes SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND code_hash=? AND used_at IS NULL');
    $use->execute([$userId, $hash]);
    return $use->rowCount() === 1;
}

/** Generate 10 recovery codes; return plaintext exactly once. */
function mfa_replace_recovery_codes(PDO $pdo, int $userId): array {
    $codes = [];
    $pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id=?')->execute([$userId]);
    $insert = $pdo->prepare('INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (?,?)');
    for ($i = 0; $i < 10; $i++) {
        $code = mfa_recovery_code();
        $insert->execute([$userId, mfa_recovery_hash($code)]);
        $codes[] = $code;
    }
    return $codes;
}
