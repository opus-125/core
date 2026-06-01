<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Opus\AuditBundle\Model\ShreddedSubject;

/**
 * @extends EntityRepository<ShreddedSubject>
 */
class ShreddedSubjectRepository extends EntityRepository
{
    public function isShredded(string $subjectId): bool
    {
        return null !== $this->find($subjectId);
    }
}
