<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use Opus\AuditBundle\Support\EntityIdentifier;

/**
 * The Spine's default capture mechanism: a Doctrine `onFlush` listener.
 *
 * Running in the ORM event system means it works for any audited entity without
 * schema coupling. It harvests the UnitOfWork's insertions, updates and
 * deletions **and** scheduled collection changes — folding a collection
 * mutation into a single consolidated entry for its owner — then delegates each
 * to the {@see AuditRecorder}.
 *
 * Known, documented limitation: bulk DQL/native `UPDATE`/`DELETE` bypass the
 * UnitOfWork and are therefore *not* captured. This is detective, not provable,
 * completeness; tier E4 (DB triggers) closes that gap.
 */
final class DoctrineAuditListener
{
    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly AuditMetadataFactory $metadataFactory,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $this->recorder->reset();

        $work = $this->collectEntityWork($uow, $em);
        $this->collectCollectionWork($uow, $em, $work);

        // Stable order (class, id) so a flush produces a reproducible chain.
        uasort($work, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        foreach ($work as $item) {
            $this->recorder->record($item['entity'], $item['action'], $item['fields'], $item['collections']);
        }
    }

    /**
     * @return array<int, array{entity: object, action: AuditAction, fields: array<string, array{0: mixed, 1: mixed}>, collections: array<string, array{added: list<mixed>, removed: list<mixed>}>, key: string}>
     */
    private function collectEntityWork(UnitOfWork $uow, EntityManagerInterface $em): array
    {
        $work = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->isAudited($em, $entity)) {
                $work[spl_object_id($entity)] = $this->item($em, $entity, AuditAction::Create, $this->fieldChangeSet($uow, $entity));
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isAudited($em, $entity)) {
                $work[spl_object_id($entity)] = $this->item($em, $entity, AuditAction::Update, $this->fieldChangeSet($uow, $entity));
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->isAudited($em, $entity)) {
                $work[spl_object_id($entity)] = $this->item($em, $entity, AuditAction::Delete, $this->deletionFields($em, $entity));
            }
        }

        return $work;
    }

    /**
     * @param array<int, array{entity: object, action: AuditAction, fields: array<string, array{0: mixed, 1: mixed}>, collections: array<string, array{added: list<mixed>, removed: list<mixed>}>, key: string}> $work
     */
    private function collectCollectionWork(UnitOfWork $uow, EntityManagerInterface $em, array &$work): void
    {
        $collections = [...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()];

        foreach ($collections as $collection) {
            $owner = $collection->getOwner();
            if (null === $owner || !$this->isAudited($em, $owner)) {
                continue;
            }

            $oid = spl_object_id($owner);
            $work[$oid] ??= $this->item($em, $owner, AuditAction::Update, $this->fieldChangeSet($uow, $owner));

            $field = $collection->getMapping()->fieldName;
            $work[$oid]['collections'][$field] = [
                'added' => array_values($collection->getInsertDiff()),
                'removed' => array_values($collection->getDeleteDiff()),
            ];
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $fields
     *
     * @return array{entity: object, action: AuditAction, fields: array<string, array{0: mixed, 1: mixed}>, collections: array<string, array{added: list<mixed>, removed: list<mixed>}>, key: string}
     */
    private function item(EntityManagerInterface $em, object $entity, AuditAction $action, array $fields): array
    {
        $class = $em->getClassMetadata($entity::class)->getName();

        return [
            'entity' => $entity,
            'action' => $action,
            'fields' => $fields,
            'collections' => [],
            'key' => $class.'#'.EntityIdentifier::of($em, $entity),
        ];
    }

    /**
     * For a deletion Doctrine provides no change set, so capture the prior state
     * of the auditable scalar fields and to-one associations as `value → null`.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function deletionFields(EntityManagerInterface $em, object $entity): array
    {
        $classMetadata = $em->getClassMetadata($entity::class);
        $identifierFields = array_flip($classMetadata->getIdentifierFieldNames());
        $fields = [];

        foreach ($classMetadata->getFieldNames() as $field) {
            if (isset($identifierFields[$field])) {
                continue;
            }
            $fields[$field] = [$classMetadata->getFieldValue($entity, $field), null];
        }

        foreach ($classMetadata->getAssociationNames() as $field) {
            if ($classMetadata->isSingleValuedAssociation($field)) {
                $fields[$field] = [$classMetadata->getFieldValue($entity, $field), null];
            }
        }

        return $fields;
    }

    /**
     * The entity's scalar/to-one change set as `field => [old, new]`.
     *
     * Doctrine's change set may also contain collection-valued entries; those
     * are captured separately from the scheduled collection changes, so they are
     * filtered out here.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function fieldChangeSet(UnitOfWork $uow, object $entity): array
    {
        $fields = [];
        foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
            if (\is_array($change)) {
                $fields[$field] = [$change[0] ?? null, $change[1] ?? null];
            }
        }

        return $fields;
    }

    private function isAudited(EntityManagerInterface $em, object $entity): bool
    {
        return $this->metadataFactory->isAuditable($em->getClassMetadata($entity::class)->getName());
    }
}
