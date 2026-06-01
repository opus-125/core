<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

/**
 * The chain fields a backend assigned to a freshly appended entry.
 */
final readonly class AppendedLink
{
    public function __construct(
        public int $sequenceNo,
        public string $previousHash,
        public string $hash,
    ) {
    }
}
