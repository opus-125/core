<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Opus\AuditBundle\Integrity\Exception\MissingAuditTransactionException;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Model\PendingAuditEntry;
use Opus\AuditBundle\Model\Schema;

/**
 * Default {@see ChainBackendInterface}: a linear SHA-256 hash chain in
 * PostgreSQL (the Spine).
 *
 * Append, under a per-stream advisory lock held for the surrounding
 * transaction, reads the stream head, assigns `sequence_no = head + 1` and
 * `previous_hash = head.hash` (or genesis for an empty stream), computes the
 * entry hash and inserts the row via DBAL — so the audit row lives in the same
 * transaction as the business change.
 *
 * `changes` and `context` are stored as **canonical** JSON (the same encoding
 * that is hashed), not via the generic DBAL json conversion: that is what lets
 * verification re-read a row and recompute a byte-identical hash, with float vs
 * int and key order preserved.
 */
final class PostgresHashChain implements ChainBackendInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StreamLockInterface $lock,
        private readonly HashCalculator $hashCalculator,
        private readonly CanonicalJsonEncoder $encoder,
    ) {
    }

    public function append(PendingAuditEntry $entry): AppendedLink
    {
        if (!$this->connection->isTransactionActive()) {
            throw MissingAuditTransactionException::create();
        }

        $this->lock->lock($entry->streamId);

        [$previousSequence, $previousHash] = $this->readHead($entry->streamId);
        $sequenceNo = $previousSequence + 1;

        $rowWithoutHash = $entry->rowWithoutHash($sequenceNo, $previousHash);
        $hash = $this->hashCalculator->hash(AuditEntry::hashablePayload($rowWithoutHash), $previousHash);

        $row = $entry->toRow($sequenceNo, $previousHash, $hash);
        $row['changes'] = $this->encoder->encode($entry->changes);
        $row['context'] = $this->encoder->encode($entry->context);

        $this->connection->insert(Schema::ENTRY_TABLE, $row, [
            'sequence_no' => ParameterType::INTEGER,
            'version_no' => ParameterType::INTEGER,
            'legal_hold' => ParameterType::BOOLEAN,
        ]);

        return new AppendedLink($sequenceNo, $previousHash, $hash);
    }

    public function verify(
        string $streamId,
        int $fromSequence,
        ?int $toSequence,
        string $expectedPreviousHash,
    ): ChainVerificationResult {
        $sql = \sprintf(
            'SELECT * FROM %s WHERE stream_id = :stream AND sequence_no >= :from%s ORDER BY sequence_no ASC',
            Schema::ENTRY_TABLE,
            null === $toSequence ? '' : ' AND sequence_no <= :to',
        );
        $params = ['stream' => $streamId, 'from' => $fromSequence];
        if (null !== $toSequence) {
            $params['to'] = $toSequence;
        }

        $expectedPrev = $expectedPreviousHash;
        $expectedSeq = $fromSequence;
        $checked = 0;

        foreach ($this->connection->iterateAssociative($sql, $params) as $row) {
            $sequenceNo = (int) $row['sequence_no'];

            if ($sequenceNo !== $expectedSeq) {
                return ChainVerificationResult::broken(
                    $checked,
                    $sequenceNo,
                    \sprintf('Stream "%s": gap before sequence %d (expected %d).', $streamId, $sequenceNo, $expectedSeq),
                );
            }

            if (!hash_equals($expectedPrev, (string) $row['previous_hash'])) {
                return ChainVerificationResult::broken(
                    $checked,
                    $sequenceNo,
                    \sprintf('Stream "%s": broken link at sequence %d (previous_hash does not match predecessor).', $streamId, $sequenceNo),
                );
            }

            $normalised = $row;
            $normalised['changes'] = $this->decodeJson((string) $row['changes']);
            $normalised['context'] = $this->decodeJson((string) $row['context']);

            $recomputed = $this->hashCalculator->hash(
                AuditEntry::hashablePayload($normalised),
                (string) $row['previous_hash'],
            );

            if (!hash_equals($recomputed, (string) $row['hash'])) {
                return ChainVerificationResult::broken(
                    $checked,
                    $sequenceNo,
                    \sprintf('Stream "%s": tampered entry at sequence %d (stored hash does not match its contents).', $streamId, $sequenceNo),
                );
            }

            $expectedPrev = (string) $row['hash'];
            ++$expectedSeq;
            ++$checked;
        }

        return ChainVerificationResult::intact($checked, $streamId);
    }

    /**
     * @return array{int, string} `[lastSequence, headHash]`; `[0, genesis]` for an empty stream
     */
    private function readHead(string $streamId): array
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT sequence_no, hash FROM %s WHERE stream_id = :stream ORDER BY sequence_no DESC LIMIT 1', Schema::ENTRY_TABLE),
            ['stream' => $streamId],
        );

        if (false === $row) {
            return [0, $this->hashCalculator->genesisHash()];
        }

        return [(int) $row['sequence_no'], (string) $row['hash']];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
