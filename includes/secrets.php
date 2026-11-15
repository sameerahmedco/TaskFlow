<?php
/** Authenticated encryption for small application secrets. Requires a private APP_KEY. */
require_once __DIR__ . '/../config/config.php';
function taskflow_secret_key(): string {
    $key = base64_decode(APP_KEY, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('Missing secure APP_KEY configuration.');
    }
    return $key;
}
function secret_encrypt(string $plaintext): string {
    if ($plaintext === '') return '';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'enc:v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, taskflow_secret_key()));
}
function secret_decrypt(string $saved): string {
    if ($saved === '') return '';
    if (!str_starts_with($saved, 'enc:v1:')) {
        // Backwards compatibility for pre-upgrade SMTP credentials.
        // Replace/resave the SMTP password in Advanced Settings after upgrading.
        return $saved;
    }
    $blob = base64_decode(substr($saved, 7), true);
    if ($blob === false || strlen($blob) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RuntimeException('Encrypted SMTP setting is invalid.');
    }
    $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, taskflow_secret_key());
    if ($plaintext === false) throw new RuntimeException('Cannot decrypt SMTP settings with this application key.');
    return $plaintext;
}
