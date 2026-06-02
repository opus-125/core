<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Opus125\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus125\AuditBundle\Integrity\ChainVerificationResult;
use Opus125\AuditBundle\Integrity\HashCalculator;
use Opus125\AuditBundle\Model\AuditEntryInterface;

/**
 * Read access to the audit trail, plus the on-demand integrity check.
 *
 * Query helpers here are meant to be reused from a project's own console
 * commands or controllers; the bundle deliberately ships no read/verify/export
 * commands of its own.
 *
 * @extends EntityRepository<AuditEntryInterface>
 */
class AuditEntryRepository extends EntityRepository
{
    /**
     * Full history of a single target entity, oldest first.
     *
     * @return list<AuditEntryInterface>
     */
    public function findForTarget(string $entityClass, string $entityId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.entityClass = :class')
            ->andWhere('e.entityId = :id')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->orderBy('e.sequenceNo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AuditEntryInterface>
     */
    public function findByStream(string $stream): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.stream = :stream')
            ->setParameter('stream', $stream)
            ->orderBy('e.sequenceNo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AuditEntryInterface>
     */
    public function findByActor(string $actorId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.actorId = :actor')
            ->setParameter('actor', $actorId)
            ->orderBy('e.sequenceNo', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The current head of a stream as `[sequenceNo, hash]`, or null if empty.
     *
     * @return array{0: int, 1: string}|null
     */
    public function head(string $stream): ?array
    {
        /** @var AuditEntryInterface|null $entry */
        $entry = $this->createQueryBuilder('e')
            ->where('e.stream = :stream')
            ->setParameter('stream', $stream)
            ->orderBy('e.sequenceNo', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null === $entry ? null : [$entry->getSequenceNo(), $entry->getHash()];
    }

    /**
     * @return list<string>
     */
    public function streams(): array
    {
        /** @var list<array{stream: string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.stream AS stream')
            ->orderBy('e.stream', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['stream'], $rows);
    }

    /**
     * Recompute every entry's hash and check it links to its predecessor.
     * Detects modified content, rewritten hashes, broken links and gaps.
     */
    public function verify(string $stream): ChainVerificationResult
    {
        $hasher = new HashCalculator(new CanonicalJsonEncoder());

        $entries = $this->findByStream($stream);
        if ([] === $entries) {
            return ChainVerificationResult::intact(0, $stream);
        }

        // Anchor on the first surviving entry's stored previous_hash (it is the
        // genesis hash for an un-purged stream).
        $expectedPrevious = $entries[0]->getPreviousHash();
        $expectedSequence = $entries[0]->getSequenceNo();
        $checked = 0;

        foreach ($entries as $entry) {
            if ($entry->getSequenceNo() !== $expectedSequence) {
                return ChainVerificationResult::broken($checked, $entry->getSequenceNo(), \sprintf('Stream "%s": gap before sequence %d.', $stream, $entry->getSequenceNo()));
            }

            if (!hash_equals($expectedPrevious, $entry->getPreviousHash())) {
                return ChainVerificationResult::broken($checked, $entry->getSequenceNo(), \sprintf('Stream "%s": broken link at sequence %d.', $stream, $entry->getSequenceNo()));
            }

            if (!$hasher->verify($entry->hashableData(), $entry->getPreviousHash(), $entry->getHash())) {
                return ChainVerificationResult::broken($checked, $entry->getSequenceNo(), \sprintf('Stream "%s": tampered entry at sequence %d.', $stream, $entry->getSequenceNo()));
            }

            $expectedPrevious = $entry->getHash();
            ++$expectedSequence;
            ++$checked;
        }

        return ChainVerificationResult::intact($checked, $stream);
    }
}
