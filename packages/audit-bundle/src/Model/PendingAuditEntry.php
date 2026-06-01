<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Enum\AuditAction;

/**
 * An audit entry assembled by the recorder but not yet placed in its chain.
 *
 * It carries everything about a change *except* the three chain fields —
 * `sequence_no`, `previous_hash`, `hash` — which only the
 * {@see \Opus\AuditBundle\Integrity\ChainBackendInterface} can assign, under the
 * per-stream lock, at append time.
 */
final readonly class PendingAuditEntry
{
    /**
     * @param array<string, mixed> $changes `{field: {old, new}}`, `#[Sensitive]` values already enveloped
     * @param array<string, mixed> $context circumstances (ip, route, correlation id, …)
     */
    public function __construct(
        public string $id,
        public string $streamId,
        public string $occurredAt,
        public AuditAction $action,
        public string $entityClass,
        public string $entityId,
        public ActorType $actorType,
        public ?string $actorId,
        public ?string $actorLabel,
        public array $changes,
        public array $context,
        public bool $legalHold = false,
        public ?int $versionNo = null,
        public ?string $snapshotHash = null,
        public ?string $signature = null,
        public ?string $signerRef = null,
    ) {
    }

    /**
     * The full column map for the DBAL INSERT, given the assigned chain fields.
     *
     * @return array<string, mixed>
     */
    public function toRow(int $sequenceNo, string $previousHash, string $hash): array
    {
        $row = $this->rowWithoutHash($sequenceNo, $previousHash);
        $row['hash'] = $hash;

        return $row;
    }

    /**
     * The column map without the `hash`, used to compute the hash. The hashable
     * subset ({@see AuditEntry::hashablePayload()}) ignores mutable columns such
     * as `legal_hold`, so they may be present here harmlessly.
     *
     * @return array<string, mixed>
     */
    public function rowWithoutHash(int $sequenceNo, string $previousHash): array
    {
        return [
            'id' => $this->id,
            'stream_id' => $this->streamId,
            'sequence_no' => $sequenceNo,
            'occurred_at' => $this->occurredAt,
            'action' => $this->action->value,
            'entity_class' => $this->entityClass,
            'entity_id' => $this->entityId,
            'actor_type' => $this->actorType->value,
            'actor_id' => $this->actorId,
            'actor_label' => $this->actorLabel,
            'changes' => $this->changes,
            'context' => $this->context,
            'legal_hold' => $this->legalHold,
            'previous_hash' => $previousHash,
            'version_no' => $this->versionNo,
            'snapshot_hash' => $this->snapshotHash,
            'signature' => $this->signature,
            'signer_ref' => $this->signerRef,
        ];
    }
}
