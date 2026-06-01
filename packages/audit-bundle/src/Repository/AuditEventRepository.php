<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Opus\AuditBundle\Model\AuditEvent;

/**
 * Read access to the lighter, non-chained {@see AuditEvent} log.
 *
 * @extends EntityRepository<AuditEvent>
 */
class AuditEventRepository extends EntityRepository
{
    /**
     * @return list<AuditEvent>
     */
    public function findForTarget(string $entityClass, string $entityId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.entityClass = :class')
            ->andWhere('e.entityId = :id')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->orderBy('e.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
