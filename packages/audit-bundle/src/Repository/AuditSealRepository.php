<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Opus\AuditBundle\Model\AuditSeal;

/**
 * Read access to stream seals.
 *
 * @extends EntityRepository<AuditSeal>
 */
class AuditSealRepository extends EntityRepository
{
    /**
     * The most recent seal for a stream, or null if it has never been sealed.
     */
    public function findLatestForStream(string $streamId): ?AuditSeal
    {
        return $this->createQueryBuilder('s')
            ->where('s.streamId = :stream')
            ->setParameter('stream', $streamId)
            ->orderBy('s.lastSequence', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * All seals for a stream, oldest first.
     *
     * @return list<AuditSeal>
     */
    public function findByStream(string $streamId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.streamId = :stream')
            ->setParameter('stream', $streamId)
            ->orderBy('s.lastSequence', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
