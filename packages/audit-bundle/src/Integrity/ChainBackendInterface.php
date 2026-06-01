<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

use Opus\AuditBundle\Integrity\Exception\MissingAuditTransactionException;
use Opus\AuditBundle\Model\PendingAuditEntry;

/**
 * Appends entries to a tamper-evident chain and verifies segments of it.
 *
 * A BC-critical seam (§5). The default {@see PostgresHashChain} is a linear
 * SHA-256 hash chain; escalation tier E2 swaps in a Merkle-backed
 * implementation (immudb/Trillian) behind the same contract.
 */
interface ChainBackendInterface
{
    /**
     * Assign the chain fields for $entry (gapless `sequence_no`, `previous_hash`
     * of the stream head, and the computed `hash`) and persist it.
     *
     * Must run inside the caller's transaction so the audit row commits or rolls
     * back together with the business change; implementations enforce this and
     * throw {@see MissingAuditTransactionException} when no transaction is
     * active.
     */
    public function append(PendingAuditEntry $entry): AppendedLink;

    /**
     * Verify the segment `[fromSequence, toSequence]` of a stream.
     *
     * $expectedPreviousHash anchors the segment: it is the hash the entry at
     * $fromSequence must chain onto — either a trusted seal's head hash or the
     * genesis hash for the start of the stream. A null $toSequence verifies
     * through to the current head.
     */
    public function verify(
        string $streamId,
        int $fromSequence,
        ?int $toSequence,
        string $expectedPreviousHash,
    ): ChainVerificationResult;
}
