<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

use Doctrine\DBAL\Connection;

/**
 * Per-stream lock backed by PostgreSQL transaction-level advisory locks.
 *
 * `pg_advisory_xact_lock` takes a 64-bit key (derived here from the stream id
 * via `hashtext`) and is released automatically when the transaction ends, so
 * there is no unlock to forget. Hash collisions between distinct stream ids only
 * cause occasional, harmless extra serialisation.
 */
final class PostgresAdvisoryLock implements StreamLockInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function lock(string $streamId): void
    {
        // hashtext() returns int4; widen to bigint to select the single-argument
        // advisory-lock overload deterministically.
        $this->connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:stream)::bigint)',
            ['stream' => $streamId],
        );
    }
}
