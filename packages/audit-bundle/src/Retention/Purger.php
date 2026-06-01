<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Retention;

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Model\AbstractAuditEntry;
use Psr\Clock\ClockInterface;

/**
 * Deletes audit entries that have outlived their retention.
 *
 * Retention is resolved per target entity class via the
 * {@see RetentionPolicyInterface}; entries of a class with no policy are kept
 * forever (the conservative default). Deletion uses a DQL bulk delete — still
 * Doctrine, portable across platforms.
 *
 * Honest note: purging the oldest entries leaves the surviving chain unable to
 * prove "nothing before this was omitted"; the remaining chain stays verifiable
 * from its new start.
 */
final class Purger
{
    /**
     * @param class-string<AbstractAuditEntry> $entryClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RetentionPolicyInterface $policy,
        private readonly ClockInterface $clock,
        private readonly string $entryClass,
    ) {
    }

    /**
     * Purge expired entries across all target classes. Returns the number of
     * entries removed.
     */
    public function purge(?\DateTimeImmutable $now = null): int
    {
        $now ??= \DateTimeImmutable::createFromInterface($this->clock->now());
        $removed = 0;

        foreach ($this->targetClasses() as $entityClass) {
            $interval = $this->policy->retentionFor($entityClass);
            if (null === $interval) {
                continue;
            }

            $removed += (int) $this->entityManager->createQuery(
                \sprintf('DELETE FROM %s e WHERE e.entityClass = :class AND e.occurredAt < :cutoff', $this->entryClass),
            )
                ->setParameter('class', $entityClass)
                ->setParameter('cutoff', $now->sub($interval))
                ->execute();
        }

        return $removed;
    }

    /**
     * @return list<class-string>
     */
    private function targetClasses(): array
    {
        /** @var list<array{entityClass: string|null}> $rows */
        $rows = $this->entityManager->createQuery(
            \sprintf('SELECT DISTINCT e.entityClass AS entityClass FROM %s e', $this->entryClass),
        )->getArrayResult();

        $classes = [];
        foreach ($rows as $row) {
            if (null !== $row['entityClass'] && class_exists($row['entityClass'])) {
                $classes[] = $row['entityClass'];
            }
        }

        return $classes;
    }
}
