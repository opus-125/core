<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Actor\ActorResolverInterface;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Integrity\AppendedLink;
use Opus\AuditBundle\Integrity\ChainBackendInterface;
use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use Opus\AuditBundle\Model\PendingAuditEntry;
use Opus\AuditBundle\Subject\SubjectResolverInterface;
use Opus\AuditBundle\Support\CanonicalTimestamp;
use Opus\AuditBundle\Support\EntityIdentifier;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assembles a {@see PendingAuditEntry} for a single entity change and appends it
 * to the chain.
 *
 * Brings the pieces together: metadata gating, change normalisation/encryption,
 * actor resolution, subject resolution and the encryption of actor PII
 * (label, IP, user-agent) under the actor's own subject key, the
 * application-side UUIDv7 id (known before flush, so the whole write stays in
 * one `onFlush` pass), and the timestamp.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly ChangeSetNormalizer $normalizer,
        private readonly ChainBackendInterface $chain,
        private readonly ActorResolverInterface $actorResolver,
        private readonly SubjectResolverInterface $subjectResolver,
        private readonly AuditContextProvider $contextProvider,
        private readonly ActorContextEncryptor $actorContextEncryptor,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Record one entity change. Returns null when the entity is not auditable.
     *
     * @param array<string, array{0: mixed, 1: mixed}>                       $fieldChangeSet
     * @param array<string, array{added: list<mixed>, removed: list<mixed>}> $collectionChanges
     */
    public function record(
        object $entity,
        AuditAction $action,
        array $fieldChangeSet,
        array $collectionChanges = [],
    ): ?AppendedLink {
        $class = $this->entityManager->getClassMetadata($entity::class)->getName();
        $metadata = $this->metadataFactory->getMetadata($class);

        if (!$metadata->auditable) {
            return null;
        }

        $subjects = $this->subjectResolver->resolveForEntity($entity);
        $changes = $this->normalizer->normalize($metadata, $fieldChangeSet, $collectionChanges, $subjects);

        $actor = $this->actorResolver->resolve();
        $actorSubjects = $this->subjectResolver->resolveForActor($actor);
        [$context, $actorLabel] = $this->resolveActorContext($actor, $actorSubjects);

        $entry = new PendingAuditEntry(
            id: Uuid::v7()->toRfc4122(),
            streamId: $metadata->stream,
            occurredAt: CanonicalTimestamp::format($this->clock->now()),
            action: $action,
            entityClass: $class,
            entityId: EntityIdentifier::of($this->entityManager, $entity),
            actorType: $actor->type,
            actorId: $actor->id,
            actorLabel: $actorLabel,
            changes: $changes,
            context: $context,
        );

        return $this->chain->append($entry);
    }

    /**
     * @param list<string> $actorSubjects
     *
     * @return array{0: array<string, mixed>, 1: string|null} [context, actorLabel]
     */
    private function resolveActorContext(Actor $actor, array $actorSubjects): array
    {
        [$public, $sensitive] = $this->contextProvider->gather($actor);

        return $this->actorContextEncryptor->apply($public, $sensitive, $actor->label, $actorSubjects);
    }
}
