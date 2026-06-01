<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

/**
 * Serialises concurrent appends to a single stream.
 *
 * Gapless `sequence_no` is computed as `MAX(seq)+1`; under concurrency that read
 * and the following insert must be exclusive per stream. The lock is held for
 * the remainder of the surrounding transaction, so a competing writer blocks
 * until the first transaction commits and then reads the new head.
 *
 * This is the "atomicity trilemma" trade-off made explicit: gaplessness plus
 * simple atomicity, at the cost of serialised writes per stream.
 */
interface StreamLockInterface
{
    /**
     * Acquire the exclusive lock for $streamId for the current transaction.
     */
    public function lock(string $streamId): void;
}
