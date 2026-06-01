<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Retention;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Support\CanonicalTimestamp;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Removes audit entries that have outlived their statutory retention.
 *
 * Purge drops a contiguous block at the *start* of a stream's chain: it walks
 * from the earliest entry and removes the leading run whose entries are all past
 * retention, stopping at the first entry that must be kept — within retention,
 * under a legal hold, or belonging to a class with no policy ("keep forever").
 *
 * Before deleting, it writes a **genesis seal** capturing the head hash of the
 * last purged entry, so the remaining chain stays verifiable from its new
 * beginning. Honest limitation: across a purge boundary "nothing was omitted"
 * is no longer provable — only the surviving chain is. The whole operation runs
 * in one transaction.
 *
 * Retention bounds *purge* of whole entries; it is independent of
 * crypto-shredding, which erases personal content on request while keeping the
 * record for the retention period.
 */
final class Purger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RetentionPolicyInterface $policy,
        private readonly ClockInterface $clock,
    ) {
    }

    public function purge(string $streamId, ?\DateTimeImmutable $now = null): PurgeReport
    {
        $now ??= \DateTimeImmutable::createFromInterface($this->clock->now());

        return $this->connection->transactional(function () use ($streamId, $now): PurgeReport {
            $boundary = $this->findPurgeBoundary($streamId, $now);

            if (null === $boundary) {
                return new PurgeReport($streamId, 0, null);
            }

            [$lastSequence, $headHash] = $boundary;

            // Anchor the remaining chain with a genesis seal before deleting.
            $this->connection->insert(Schema::SEAL_TABLE, [
                'id' => Uuid::v7()->toRfc4122(),
                'stream_id' => $streamId,
                'last_sequence' => $lastSequence,
                'head_hash' => $headHash,
                'sealed_at' => CanonicalTimestamp::format($now),
                'genesis' => true,
            ], [
                'last_sequence' => ParameterType::INTEGER,
                'genesis' => ParameterType::BOOLEAN,
            ]);

            $purged = (int) $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE stream_id = :s AND sequence_no <= :seq', Schema::ENTRY_TABLE),
                ['s' => $streamId, 'seq' => $lastSequence],
                ['seq' => ParameterType::INTEGER],
            );

            return new PurgeReport($streamId, $purged, $lastSequence);
        });
    }

    /**
     * @return list<PurgeReport>
     */
    public function purgeAll(?\DateTimeImmutable $now = null): array
    {
        /** @var list<string> $streams */
        $streams = $this->connection->fetchFirstColumn(
            \sprintf('SELECT DISTINCT stream_id FROM %s ORDER BY stream_id', Schema::ENTRY_TABLE),
        );

        return array_map(fn (string $stream): PurgeReport => $this->purge($stream, $now), $streams);
    }

    /**
     * The sequence (and head hash) of the last entry eligible for purging, or
     * null if nothing may be purged.
     *
     * @return array{int, string}|null
     */
    private function findPurgeBoundary(string $streamId, \DateTimeImmutable $now): ?array
    {
        $cutoffCache = [];
        $boundary = null;

        $rows = $this->connection->iterateAssociative(
            \sprintf('SELECT sequence_no, occurred_at, entity_class, legal_hold, hash FROM %s WHERE stream_id = :s ORDER BY sequence_no ASC', Schema::ENTRY_TABLE),
            ['s' => $streamId],
        );

        foreach ($rows as $row) {
            if ($this->isTruthy($row['legal_hold'])) {
                break;
            }

            $class = (string) $row['entity_class'];
            $cutoff = $cutoffCache[$class] ??= $this->cutoffFor($class, $now);

            if (null === $cutoff || (string) $row['occurred_at'] >= $cutoff) {
                break;
            }

            $boundary = [(int) $row['sequence_no'], (string) $row['hash']];
        }

        return $boundary;
    }

    private function cutoffFor(string $entityClass, \DateTimeImmutable $now): ?string
    {
        /** @var class-string $entityClass */
        $interval = $this->policy->retentionFor($entityClass);

        if (null === $interval) {
            return null;
        }

        // Entries strictly older than this canonical instant may be purged.
        return CanonicalTimestamp::format($now->sub($interval));
    }

    private function isTruthy(mixed $value): bool
    {
        return \in_array($value, [true, 't', '1', 1], true);
    }
}
