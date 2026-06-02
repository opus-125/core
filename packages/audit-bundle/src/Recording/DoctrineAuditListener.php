<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Opus125\AuditBundle\Enum\AuditAction;
use Opus125\AuditBundle\Metadata\AuditAttributeReader;
use Opus125\AuditBundle\Support\EntityIdentifier;
use Opus125\AuditBundle\Workflow\PendingTransition;
use Opus125\AuditBundle\Workflow\WorkflowTransitionBuffer;

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
 *
 * When the Symfony Workflow component is in use it also drains the
 * {@see WorkflowTransitionBuffer}: transitions applied since the last flush are
 * recorded here, in the same flush — and transaction — as the marking change
 * they describe, and the marking field is dropped from the ordinary field entry
 * so the status change is logged once, as the richer `transition` entry.
 */
final class DoctrineAuditListener
{
    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly AuditAttributeReader $reader,
        private readonly ?WorkflowTransitionBuffer $workflowBuffer = null,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $this->recorder->reset();

        // Transitions whose marking is actually being persisted in this flush,
        // grouped by the subject's object id.
        $transitions = $this->pendingTransitions($uow);

        $work = $this->collectEntityWork($uow, $em);
        $this->collectCollectionWork($uow, $em, $work);

        // Stable order (class, id) so a flush produces a reproducible chain.
        uasort($work, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        foreach ($work as $oid => $item) {
            $pending = $transitions[$oid] ?? [];
            $fields = [] === $pending ? $item['fields'] : $this->withoutMarking($em, $item, $pending);

            // A transition that only moved the marking leaves an empty update —
            // the transition entry below is the sole, richer record of it.
            if ([] === $fields && [] === $item['collections'] && AuditAction::Update === $item['action'] && [] !== $pending) {
                continue;
            }

            $this->recorder->record($item['entity'], $item['action'], $fields, $item['collections']);
        }

        $this->recordTransitions($transitions);
    }

    /**
     * Drain the buffer, keeping only transitions whose subject is inserted or
     * updated in this flush — i.e. whose marking is being persisted now. Anything
     * else (applied but never flushed) is dropped rather than left to attach to a
     * later, unrelated flush.
     *
     * @return array<int, list<PendingTransition>> keyed by subject object id, stable subject order
     */
    private function pendingTransitions(UnitOfWork $uow): array
    {
        if (null === $this->workflowBuffer) {
            return [];
        }

        $persisted = [];
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entity) {
            $persisted[spl_object_id($entity)] = true;
        }

        $grouped = [];
        foreach ($this->workflowBuffer->drain() as $transition) {
            $oid = spl_object_id($transition->subject);
            if (isset($persisted[$oid])) {
                $grouped[$oid][] = $transition;
            }
        }

        return $grouped;
    }

    /**
     * @param array<int, list<PendingTransition>> $transitions
     */
    private function recordTransitions(array $transitions): void
    {
        foreach ($transitions as $pending) {
            foreach ($pending as $transition) {
                $this->recorder->recordTransition(
                    $transition->subject,
                    $transition->workflow,
                    $transition->transition,
                    $transition->froms,
                    $transition->tos,
                );
            }
        }
    }

    /**
     * Remove the marking field from a transition subject's field change set so
     * it is not logged both as a field update and as a transition. The field is
     * taken from `#[AuditableWorkflow(marking: ...)]` when declared, otherwise
     * matched by its new value landing on one of the transition's target places.
     *
     * @param array{entity: object, action: AuditAction, fields: array<string, array{0: mixed, 1: mixed}>, collections: array<string, array{added: list<mixed>, removed: list<mixed>}>, key: string} $item
     * @param list<PendingTransition>                                                                                                                                                                $pending
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function withoutMarking(EntityManagerInterface $em, array $item, array $pending): array
    {
        $fields = $item['fields'];
        $class = $em->getClassMetadata($item['entity']::class)->getName();

        $declared = $this->reader->workflowMarking($class);
        if (null !== $declared) {
            unset($fields[$declared]);

            return $fields;
        }

        foreach ($fields as $field => [, $new]) {
            foreach ($pending as $transition) {
                if (null !== $new && \in_array((string) $new, $transition->tos, true)) {
                    unset($fields[$field]);

                    continue 2;
                }
            }
        }

        return $fields;
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
        return $this->reader->isAuditable($em->getClassMetadata($entity::class)->getName());
    }
}
