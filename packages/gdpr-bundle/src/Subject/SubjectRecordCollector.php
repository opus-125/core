<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Subject;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Registry\EntityClassLocator;
use Opus125\GdprBundle\Support\EntityIdentifier;

/**
 * Gathers the live entity objects that belong to a subject: the subject entity
 * itself plus every record reachable by a declared `#[SubjectLink]` path
 * (multi-level joins included), de-duplicated.
 *
 * Shared by access (which reads the entities' fields) and erasure (which mutates
 * them), so both traverse the registry identically.
 */
final class SubjectRecordCollector
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SubjectPathResolver $pathResolver,
        private readonly EntityClassLocator $locator,
    ) {
    }

    /**
     * @return list<object> the subject entity first, then its linked records
     */
    public function collect(object $subjectEntity, SubjectReference $subject): array
    {
        /** @var array<string, object> $records keyed by "Class#id" */
        $records = [(string) $subject => $subjectEntity];

        foreach ($this->locator->relevantClasses() as $class) {
            if ($class === $subject->entityClass) {
                continue;
            }

            foreach ($this->pathResolver->pathsTo($class, $subject->entityClass) as $subjectPath) {
                foreach ($this->queryLinked($class, $subjectPath->path, $subjectEntity) as $entity) {
                    $key = $this->entityManager->getClassMetadata($entity::class)->getName()
                        .'#'.EntityIdentifier::of($this->entityManager, $entity);
                    $records[$key] = $entity;
                }
            }
        }

        return array_values($records);
    }

    /**
     * @param class-string $class
     * @param list<string> $path
     *
     * @return list<object>
     */
    private function queryLinked(string $class, array $path, object $subjectEntity): array
    {
        if ([] === $path) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()->select('e0')->from($class, 'e0');

        $alias = 'e0';
        foreach ($path as $i => $property) {
            $next = 'e'.($i + 1);
            $qb->innerJoin($alias.'.'.$property, $next);
            $alias = $next;
        }

        /** @var list<object> $result */
        $result = $qb->where($alias.' = :subject')
            ->setParameter('subject', $subjectEntity)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
