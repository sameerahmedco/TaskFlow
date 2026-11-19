<?php
/** Encrypt capability-bearing emails before queueing; no live SMTP/DB needed. */
declare(strict_types=1);
putenv('TASKFLOW_APP_KEY=' . base64_encode(str_repeat(chr(10), 32)));
require_once __DIR__ . '/../includes/secrets.php';
$link = 'https://taskflow.example.test/recover/#token=' . str_repeat('a',64);
$payload = "Use this private link: $link";
$encrypted = secret_encrypt($payload);
if (!str_starts_with($encrypted,'enc:v1:') || str_contains($encrypted,$link)) {
    fwrite(STDERR,"FAIL plaintext recovery link in queued ciphertext\n"); exit(1);
}
if (secret_decrypt($encrypted) !== $payload) {
    fwrite(STDERR,"FAIL encrypted message cannot be recovered by worker\n"); exit(1);
}
try {secret_decrypt(substr($encrypted,0,-2).'AZ'); fwrite(STDERR,"FAIL tampering was not detected\n"); exit(1);}
catch(RuntimeException $e) { echo "PASS authenticated mail encryption rejects tampering\n"; }
echo "PASS queued email contains authenticated ciphertext, never the bearer link\n";
