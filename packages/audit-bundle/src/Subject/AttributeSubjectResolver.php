<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Subject;

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use Opus\AuditBundle\Support\EntityIdentifier;

/**
 * Default {@see SubjectResolverInterface}.
 *
 * Uses the `#[DataSubject]` declarations: each marked property contributes a
 * subject id (an association via its identifier, a scalar as itself). When a
 * class declares none, it falls back to the heuristic that the record is about
 * *itself* — the subject is the entity, keyed by class and identifier — so
 * `#[Sensitive]` data is never left without a shredding key.
 *
 * For actors, a non-anonymous, identified actor maps to a namespaced
 * `actor:<type>:<id>` subject, so the acting person's encrypted label and
 * context can be shredded alongside everything else about them.
 */
final class AttributeSubjectResolver implements SubjectResolverInterface
{
    public function __construct(
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveForEntity(object $entity): array
    {
        $class = $this->entityManager->getClassMetadata($entity::class)->getName();
        $metadata = $this->metadataFactory->getMetadata($class);
        $classMetadata = $this->entityManager->getClassMetadata($class);

        $subjectFields = $metadata->subjectFields();

        if ([] === $subjectFields) {
            // Heuristic: the record is about itself.
            return [$this->entitySubjectId($class, EntityIdentifier::of($this->entityManager, $entity))];
        }

        $subjects = [];
        foreach ($subjectFields as $field) {
            $value = $classMetadata->getFieldValue($entity, $field);

            if (null === $value) {
                continue;
            }

            $subjects[] = \is_object($value)
                ? $this->entitySubjectId($this->entityManager->getClassMetadata($value::class)->getName(), EntityIdentifier::of($this->entityManager, $value))
                : $this->scalarSubjectId($class, $field, $value);
        }

        return array_values(array_unique($subjects));
    }

    public function resolveForActor(Actor $actor): array
    {
        if (null === $actor->id || ActorType::Anonymous === $actor->type || ActorType::System === $actor->type) {
            return [];
        }

        return [\sprintf('actor:%s:%s', $actor->type->value, $actor->id)];
    }

    private function entitySubjectId(string $class, string $identifier): string
    {
        return \sprintf('%s#%s', $class, $identifier);
    }

    private function scalarSubjectId(string $class, string $field, mixed $value): string
    {
        return \sprintf('%s.%s#%s', $class, $field, EntityIdentifier::scalar($this->entityManager, $value));
    }
}
