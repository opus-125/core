<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Enum\ActorType;

/**
 * Mapped-superclass implementation of {@see AuditEntryInterface}.
 *
 * Plain Doctrine — written through the EntityManager like any other entity, so
 * it works on every Doctrine DBAL platform. Each entry chains onto its
 * predecessor in the same stream via {@see $hash}/{@see $previousHash}, making
 * the trail tamper-evident; the hash is computed over {@see hashableData()}.
 *
 * Two fields hold personal data and may be stored encrypted (as an envelope):
 * the `#[Sensitive]` values inside {@see $changes} and the {@see $actorLabel}.
 * Crypto-shredding destroys the key, never the ciphertext, so the chain stays
 * valid after erasure.
 */
#[ORM\MappedSuperclass]
abstract class AbstractAuditEntry implements AuditEntryInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    protected string $id;

    #[ORM\Column(length: 255)]
    protected string $stream;

    #[ORM\Column(name: 'sequence_no', type: Types::BIGINT)]
    protected int $sequenceNo = 0;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE)]
    protected \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64)]
    protected string $action;

    #[ORM\Column(name: 'entity_class', length: 255, nullable: true)]
    protected ?string $entityClass = null;

    #[ORM\Column(name: 'entity_id', length: 255, nullable: true)]
    protected ?string $entityId = null;

    #[ORM\Column(name: 'actor_type', length: 16, enumType: ActorType::class)]
    protected ActorType $actorType = ActorType::System;

    #[ORM\Column(name: 'actor_id', length: 255, nullable: true)]
    protected ?string $actorId = null;

    #[ORM\Column(name: 'actor_label', type: Types::TEXT, nullable: true)]
    protected ?string $actorLabel = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $changes = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $context = [];

    #[ORM\Column(name: 'previous_hash', length: 64)]
    protected string $previousHash = '';

    #[ORM\Column(length: 64)]
    protected string $hash = '';

    public function getId(): string
    {
        return $this->id;
    }

    public function getStream(): string
    {
        return $this->stream;
    }

    public function getSequenceNo(): int
    {
        return $this->sequenceNo;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function getActorType(): ActorType
    {
        return $this->actorType;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getActorLabel(): ?string
    {
        return $this->actorLabel;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getPreviousHash(): string
    {
        return $this->previousHash;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    // -- Construction (used by the recorder before the entry is persisted) ----

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $context
     */
    public function initialize(
        string $id,
        string $stream,
        \DateTimeImmutable $occurredAt,
        string $action,
        ?string $entityClass,
        ?string $entityId,
        ActorType $actorType,
        ?string $actorId,
        ?string $actorLabel,
        array $changes,
        array $context,
    ): void {
        $this->id = $id;
        $this->stream = $stream;
        $this->occurredAt = $occurredAt;
        $this->action = $action;
        $this->entityClass = $entityClass;
        $this->entityId = $entityId;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->actorLabel = $actorLabel;
        $this->changes = $changes;
        $this->context = $context;
    }

    /**
     * Assign the stream position before hashing — `sequence_no` is part of the
     * hashed payload, so it must be set first.
     */
    public function assignSequence(int $sequenceNo, string $previousHash): void
    {
        $this->sequenceNo = $sequenceNo;
        $this->previousHash = $previousHash;
    }

    public function setHash(string $hash): void
    {
        $this->hash = $hash;
    }

    /**
     * The deterministic payload the hash is computed over — the single
     * definition shared by writing and verification. Pins every value to a
     * precise type (sequence as int, the instant as a second-precision UTC
     * string) so a row re-reads and re-hashes identically.
     *
     * @return array<string, mixed>
     */
    public function hashableData(): array
    {
        return [
            'id' => $this->id,
            'stream' => $this->stream,
            'sequence_no' => $this->sequenceNo,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'action' => $this->action,
            'entity_class' => $this->entityClass,
            'entity_id' => $this->entityId,
            'actor_type' => $this->actorType->value,
            'actor_id' => $this->actorId,
            'actor_label' => $this->actorLabel,
            'changes' => $this->changes,
            'context' => $this->context,
        ];
    }
}
