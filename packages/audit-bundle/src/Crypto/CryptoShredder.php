<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

use Opus\AuditBundle\Crypto\Exception\DecryptionFailedException;

/**
 * Encrypts and decrypts individual sensitive values as self-describing
 * envelopes, and renders unrecoverable (shredded) values as a placeholder.
 *
 * An envelope replaces a `#[Sensitive]` value inside the audit `changes` map (or
 * the actor label). It records the subject set and ciphertext, so decryption
 * later needs only the {@see SubjectKeyProviderInterface}. When a required
 * key has been shredded, decryption yields {@see REDACTED} — the GDPR-erased
 * state — while the ciphertext bytes (and thus the hash-chain) stay intact.
 *
 * Multi-subject values are encrypted under a key combined from *all* their
 * subjects' keys, so shredding any one of them erases the value.
 */
final class CryptoShredder
{
    public const string REDACTED = '[redacted: erased]';

    private const string ENVELOPE_MARKER = '__opus_enc';
    private const int ENVELOPE_VERSION = 1;
    private const string ALGORITHM = 'xchacha20poly1305-ietf';

    public function __construct(
        private readonly SubjectKeyProviderInterface $keyProvider,
        private readonly Cipher $cipher,
    ) {
    }

    /**
     * Encrypt a value for the given data subjects, returning an envelope. If a
     * subject is already shredded the value is stored pre-redacted.
     *
     * @param list<string> $subjectIds
     *
     * @return array<string, mixed>
     */
    public function encryptValue(mixed $value, array $subjectIds): array
    {
        $subjects = $this->normaliseSubjects($subjectIds);
        $key = $this->combinedKey($subjects);

        if (null === $key) {
            return [self::ENVELOPE_MARKER => self::ENVELOPE_VERSION, 'subjects' => $subjects, 'redacted' => true];
        }

        $plaintext = json_encode($value, \JSON_THROW_ON_ERROR);
        $blob = $this->cipher->encrypt($key, $plaintext, $this->aad($subjects));

        return [
            self::ENVELOPE_MARKER => self::ENVELOPE_VERSION,
            'alg' => self::ALGORITHM,
            'subjects' => $subjects,
            'ct' => base64_encode($blob),
        ];
    }

    /**
     * Decrypt an envelope, or {@see REDACTED} if its subject has been shredded.
     *
     * @param array<string, mixed> $envelope
     */
    public function decryptValue(array $envelope): mixed
    {
        if (!self::isEnvelope($envelope)) {
            throw new \InvalidArgumentException('Value is not an encryption envelope.');
        }

        if (($envelope['redacted'] ?? false) || !isset($envelope['ct'])) {
            return self::REDACTED;
        }

        /** @var list<string> $subjects */
        $subjects = $envelope['subjects'];
        $key = $this->combinedKey($subjects);
        if (null === $key) {
            return self::REDACTED;
        }

        $blob = base64_decode((string) $envelope['ct'], true);
        if (false === $blob) {
            return self::REDACTED;
        }

        try {
            $plaintext = $this->cipher->decrypt($key, $blob, $this->aad($subjects));
        } catch (DecryptionFailedException) {
            return self::REDACTED;
        }

        return json_decode($plaintext, true, 512, \JSON_THROW_ON_ERROR);
    }

    public static function isEnvelope(mixed $value): bool
    {
        return \is_array($value)
            && \array_key_exists(self::ENVELOPE_MARKER, $value)
            && isset($value['subjects'])
            && \is_array($value['subjects']);
    }

    /**
     * Combine the subjects' keys into one value key, or null if any is shredded.
     *
     * @param list<string> $subjects
     */
    private function combinedKey(array $subjects): ?string
    {
        $keys = [];
        foreach ($subjects as $subjectId) {
            $key = $this->keyProvider->keyForSubject($subjectId);
            if (null === $key) {
                return null;
            }
            $keys[] = $key;
        }

        return hash_hkdf('sha256', implode('', $keys), Cipher::KEY_BYTES, 'opus-audit-value');
    }

    /**
     * @param list<string> $subjects
     */
    private function aad(array $subjects): string
    {
        return 'opus-audit:'.implode(',', $subjects);
    }

    /**
     * @param list<string> $subjectIds
     *
     * @return list<string>
     */
    private function normaliseSubjects(array $subjectIds): array
    {
        $subjects = array_values(array_unique(array_filter($subjectIds, static fn (string $s): bool => '' !== $s)));

        if ([] === $subjects) {
            throw new \InvalidArgumentException('Cannot encrypt a sensitive value without at least one data subject.');
        }

        sort($subjects, \SORT_STRING);

        return $subjects;
    }
}
