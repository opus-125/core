<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Support;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\DataContracts\Subject\SubjectReference;

/**
 * Loads the managed entity behind a {@see SubjectReference}, reversing the
 * identifier rendering of {@see EntityIdentifier}: a single-field id is passed
 * through, a composite id is JSON-decoded back into its field map.
 */
final class SubjectLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function load(SubjectReference $subject): ?object
    {
        $metadata = $this->entityManager->getClassMetadata($subject->entityClass);
        $idFields = $metadata->getIdentifierFieldNames();

        if (1 === \count($idFields)) {
            return $this->entityManager->find($subject->entityClass, $subject->entityId);
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($subject->entityId, true);
        if (!\is_array($decoded)) {
            return null;
        }

        return $this->entityManager->find($subject->entityClass, $decoded);
    }
}
