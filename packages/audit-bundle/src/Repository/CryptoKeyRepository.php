<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Opus\AuditBundle\Model\CryptoKey;

/**
 * Storage of per-subject wrapped DEKs for the default Doctrine keystore.
 *
 * @extends EntityRepository<CryptoKey>
 */
class CryptoKeyRepository extends EntityRepository
{
    public function findBySubject(string $subjectId): ?CryptoKey
    {
        return $this->find($subjectId);
    }
}
