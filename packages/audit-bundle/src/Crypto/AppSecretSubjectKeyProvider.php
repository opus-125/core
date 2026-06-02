<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

/**
 * Default {@see SubjectKeyProviderInterface}: derives a per-entity key from the
 * application secret with HKDF. Zero configuration.
 *
 * It never returns null, so out of the box `#[Sensitive]` values are encrypted
 * at rest but not erasable (the key is always re-derivable from the secret). To
 * support real crypto-shredding, replace this service with one that stores a
 * destroyable key per subject and returns null once erased — see
 * {@see SubjectKeyProviderInterface}.
 */
final class AppSecretSubjectKeyProvider implements SubjectKeyProviderInterface
{
    private const int KEY_BYTES = 32;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('The application secret must not be empty; it seeds the encryption keys.');
        }
    }

    public function keyFor(string $entityClass, string $entityId): string
    {
        return hash_hkdf('sha256', $this->secret, self::KEY_BYTES, 'opus-audit:'.$entityClass.'#'.$entityId);
    }
}
