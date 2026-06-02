<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Crypto;

use Opus125\DataContracts\Subject\SubjectReference;

/**
 * Stores one data-encryption key (DEK) per subject — the seam crypto-shredding
 * turns on.
 *
 * A subject's `crypto_shred` data is encrypted under their DEK. Erasure
 * ({@see shred()}) destroys that DEK **irreversibly**, after which the ciphertext
 * — wherever it lives, including an append-only audit trail — can never be
 * decrypted again, while the bytes (and any hash-chain over them) remain intact.
 *
 * Destruction is a tombstone, not a delete: a shredded subject's key is gone for
 * good and must never be re-created, so {@see ensureKey()} returns null for it
 * rather than silently minting a fresh one.
 *
 * The default {@see DoctrineKeyStore} keeps wrapped DEKs in the database; the
 * interface is the extension point for Vault / KMS / HSM backends.
 */
interface KeyStoreInterface
{
    /**
     * The subject's live DEK (raw 32 bytes), or null if none exists yet or it was
     * shredded.
     */
    public function keyFor(SubjectReference $subject): ?string;

    /**
     * The subject's DEK, creating one on first use. Returns null — never a new
     * key — when the subject has been shredded, so erasure is irreversible.
     */
    public function ensureKey(SubjectReference $subject): ?string;

    /**
     * Irreversibly destroy the subject's DEK (crypto-shredding). Idempotent.
     */
    public function shred(SubjectReference $subject): void;

    /**
     * Whether the subject's key has been shredded.
     */
    public function isShredded(SubjectReference $subject): bool;
}
