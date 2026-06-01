<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

/**
 * Supplies the symmetric key that protects a data subject's sensitive audit
 * values — the single seam projects override to control crypto-shredding.
 *
 * The default derives a key per subject from the application secret. Override
 * the service (alias this interface to your own) to, for example, store a random
 * key per subject and physically destroy it on erasure for a stronger
 * guarantee.
 *
 * Crypto-shredding works by making {@see keyForSubject()} return null: the
 * ciphertext stays in place (so the hash-chain remains valid) but the value can
 * no longer be decrypted.
 */
interface SubjectKeyProviderInterface
{
    /**
     * The 32-byte key for a subject, or null if the subject has been shredded.
     */
    public function keyForSubject(string $subjectId): ?string;

    /**
     * Crypto-shred a subject: make its key permanently unavailable. Idempotent.
     */
    public function shred(string $subjectId): void;

    public function isShredded(string $subjectId): bool;
}
