<?php
/** No database required: RFC 6238 TOTP vectors, secret crypto and CSV neutralization. */
declare(strict_types=1);
putenv('TASKFLOW_APP_KEY=' . base64_encode(str_repeat(chr(1), 32)));
require_once __DIR__ . '/../includes/mfa.php';
require_once __DIR__ . '/../includes/csv.php';

$count = 0;
function assert_taskflow(bool $condition, string $label): void {
    global $count;
    $count++;
    if (!$condition) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}

$secret = mfa_base32_encode('12345678901234567890');
assert_taskflow(mfa_base32_decode($secret) === '12345678901234567890', 'Base32 roundtrip');
// RFC 6238 SHA1 values are eight digits; TaskFlow uses the last six.
foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924'] as $second => $code) {
    assert_taskflow(mfa_totp_at($secret, intdiv($second, 30)) === $code, "RFC 6238 at $second");
}
assert_taskflow(mfa_matching_step($secret, '287082', 0, 59) === 1, 'accepts current TOTP');
assert_taskflow(mfa_matching_step($secret, '287082', 1, 59) === false, 'blocks TOTP reuse');
assert_taskflow(mfa_matching_step($secret, '1234567', 0, 59) === false, 'rejects incorrect code length');
$enc = secret_encrypt($secret);
assert_taskflow(str_starts_with($enc, 'enc:v1:') && !str_contains($enc, $secret), 'MFA secret encrypted');
assert_taskflow(secret_decrypt($enc) === $secret, 'MFA secret decrypt roundtrip');
$recovery = mfa_recovery_code();
assert_taskflow((bool) preg_match('/\A[A-F0-9]{4}(?:-[A-F0-9]{4}){4}\z/', $recovery), 'secure recovery format');
assert_taskflow(mfa_recovery_hash($recovery) === mfa_recovery_hash(strtolower(str_replace('-', '', $recovery))), 'recovery normalizes');
assert_taskflow(mfa_recovery_hash('not a code') === '', 'rejects invalid recovery');
foreach (['=1+1', ' +SUM(1,1)', "\t@unsafe", '-2+5', "\r=BAD()"] as $candidate) {
    assert_taskflow(str_starts_with(taskflow_csv_safe($candidate), "'"), 'CSV neutralizes ' . json_encode($candidate));
}
assert_taskflow(taskflow_csv_safe('Quarterly report') === 'Quarterly report', 'ordinary CSV unaffected');
echo "All $count feature unit tests passed.\n";
