<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Opus\AuditBundle\Model\AuditEntry;

/**
 * Read access to the audit trail.
 *
 * Bulk, forensic walks (verification, export) stream rows through DBAL in the
 * chain backend; this repository serves the ordinary "show me the history of
 * X" read paths and the demo/UI.
 *
 * @extends EntityRepository<AuditEntry>
 */
class AuditEntryRepository extends EntityRepository
{
    /**
     * Full history of a single target entity, oldest first.
     *
     * @param class-string $entityClass
     *
     * @return list<AuditEntry>
     */
    public function findForTarget(string $entityClass, string $entityId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.entityClass = :class')
            ->andWhere('e.entityId = :id')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.sequenceNo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Entries of a stream ordered by sequence, oldest first.
     *
     * @return list<AuditEntry>
     */
    public function findByStream(string $streamId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.streamId = :stream')
            ->setParameter('stream', $streamId)
            ->orderBy('e.sequenceNo', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything a given actor did, most recent first.
     *
     * @return list<AuditEntry>
     */
    public function findByActor(string $actorId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.actorId = :actor')
            ->setParameter('actor', $actorId)
            ->orderBy('e.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
