<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function fieldEncryptionKeyForVersion(int $version): string
{
    $keyName = $version === 2 ? 'FIELD_ENCRYPTION_KEY_V2' : 'FIELD_ENCRYPTION_KEY';
    $key = base64_decode(requiredConfig($keyName), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException($keyName . ' must encode 32 random bytes.');
    }
    return $key;
}

// Compatibility with previously installed MFA security package.
function fieldEncryptionKey(): string
{
    return fieldEncryptionKeyForVersion(1);
}

function encryptSensitiveSetting(string $key, string $plaintext): string
{
    if ($plaintext === '') return '';
    $version = config('FIELD_ENCRYPTION_KEY_V2') !== null ? 2 : 1;
    $nonce = random_bytes(12);
    $tag = '';
    $aad = 'digitalinvoice:setting:' . $key . ($version === 2 ? ':v2' : '');
    $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', fieldEncryptionKeyForVersion($version), OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
    if ($encrypted === false) throw new RuntimeException('Unable to encrypt integration credential.');
    return 'enc:v' . $version . ':' . base64_encode($nonce . $tag . $encrypted);
}

function decryptSensitiveSetting(string $key, string $stored): string
{
    if ($stored === '') return '';
    if (!preg_match('/^enc:v([12]):(.+)$/sD', $stored, $matches)) {
        throw new RuntimeException('Stored NRS credentials require encryption migration.');
    }
    $version = (int) $matches[1];
    $raw = base64_decode($matches[2], true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Encrypted credential is corrupted.');
    $aad = 'digitalinvoice:setting:' . $key . ($version === 2 ? ':v2' : '');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', fieldEncryptionKeyForVersion($version), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
    if ($plain === false) throw new RuntimeException('Encrypted credential authentication failed.');
    return $plain;
}
