<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

/**
 * Default no-op {@see StreamLockInterface}: portable across every Doctrine
 * platform.
 *
 * Without a real lock, the unique `(stream, sequence_no)` constraint still keeps
 * the chain gapless — two concurrent writers to the same stream that pick the
 * same next sequence collide, and one transaction rolls back (and can retry)
 * rather than producing a gap. Apps with heavy same-stream concurrency on
 * PostgreSQL can opt into {@see PostgresAdvisoryLock} (inside an explicit
 * transaction) to serialise instead.
 */
final class NullStreamLock implements StreamLockInterface
{
    public function lock(string $streamId): void
    {
        // Intentionally empty.
    }
}
