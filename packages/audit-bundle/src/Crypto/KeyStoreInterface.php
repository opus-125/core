<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

use Opus\AuditBundle\Crypto\Exception\LegalHoldViolationException;

/**
 * Manages per-subject Data Encryption Keys (DEKs) and the derivation of the
 * combined value key that protects sensitive audit content.
 *
 * This is a BC-critical seam (§5). The default implementation stores wrapped
 * DEKs in the database; alternatives (Vault, KMS, HSM) implement the same
 * contract.
 *
 * **Multi-subject semantics.** A value concerning several subjects is encrypted
 * under a key *derived from all their DEKs together*, so destroying *any one*
 * subject's DEK makes the value unreadable. {@see deriveEncryptionKey()}
 * provisions DEKs as needed; {@see deriveDecryptionKey()} returns the same key
 * only while *every* contributing DEK still exists.
 */
interface KeyStoreInterface
{
    /**
     * Derive the key used to encrypt a value for the given subjects, creating
     * any missing per-subject DEKs. The result is independent of the order of
     * $subjectIds.
     *
     * @param list<string> $subjectIds non-empty list of subject identifiers
     *
     * @return string a 32-byte key
     */
    public function deriveEncryptionKey(array $subjectIds): string;

    /**
     * Derive the decryption key for the given subjects, or null if *any* of
     * their DEKs is missing or has been shredded (in which case the value is
     * intentionally unrecoverable).
     *
     * @param list<string> $subjectIds
     *
     * @return string|null a 32-byte key, or null if undecryptable
     */
    public function deriveDecryptionKey(array $subjectIds): ?string;

    /**
     * Crypto-shred a subject: irreversibly destroy its DEK. Idempotent.
     *
     * @throws LegalHoldViolationException if the subject is under a legal hold
     */
    public function shred(string $subjectId): void;

    public function isShredded(string $subjectId): bool;

    public function hasSubject(string $subjectId): bool;

    /**
     * Place a legal hold that blocks {@see shred()} for the subject
     * (GDPR Art. 17(3)(b)).
     */
    public function placeLegalHold(string $subjectId): void;

    public function liftLegalHold(string $subjectId): void;

    public function isUnderLegalHold(string $subjectId): bool;
}
