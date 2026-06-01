<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Actor\ActorResolverInterface;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\StreamLockInterface;
use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use Opus\AuditBundle\Model\AbstractAuditEntry;
use Opus\AuditBundle\Subject\SubjectResolverInterface;
use Opus\AuditBundle\Support\EntityIdentifier;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assembles audit entries and adds them to the current unit of work.
 *
 * Entries are created as ordinary Doctrine entities and scheduled with
 * {@see \Doctrine\ORM\UnitOfWork::computeChangeSet()} so they are inserted in
 * the same flush — and therefore the same transaction — as the business change,
 * on any Doctrine platform and without raw SQL.
 *
 * Sequence numbers and the hash chain are tracked in-memory across one flush so
 * several changes to the same stream chain correctly before anything is
 * committed.
 */
final class AuditRecorder
{
    /**
     * @var array<string, array{0: int, 1: string}> per-stream [sequenceNo, hash] within the current flush
     */
    private array $heads = [];

    /**
     * @param class-string<AbstractAuditEntry> $entryClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly ChangeSetNormalizer $normalizer,
        private readonly ActorResolverInterface $actorResolver,
        private readonly SubjectResolverInterface $subjectResolver,
        private readonly AuditContextProvider $contextProvider,
        private readonly ActorContextEncryptor $actorEncryptor,
        private readonly HashCalculator $hashCalculator,
        private readonly CanonicalJsonEncoder $encoder,
        private readonly StreamLockInterface $lock,
        private readonly ClockInterface $clock,
        private readonly string $entryClass,
    ) {
    }

    /**
     * Forget the in-flush chain state. Called at the start of each flush.
     */
    public function reset(): void
    {
        $this->heads = [];
    }

    /**
     * Record a mutating change. No-op for non-auditable entities.
     *
     * @param array<string, array{0: mixed, 1: mixed}>                       $fieldChangeSet
     * @param array<string, array{added: list<mixed>, removed: list<mixed>}> $collectionChanges
     */
    public function record(object $entity, AuditAction $action, array $fieldChangeSet, array $collectionChanges = []): void
    {
        $class = $this->entityManager->getClassMetadata($entity::class)->getName();
        $metadata = $this->metadataFactory->getMetadata($class);

        if (!$metadata->auditable) {
            return;
        }

        $subjects = $this->subjectResolver->resolveForEntity($entity);
        $changes = $this->normalizer->normalize($metadata, $fieldChangeSet, $collectionChanges, $subjects);

        $this->append($metadata->stream, $action->value, $class, EntityIdentifier::of($this->entityManager, $entity), $changes);
    }

    /**
     * Record a non-mutating action (download, export, view, …). It is chained
     * into the target entity's stream when given, otherwise a generic stream.
     *
     * @param array<string, mixed> $context
     */
    public function recordAction(string $action, ?object $entity = null, array $context = []): void
    {
        $stream = 'event';
        $entityClass = null;
        $entityId = null;

        if (null !== $entity) {
            $entityClass = $this->entityManager->getClassMetadata($entity::class)->getName();
            $metadata = $this->metadataFactory->getMetadata($entityClass);
            $stream = $metadata->auditable ? $metadata->stream : $entityClass;
            $entityId = EntityIdentifier::of($this->entityManager, $entity);
        }

        $this->append($stream, $action, $entityClass, $entityId, [], $context);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $extraContext
     */
    private function append(string $stream, string $action, ?string $entityClass, ?string $entityId, array $changes, array $extraContext = []): void
    {
        $actor = $this->actorResolver->resolve();
        $actorSubjects = $this->subjectResolver->resolveForActor($actor);

        [$public, $sensitive] = $this->contextProvider->gather($actor);
        [$context, $actorLabel] = $this->actorEncryptor->apply(array_merge($public, $extraContext), $sensitive, $actor->getAuditActorLabel(), $actorSubjects);

        $this->lock->lock($stream);
        [$previousSequence, $previousHash] = $this->headFor($stream);
        $sequenceNo = $previousSequence + 1;

        $entry = $this->newEntry();
        $entry->initialize(
            Uuid::v7()->toRfc4122(),
            $stream,
            $this->now(),
            $action,
            $entityClass,
            $entityId,
            $actor->getAuditActorType(),
            $actor->getAuditActorId(),
            $actorLabel,
            $this->roundTrip($changes),
            $this->roundTrip($context),
        );

        // Sequence first — it is part of the hashed payload — then the hash.
        $entry->assignSequence($sequenceNo, $previousHash);
        $hash = $this->hashCalculator->hash($entry->hashableData(), $previousHash);
        $entry->setHash($hash);

        $this->entityManager->persist($entry);
        $this->entityManager->getUnitOfWork()->computeChangeSet(
            $this->entityManager->getClassMetadata($this->entryClass),
            $entry,
        );

        $this->heads[$stream] = [$sequenceNo, $hash];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function headFor(string $stream): array
    {
        if (isset($this->heads[$stream])) {
            return $this->heads[$stream];
        }

        /** @var array{sequenceNo: int|string, hash: string}|null $row */
        $row = $this->entityManager->createQueryBuilder()
            ->select('e.sequenceNo AS sequenceNo', 'e.hash AS hash')
            ->from($this->entryClass, 'e')
            ->where('e.stream = :stream')
            ->setParameter('stream', $stream)
            ->orderBy('e.sequenceNo', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->heads[$stream] = null === $row
            ? [0, HashCalculator::GENESIS_HASH]
            : [(int) $row['sequenceNo'], $row['hash']];
    }

    private function newEntry(): AbstractAuditEntry
    {
        return new $this->entryClass();
    }

    private function now(): \DateTimeImmutable
    {
        // Second precision keeps occurred_at hashable losslessly on any platform.
        return new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
    }

    /**
     * Normalise through JSON exactly as Doctrine will persist it, so the hash
     * computed now matches the value re-read during verification.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function roundTrip(array $data): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->encoder->encode($data), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
