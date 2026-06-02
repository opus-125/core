<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Model;

use Opus125\AuditBundle\Enum\ActorType;

/**
 * The audit record contract.
 *
 * The bundle ships {@see AuditEntry} as the default Doctrine entity. To use your
 * own table, map an entity that implements this interface (most easily
 * `use AuditEntryTrait;`) and point the interface at it with Doctrine
 * `resolve_target_entities`.
 *
 * One entry type covers both mutating changes (`create`/`update`/`delete`) and
 * non-mutating actions (e.g. `download`); the latter simply have an action name
 * and no field changes.
 */
interface AuditEntryInterface
{
    public function getId(): string;

    public function getStream(): string;

    public function getSequenceNo(): int;

    public function getOccurredAt(): \DateTimeImmutable;

    public function getAction(): string;

    public function getEntityClass(): ?string;

    public function getEntityId(): ?string;

    public function getActorType(): ActorType;

    public function getActorId(): ?string;

    public function getActorLabel(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function getChanges(): array;

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array;

    public function getPreviousHash(): string;

    public function getHash(): string;

    /**
     * The deterministic payload the {@see getHash()} is computed over.
     *
     * @return array<string, mixed>
     */
    public function hashableData(): array;

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
    ): void;

    public function assignSequence(int $sequenceNo, string $previousHash): void;

    public function setHash(string $hash): void;
}
