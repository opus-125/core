<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Subject;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\DataContracts\Subject\SubjectResolverInterface;
use Opus125\GdprBundle\Registry\PersonalDataRegistry;
use Opus125\GdprBundle\Support\EntityIdentifier;

/**
 * Walks the declared object graph from any entity to the data subject(s) it
 * concerns — the sharpest edge of the registry.
 *
 * Resolution follows only `#[SubjectLink]` hops and stops at `#[DataSubject]`
 * roots; it never infers a relationship. It handles:
 *
 *  - **direct** subjects (the entity is itself a `#[DataSubject]`);
 *  - **multi-level** links (`Position → Order → Contact`), by recursion;
 *  - **shared** data (several links, or one value reachable from several
 *    subjects) — every distinct subject is returned;
 *  - **cycles** in the graph — a visited set of object identities breaks them;
 *  - **orphans** — a null/unresolvable link contributes nothing rather than
 *    guessing, so callers stay conservative.
 */
final class SubjectResolver implements SubjectResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalDataRegistry $registry,
    ) {
    }

    /**
     * @return list<SubjectReference>
     */
    public function resolve(object $entity): array
    {
        /** @var array<string, SubjectReference> $found keyed by "Class#id" */
        $found = [];
        $this->walk($entity, $found, []);

        return array_values($found);
    }

    /**
     * @param array<string, SubjectReference> $found   accumulator, by canonical token
     * @param array<int, true>                $visited object ids on the current path
     */
    private function walk(object $entity, array &$found, array $visited): void
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) {
            return; // cycle — stop.
        }
        $visited[$oid] = true;

        if (!$this->entityManager->getMetadataFactory()->hasMetadataFor($entity::class)
            && $this->entityManager->getMetadataFactory()->isTransient($entity::class)) {
            return; // not a managed entity — nothing to traverse.
        }

        $class = $this->entityManager->getClassMetadata($entity::class)->getName();
        $metadata = $this->registry->metadataFor($class);

        if ($metadata->isDataSubject) {
            $ref = new SubjectReference($class, EntityIdentifier::of($this->entityManager, $entity));
            $found[(string) $ref] = $ref;

            return; // a subject is a root; do not traverse past it.
        }

        foreach ($metadata->links as $link) {
            foreach ($this->linkedEntities($entity, $link->property) as $linked) {
                $this->walk($linked, $found, $visited);
            }
        }
    }

    /**
     * The related object(s) behind an association property, initialised enough to
     * traverse further. Returns nothing for a null or empty association.
     *
     * @return iterable<object>
     */
    private function linkedEntities(object $entity, string $property): iterable
    {
        $value = $this->readProperty($entity, $property);

        if (null === $value) {
            return;
        }

        $candidates = is_iterable($value) ? $value : [$value];
        foreach ($candidates as $candidate) {
            if (\is_object($candidate)) {
                // A lazy proxy carries its id, but its own links need the real
                // object loaded before we can read them.
                $this->entityManager->initializeObject($candidate);
                yield $candidate;
            }
        }
    }

    private function readProperty(object $entity, string $property): mixed
    {
        $reflection = new \ReflectionProperty($entity::class, $property);

        return $reflection->isInitialized($entity) ? $reflection->getValue($entity) : null;
    }
}
