<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Actor\ActorResolverInterface;
use Opus\AuditBundle\Crypto\SubjectKeyProviderInterface;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Event\AuditEntryRecorded;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Metadata\AuditAttributeReader;
use Opus\AuditBundle\Model\AuditEntryInterface;
use Opus\AuditBundle\Support\EntityIdentifier;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assembles audit entries and adds them to the current unit of work.
 *
 * Entries are created as ordinary Doctrine entities (the concrete class is
 * resolved from {@see AuditEntryInterface} via `resolve_target_entities`) and
 * scheduled with {@see \Doctrine\ORM\UnitOfWork::computeChangeSet()}, so they
 * are inserted in the same flush — and transaction — as the business change, on
 * any Doctrine platform and without raw SQL.
 *
 * Concurrency: `sequence_no` is `head + 1` per stream, guarded by the unique
 * `(stream, sequence_no)` constraint — a rare concurrent collision fails the
 * transaction (and is retried) rather than corrupting the chain; no database
 * lock is needed by default.
 */
final class AuditRecorder
{
    /**
     * @var array<string, array{0: int, 1: string}> per-stream [sequenceNo, hash] within the current flush
     */
    private array $heads = [];

    /**
     * @var class-string<AuditEntryInterface>|null
     */
    private ?string $entryClass = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditAttributeReader $reader,
        private readonly ChangeSetNormalizer $normalizer,
        private readonly ActorResolverInterface $actorResolver,
        private readonly SubjectKeyProviderInterface $keyProvider,
        private readonly AuditContextProvider $contextProvider,
        private readonly HashCalculator $hashCalculator,
        private readonly CanonicalJsonEncoder $encoder,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
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
        if (!$this->reader->isAuditable($class)) {
            return;
        }

        $entityId = EntityIdentifier::of($this->entityManager, $entity);
        $key = $this->keyProvider->keyFor($class, $entityId);
        $changes = $this->normalizer->normalize($class, $fieldChangeSet, $collectionChanges, $key);

        $this->append($this->reader->stream($class), $action->value, $class, $entityId, $changes);
    }

    /**
     * Record a non-mutating action (download, export, view, …), chained into the
     * target entity's stream when given, otherwise a generic stream.
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
            $stream = $this->reader->isAuditable($entityClass) ? $this->reader->stream($entityClass) : $entityClass;
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
        $context = array_merge($this->contextProvider->gather($actor), $extraContext);

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
            $actor->getAuditActorLabel(),
            $this->roundTrip($changes),
            $this->roundTrip($context),
        );

        // Sequence first — it is part of the hashed payload — then the hash.
        $entry->assignSequence($sequenceNo, $previousHash);
        $entry->setHash($this->hashCalculator->hash($entry->hashableData(), $previousHash));

        $this->entityManager->persist($entry);
        $this->entityManager->getUnitOfWork()->computeChangeSet(
            $this->entityManager->getClassMetadata($entry::class),
            $entry,
        );

        $this->heads[$stream] = [$sequenceNo, $entry->getHash()];

        $this->eventDispatcher?->dispatch(new AuditEntryRecorded($entry));
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
            ->from($this->resolveEntryClass(), 'e')
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

    private function newEntry(): AuditEntryInterface
    {
        $class = $this->resolveEntryClass();

        return new $class();
    }

    /**
     * @return class-string<AuditEntryInterface>
     */
    private function resolveEntryClass(): string
    {
        /** @var class-string<AuditEntryInterface> $class */
        $class = $this->entityManager->getClassMetadata(AuditEntryInterface::class)->getName();

        return $this->entryClass ??= $class;
    }

    private function now(): \DateTimeImmutable
    {
        // Second precision keeps occurred_at hashable losslessly on any platform.
        return new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
    }

    /**
     * Normalise through the canonical encoder exactly as it will be hashed and
     * stored, so write-time and read-time hashes match.
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
