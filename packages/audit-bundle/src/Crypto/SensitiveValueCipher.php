<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

use Opus\AuditBundle\Crypto\Exception\DecryptionFailedException;

/**
 * Encrypts and decrypts a single `#[Sensitive]` value as a self-describing
 * envelope, and renders unreadable (shredded) values as a placeholder.
 *
 * The key comes from the {@see SubjectKeyProviderInterface} (keyed by the
 * audited entity). When that key is null — the subject has been erased — or the
 * ciphertext no longer authenticates, decryption yields {@see REDACTED} rather
 * than throwing, while the ciphertext bytes (and the hash-chain) stay intact.
 */
final class SensitiveValueCipher
{
    public const string REDACTED = '[redacted: erased]';

    private const string ENVELOPE_MARKER = '__opus_enc';
    private const int ENVELOPE_VERSION = 1;
    private const string AAD = 'opus-audit';

    public function __construct(
        private readonly Cipher $cipher,
    ) {
    }

    /**
     * @return array<string, mixed> the envelope (JSON-able)
     */
    public function encrypt(mixed $value, string $key): array
    {
        $blob = $this->cipher->encrypt($key, json_encode($value, \JSON_THROW_ON_ERROR), self::AAD);

        return [
            self::ENVELOPE_MARKER => self::ENVELOPE_VERSION,
            'ct' => base64_encode($blob),
        ];
    }

    /**
     * Mark a value as already erased (no key available at write time).
     *
     * @return array<string, mixed>
     */
    public function redactedEnvelope(): array
    {
        return [self::ENVELOPE_MARKER => self::ENVELOPE_VERSION, 'redacted' => true];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function decrypt(array $envelope, ?string $key): mixed
    {
        if (true === ($envelope['redacted'] ?? false) || !isset($envelope['ct']) || null === $key) {
            return self::REDACTED;
        }

        $blob = base64_decode((string) $envelope['ct'], true);
        if (false === $blob) {
            return self::REDACTED;
        }

        try {
            $plaintext = $this->cipher->decrypt($key, $blob, self::AAD);
        } catch (DecryptionFailedException) {
            return self::REDACTED;
        }

        return json_decode($plaintext, true, 512, \JSON_THROW_ON_ERROR);
    }

    public static function isEnvelope(mixed $value): bool
    {
        return \is_array($value) && \array_key_exists(self::ENVELOPE_MARKER, $value);
    }
}
