<?php

declare(strict_types=1);

namespace Opus125\DataContracts\Erasure;

/**
 * How a {@see \Opus125\DataContracts\Attribute\PersonalData} field is erased when
 * a data subject exercises the right to be forgotten (GDPR Art. 17).
 *
 * "Erasure" is deliberately *three* mechanisms, not one — the right one depends
 * on whether the field is live operational data or immutable history:
 *
 *  - {@see Nullify}      — empty the field. For live data with no statistical value.
 *  - {@see Pseudonymize} — replace the value with a stable pseudonym, so
 *                          referential integrity and aggregate statistics survive
 *                          while the person is no longer identifiable.
 *  - {@see CryptoShred}  — destroy the subject's data-encryption key so the
 *                          ciphertext in an **append-only** store (audit trail,
 *                          revision history) becomes permanently unreadable while
 *                          the bytes — and any hash-chain over them — stay intact.
 *
 * The backing strings are part of the attribute signature and therefore
 * BC-critical: they are what a developer writes as `erasure: 'pseudonymize'`.
 */
enum ErasureStrategy: string
{
    case Nullify = 'nullify';
    case Pseudonymize = 'pseudonymize';
    case CryptoShred = 'crypto_shred';
}
