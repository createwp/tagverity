<?php
// ─── Token encryption — Google OAuth refresh tokens ──────────────────────────
// AES-256-GCM with a random nonce per encrypt call.
// The encryption key is loaded from TOKEN_ENCRYPTION_KEY env var; never stored in DB.

/**
 * Encrypt a plaintext string using AES-256-GCM.
 *
 * Returns a base64-encoded string: nonce(12 bytes) + tag(16 bytes) + ciphertext.
 *
 * @param string $plaintext  The value to encrypt (e.g. a Google refresh token)
 * @return string            Base64-encoded encrypted blob
 * @throws RuntimeException  If encryption key is missing or openssl fails
 */
function encrypt_token(string $plaintext): string {
    $key = base64_decode(TOKEN_ENCRYPTION_KEY);
    if (strlen($key) !== 32) {
        throw new RuntimeException('TOKEN_ENCRYPTION_KEY must be a 32-byte base64-encoded string.');
    }
    $nonce = random_bytes(12); // GCM standard nonce size
    $tag   = '';
    $ciphertext = openssl_encrypt(
        $plaintext, 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, $nonce, $tag, '', 16
    );
    if ($ciphertext === false) {
        throw new RuntimeException('openssl_encrypt failed.');
    }
    return base64_encode($nonce . $tag . $ciphertext);
}

/**
 * Decrypt a blob previously created by encrypt_token().
 *
 * @param string $blob  Base64-encoded encrypted blob
 * @return string       Decrypted plaintext
 * @throws RuntimeException  If decryption fails (wrong key or tampered data)
 */
function decrypt_token(string $blob): string {
    $key = base64_decode(TOKEN_ENCRYPTION_KEY);
    if (strlen($key) !== 32) {
        throw new RuntimeException('TOKEN_ENCRYPTION_KEY must be a 32-byte base64-encoded string.');
    }
    $raw        = base64_decode($blob);
    $nonce      = substr($raw, 0, 12);
    $tag        = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext  = openssl_decrypt(
        $ciphertext, 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, $nonce, $tag
    );
    if ($plaintext === false) {
        throw new RuntimeException('decrypt_token: decryption failed — wrong key or tampered data.');
    }
    return $plaintext;
}
