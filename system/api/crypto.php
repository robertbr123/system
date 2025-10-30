<?php
// Simple reversible encryption helpers for storing sensitive values like device passwords.
// IMPORTANT: Change ENCRYPTION_KEY to a strong, secret key in production.

if (!defined('ENCRYPTION_KEY')) {
    // 32-byte key for AES-256-CBC. Replace with your own secret and keep it out of version control if possible.
    define('ENCRYPTION_KEY', 'change-this-32-byte-secret-key-123456');
}

function encrypt_secret(string $plaintext): string {
    if ($plaintext === '') return '';
    $key = ENCRYPTION_KEY;
    $iv = random_bytes(16); // AES block size 16 bytes
    $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        throw new Exception('Falha ao criptografar');
    }
    // Store iv + ciphertext as base64
    return base64_encode($iv . $ciphertext);
}

function decrypt_secret(?string $encoded): string {
    if (!$encoded) return '';
    $data = base64_decode($encoded, true);
    if ($data === false || strlen($data) < 17) return '';
    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    $key = ENCRYPTION_KEY;
    $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) return '';
    return $plaintext;
}
