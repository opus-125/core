<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Registry;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Lists the Doctrine-mapped entity classes the registry knows something about —
 * i.e. those declaring personal data, a subject link, or being a data subject.
 *
 * Access and erasure are "always complete because registry-driven": rather than
 * a hand-kept list of classes to scan, they ask Doctrine for every mapped entity
 * and keep the ones the registry finds relevant. A new annotated entity is
 * therefore covered automatically.
 */
final class MappedEntityLocator implements EntityClassLocator
{
    /**
     * @var list<class-string>|null
     */
    private ?array $relevant = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalDataRegistry $registry,
    ) {
    }

    /**
     * @return list<class-string>
     */
    public function relevantClasses(): array
    {
        if (null !== $this->relevant) {
            return $this->relevant;
        }

        $relevant = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            /** @var class-string $class */
            $class = $metadata->getName();
            if ($this->registry->metadataFor($class)->isRelevant()) {
                $relevant[] = $class;
            }
        }

        return $this->relevant = $relevant;
    }
}
