<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

use Doctrine\DBAL\Connection;
use Opus\AuditBundle\Model\Schema;

/**
 * Orchestrates chain verification around seals.
 *
 * Two modes:
 *  - **incremental** (the monitoring default): verify only the segment appended
 *    since the latest seal, anchored on that seal's head hash — O(segment), not
 *    O(stream), because everything up to a mirrored seal was already attested;
 *  - **full**: re-walk from the chain's true beginning — the genesis hash, or,
 *    for a chain shortened by a retention purge, the genesis seal that records
 *    the head hash at the purge boundary.
 *
 * If a purged chain has no anchoring seal, verification still checks every link
 * from the earliest surviving entry but cannot attest the very first one; that
 * is reported honestly rather than hidden.
 */
final class ChainVerifier
{
    public function __construct(
        private readonly ChainBackendInterface $chain,
        private readonly Connection $connection,
    ) {
    }

    public function verifyFull(string $streamId): ChainVerificationResult
    {
        $minSequence = $this->minSequence($streamId);
        if (null === $minSequence) {
            return ChainVerificationResult::intact(0, $streamId);
        }

        if (1 === $minSequence) {
            return $this->chain->verify($streamId, 1, null, HashCalculator::GENESIS_HASH);
        }

        $anchor = $this->sealHashAt($streamId, $minSequence - 1)
            ?? $this->storedPreviousHash($streamId, $minSequence);

        return $this->chain->verify($streamId, $minSequence, null, $anchor);
    }

    public function verifyIncremental(string $streamId): ChainVerificationResult
    {
        $seal = $this->connection->fetchAssociative(
            \sprintf('SELECT last_sequence, head_hash FROM %s WHERE stream_id = :s ORDER BY last_sequence DESC LIMIT 1', Schema::SEAL_TABLE),
            ['s' => $streamId],
        );

        if (false === $seal) {
            return $this->verifyFull($streamId);
        }

        return $this->chain->verify(
            $streamId,
            (int) $seal['last_sequence'] + 1,
            null,
            (string) $seal['head_hash'],
        );
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

    private function minSequence(string $streamId): ?int
    {
        $min = $this->connection->fetchOne(
            \sprintf('SELECT MIN(sequence_no) FROM %s WHERE stream_id = :s', Schema::ENTRY_TABLE),
            ['s' => $streamId],
        );

        return null === $min || false === $min ? null : (int) $min;
    }

    private function sealHashAt(string $streamId, int $lastSequence): ?string
    {
        $hash = $this->connection->fetchOne(
            \sprintf('SELECT head_hash FROM %s WHERE stream_id = :s AND last_sequence = :seq ORDER BY genesis DESC, sealed_at DESC LIMIT 1', Schema::SEAL_TABLE),
            ['s' => $streamId, 'seq' => $lastSequence],
        );

        return false === $hash ? null : (string) $hash;
    }

    private function storedPreviousHash(string $streamId, int $sequence): string
    {
        return (string) $this->connection->fetchOne(
            \sprintf('SELECT previous_hash FROM %s WHERE stream_id = :s AND sequence_no = :seq', Schema::ENTRY_TABLE),
            ['s' => $streamId, 'seq' => $sequence],
        );
    }
}
