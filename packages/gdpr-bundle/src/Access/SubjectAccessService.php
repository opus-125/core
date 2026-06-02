<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Access;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Exception\SubjectNotFoundException;
use Opus125\GdprBundle\Registry\PersonalDataRegistry;
use Opus125\GdprBundle\Subject\SubjectRecordCollector;
use Opus125\GdprBundle\Support\EntityIdentifier;
use Opus125\GdprBundle\Support\SubjectLoader;
use Opus125\GdprBundle\Support\ValueNormalizer;

/**
 * Subject access & portability (GDPR Art. 15 / 20).
 *
 * Given a subject, it traverses the registry to gather **every** personal-data
 * field across **every** entity that belongs to that subject — the subject's own
 * fields plus every record reachable by a declared `#[SubjectLink]` path
 * (multi-level joins included) — into a {@see SubjectDataReport}. Because the set
 * of classes and fields comes from the registry, the collection is always
 * complete; there is no per-entity script to maintain.
 */
final class SubjectAccessService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalDataRegistry $registry,
        private readonly SubjectRecordCollector $collector,
        private readonly SubjectLoader $subjectLoader,
        private readonly ValueNormalizer $normalizer,
    ) {
    }

    /**
     * @throws SubjectNotFoundException when the subject entity cannot be loaded
     */
    public function collect(SubjectReference $subject): SubjectDataReport
    {
        $subjectEntity = $this->subjectLoader->load($subject);
        if (null === $subjectEntity) {
            throw SubjectNotFoundException::for($subject);
        }

        $records = [];
        foreach ($this->collector->collect($subjectEntity, $subject) as $entity) {
            $record = $this->collectRecord($entity);
            if (null !== $record) {
                $records[] = $record;
            }
        }

        return new SubjectDataReport($subject, $records);
    }

    private function collectRecord(object $entity): ?CollectedRecord
    {
        $class = $this->entityManager->getClassMetadata($entity::class)->getName();
        $fieldsMeta = $this->registry->personalData($class);
        if ([] === $fieldsMeta) {
            return null;
        }

        $fields = [];
        foreach ($fieldsMeta as $field) {
            $reflection = new \ReflectionProperty($class, $field->property);
            $value = $reflection->isInitialized($entity) ? $reflection->getValue($entity) : null;
            $fields[] = ['field' => $field, 'value' => $this->normalizer->normalize($value)];
        }

        return new CollectedRecord($class, EntityIdentifier::of($this->entityManager, $entity), $fields);
    }
}
