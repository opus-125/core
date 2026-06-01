<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

use Opus\AuditBundle\Crypto\Exception\DecryptionFailedException;

/**
 * Encrypts and decrypts individual sensitive values as self-describing
 * envelopes, and renders unrecoverable (shredded) values as a placeholder.
 *
 * An envelope is a small JSON-able structure embedded in the audit `changes`
 * map in place of a `#[Sensitive]` value. It records the subject set and the
 * ciphertext, so decryption later needs nothing but the keystore. When the
 * required key has been shredded, decryption yields {@see REDACTED} instead of
 * the cleartext — the GDPR-erased state — while the ciphertext bytes (and thus
 * the hash-chain) stay intact.
 */
final class CryptoShredder
{
    /**
     * What a value decrypts to once its subject has been crypto-shredded.
     */
    public const string REDACTED = '[redacted: erased]';

    /**
     * Marker key identifying an encryption envelope inside the changes map.
     */
    private const string ENVELOPE_MARKER = '__opus_enc';
    private const int ENVELOPE_VERSION = 1;
    private const string ALGORITHM = 'xchacha20poly1305-ietf';

    public function __construct(
        private readonly KeyStoreInterface $keyStore,
        private readonly Cipher $cipher,
    ) {
    }

    /**
     * Encrypt a value for the given data subjects, returning an envelope.
     *
     * @param list<string> $subjectIds
     *
     * @return array<string, mixed> the envelope (JSON-able)
     */
    public function encryptValue(mixed $value, array $subjectIds): array
    {
        $subjects = $this->normaliseSubjects($subjectIds);
        $key = $this->keyStore->deriveEncryptionKey($subjects);

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
     * Decrypt an envelope back to its value, or {@see REDACTED} if the subject's
     * key has been shredded (or the ciphertext can no longer be authenticated).
     *
     * @param array<string, mixed> $envelope
     */
    public function decryptValue(array $envelope): mixed
    {
        if (!self::isEnvelope($envelope)) {
            throw new \InvalidArgumentException('Value is not an encryption envelope.');
        }

        /** @var list<string> $subjects */
        $subjects = $envelope['subjects'];
        $key = $this->keyStore->deriveDecryptionKey($subjects);

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
            // Key was re-provisioned after a shred, or the ciphertext is gone:
            // the value is, for all intents, erased.
            return self::REDACTED;
        }

        return json_decode($plaintext, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Whether a value in the changes map is an encryption envelope.
     */
    public static function isEnvelope(mixed $value): bool
    {
        return \is_array($value)
            && \array_key_exists(self::ENVELOPE_MARKER, $value)
            && isset($value['subjects'], $value['ct'])
            && \is_array($value['subjects']);
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
