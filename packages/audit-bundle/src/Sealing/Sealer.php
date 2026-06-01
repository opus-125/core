<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Sealing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Support\CanonicalTimestamp;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes stream seals — periodic, externally-mirrorable checkpoints of a
 * stream's head `(last_sequence, head_hash)`.
 *
 * A seal that has been copied to WORM/external storage anchors the chain:
 * verification can re-walk just the segment since the last trusted seal, and a
 * rewritten head is caught by comparing against it. After a retention purge a
 * {@see seal()} with `genesis: true` marks the new legitimate start of the
 * (shortened) chain.
 */
final class Sealer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Seal the current head of $streamId. Returns the new seal id, or null if
     * the stream has no entries to seal.
     */
    public function seal(string $streamId, bool $genesis = false): ?string
    {
        $head = $this->connection->fetchAssociative(
            \sprintf('SELECT sequence_no, hash FROM %s WHERE stream_id = :s ORDER BY sequence_no DESC LIMIT 1', Schema::ENTRY_TABLE),
            ['s' => $streamId],
        );

        if (false === $head) {
            return null;
        }

        $id = Uuid::v7()->toRfc4122();

        $this->connection->insert(Schema::SEAL_TABLE, [
            'id' => $id,
            'stream_id' => $streamId,
            'last_sequence' => (int) $head['sequence_no'],
            'head_hash' => (string) $head['hash'],
            'sealed_at' => CanonicalTimestamp::format($this->clock->now()),
            'genesis' => $genesis,
        ], [
            'last_sequence' => ParameterType::INTEGER,
            'genesis' => ParameterType::BOOLEAN,
        ]);

        return $id;
    }

    /**
     * Seal the head of every stream that has entries. Returns the number sealed.
     */
    public function sealAll(): int
    {
        $sealed = 0;
        foreach ($this->streams() as $streamId) {
            if (null !== $this->seal($streamId)) {
                ++$sealed;
            }
        }

        return $sealed;
    }

    /**
     * @return list<string>
     */
    public function streams(): array
    {
        /** @var list<string> $streams */
        $streams = $this->connection->fetchFirstColumn(
            \sprintf('SELECT DISTINCT stream_id FROM %s ORDER BY stream_id', Schema::ENTRY_TABLE),
        );

        return $streams;
    }
}
