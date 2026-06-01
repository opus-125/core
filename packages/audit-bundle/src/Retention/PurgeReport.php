<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Retention;

/**
 * Outcome of purging one stream.
 */
final readonly class PurgeReport
{
    public function __construct(
        public string $streamId,
        public int $purgedCount,
        public ?int $newGenesisSequence,
    ) {
    }

    public function purgedAnything(): bool
    {
        return $this->purgedCount > 0;
    }
}
