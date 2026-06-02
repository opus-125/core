<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\Application;
use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Crypto\SubjectKeyProviderInterface;

/**
 * Project override of the audit key provider: the key lives on the applicant
 * ({@see \App\Entity\Citizen}). An audited {@see Application}'s sensitive values
 * are protected by its applicant's key; anonymising the citizen drops the key,
 * so those values become permanently unreadable (crypto-shredding) — no
 * bundle-owned state, just a normal domain operation.
 */
final class CitizenKeyProvider implements SubjectKeyProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function keyFor(string $entityClass, string $entityId): ?string
    {
        if (Application::class !== $entityClass) {
            return null;
        }

        $application = $this->em->find(Application::class, $entityId);

        return $application?->getApplicant()->getAuditKey();
    }
}
