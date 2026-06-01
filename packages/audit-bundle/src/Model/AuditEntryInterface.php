<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Opus\AuditBundle\Enum\ActorType;

/**
 * The audit record contract.
 *
 * The bundle ships {@see AuditEntry} as the default Doctrine entity, but a
 * project may use its own entity instead — map it, implement this interface
 * (most easily by extending {@see AbstractAuditEntry}) and point
 * `opus_audit.entry_class` at it.
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
}
