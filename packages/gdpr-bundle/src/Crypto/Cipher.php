<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Crypto;

use Opus125\GdprBundle\Exception\DecryptionFailedException;

/**
 * Authenticated symmetric encryption for key wrapping and at-rest field
 * protection.
 *
 * Wraps libsodium's XChaCha20-Poly1305 (IETF) AEAD: 256-bit keys, 192-bit random
 * nonces (wide enough to generate randomly without a counter) and a Poly1305 tag
 * over ciphertext + associated data. The crypto is libsodium's, never
 * hand-rolled — only the envelope framing is ours.
 *
 * Output blobs are `nonce || ciphertext+tag` as raw bytes; callers base64 them at
 * the storage boundary. (A deliberate sibling of the Audit bundle's cipher: the
 * two bundles stay decoupled, so each owns its primitive.)
 */
final class Cipher
{
    public const int KEY_BYTES = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    private const int NONCE_BYTES = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    /**
     * Generate a fresh random 256-bit key.
     */
    public function generateKey(): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
    }

    /**
     * @param string $key       32-byte key
     * @param string $plaintext data to protect
     * @param string $aad       associated data authenticated but not encrypted
     *
     * @return string `nonce || ciphertext` (raw bytes)
     */
    public function encrypt(string $key, string $plaintext, string $aad = ''): string
    {
        $this->assertKey($key);

        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);

        return $nonce.$ciphertext;
    }

    /**
     * @param string $key  32-byte key
     * @param string $blob `nonce || ciphertext` as produced by {@see encrypt()}
     * @param string $aad  the same associated data used when encrypting
     *
     * @throws DecryptionFailedException if the key/aad is wrong or the blob is corrupt
     */
    public function decrypt(string $key, string $blob, string $aad = ''): string
    {
        $this->assertKey($key);

        if (\strlen($blob) <= self::NONCE_BYTES) {
            throw new DecryptionFailedException('Ciphertext blob is too short to contain a nonce.');
        }

        $nonce = substr($blob, 0, self::NONCE_BYTES);
        $ciphertext = substr($blob, self::NONCE_BYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);

        if (false === $plaintext) {
            throw new DecryptionFailedException('Authenticated decryption failed (wrong key, wrong associated data, or tampered ciphertext).');
        }

        return $plaintext;
    }

    private function assertKey(string $key): void
    {
        if (self::KEY_BYTES !== \strlen($key)) {
            throw new \InvalidArgumentException(\sprintf('Encryption key must be exactly %d bytes, got %d.', self::KEY_BYTES, \strlen($key)));
        }
    }
}
