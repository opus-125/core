<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

/**
 * Decides the encryption key for an audited entity's `#[Sensitive]` values —
 * the single seam projects override to control crypto-shredding.
 *
 * Return a 32-byte key while the subject's data may be read, or **null** once it
 * has been erased/anonymised. Returning null is the whole shredding mechanism:
 * the ciphertext stays in the audit row (so the hash-chain stays valid) but the
 * value can no longer be decrypted and reads render it redacted.
 *
 * The default derives the key from the application secret. A typical project
 * override stores a random key on the entity (e.g. the `User`) and returns it
 * until the entity is anonymised, then null — so erasure is a normal domain
 * operation with no bundle-owned state.
 */
interface SubjectKeyProviderInterface
{
    /**
     * @param class-string $entityClass the audited entity's class
     * @param string       $entityId    the audited entity's identifier
     */
    public function keyFor(string $entityClass, string $entityId): ?string;
}
