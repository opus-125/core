<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

/**
 * Outcome of verifying a chain segment.
 */
final readonly class ChainVerificationResult
{
    /**
     * @param int      $checkedCount     number of entries walked
     * @param int|null $brokenAtSequence sequence at which verification failed, or null if intact
     * @param string   $message          human-readable summary
     */
    private function __construct(
        public bool $valid,
        public int $checkedCount,
        public ?int $brokenAtSequence,
        public string $message,
    ) {
    }

    public static function intact(int $checkedCount, string $streamId): self
    {
        return new self(true, $checkedCount, null, \sprintf('Stream "%s": %d entr%s verified, chain intact.', $streamId, $checkedCount, 1 === $checkedCount ? 'y' : 'ies'));
    }

    public static function broken(int $checkedCount, int $brokenAtSequence, string $message): self
    {
        return new self(false, $checkedCount, $brokenAtSequence, $message);
    }
}
