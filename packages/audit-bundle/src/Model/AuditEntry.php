<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Repository\AuditEntryRepository;

/**
 * One immutable, hash-chained record of a mutating change (Spine).
 *
 * Entries are append-only and tamper-evident: each carries the hash of its
 * predecessor in the same stream, so altering or dropping any historical entry
 * breaks every following hash (detected by `audit:verify`).
 *
 * Two fields are intentionally *mutable* and therefore **excluded from the
 * hash**: {@see $legalHold} (a hold can be placed/lifted over time) and the E3
 * {@see $signature}/{@see $signerRef} (added after the entry is hashed). The
 * hashed payload is defined by {@see self::hashablePayload()}. Everything else —
 * including the `#[Sensitive]` ciphertext in {@see $changes} — is immutable, so
 * crypto-shredding (which destroys keys, never ciphertext) keeps the chain
 * valid.
 *
 * Rows are *written* via DBAL during `onFlush` (for transactional atomicity and
 * advisory-lock sequencing); this ORM mapping exists for schema generation and
 * read access.
 */
#[ORM\Entity(repositoryClass: AuditEntryRepository::class, readOnly: true)]
#[ORM\Table(name: Schema::ENTRY_TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_audit_entry_stream_seq', columns: ['stream_id', 'sequence_no'])]
#[ORM\Index(name: 'idx_audit_entry_target', columns: ['entity_class', 'entity_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_entry_actor', columns: ['actor_id', 'occurred_at'])]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'stream_id', type: Types::STRING, length: 255)]
    private string $streamId;

    #[ORM\Column(name: 'sequence_no', type: Types::BIGINT)]
    private int $sequenceNo;

    /**
     * Canonical RFC 3339 UTC instant with microseconds, e.g.
     * `2026-06-01T10:30:00.123456Z`. Stored as a sortable string so the value
     * that is hashed round-trips losslessly (a `timestamptz` column would
     * truncate the microseconds the hash depends on).
     */
    #[ORM\Column(name: 'occurred_at', type: Types::STRING, length: 32)]
    private string $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(name: 'entity_class', type: Types::STRING, length: 255)]
    private string $entityClass;

    /**
     * The target's identifier. A scalar id as-is; a composite key as a JSON
     * object string.
     */
    #[ORM\Column(name: 'entity_id', type: Types::STRING, length: 255)]
    private string $entityId;

    #[ORM\Column(name: 'actor_type', type: Types::STRING, length: 16, enumType: ActorType::class)]
    private ActorType $actorType;

    #[ORM\Column(name: 'actor_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $actorId;

    /**
     * Denormalised actor display name. Personal data — stored encrypted when a
     * keystore is configured.
     */
    #[ORM\Column(name: 'actor_label', type: Types::TEXT, nullable: true)]
    private ?string $actorLabel;

    /**
     * Changed fields as `{field: {old, new}}`. `#[Sensitive]` values are stored
     * as encryption envelopes.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $changes;

    /**
     * Circumstances: ip, user-agent, route, correlation id, optional reason.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $context;

    #[ORM\Column(name: 'legal_hold', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $legalHold;

    #[ORM\Column(name: 'previous_hash', type: Types::STRING, length: 64)]
    private string $previousHash;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $hash;

    #[ORM\Column(name: 'version_no', type: Types::INTEGER, nullable: true)]
    private ?int $versionNo;

    #[ORM\Column(name: 'snapshot_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $snapshotHash;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signature;

    #[ORM\Column(name: 'signer_ref', type: Types::STRING, length: 255, nullable: true)]
    private ?string $signerRef;

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $id,
        string $streamId,
        int $sequenceNo,
        string $occurredAt,
        AuditAction $action,
        string $entityClass,
        string $entityId,
        ActorType $actorType,
        ?string $actorId,
        ?string $actorLabel,
        array $changes,
        array $context,
        string $previousHash,
        string $hash,
        bool $legalHold = false,
        ?int $versionNo = null,
        ?string $snapshotHash = null,
        ?string $signature = null,
        ?string $signerRef = null,
    ) {
        $this->id = $id;
        $this->streamId = $streamId;
        $this->sequenceNo = $sequenceNo;
        $this->occurredAt = $occurredAt;
        $this->action = $action;
        $this->entityClass = $entityClass;
        $this->entityId = $entityId;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->actorLabel = $actorLabel;
        $this->changes = $changes;
        $this->context = $context;
        $this->previousHash = $previousHash;
        $this->hash = $hash;
        $this->legalHold = $legalHold;
        $this->versionNo = $versionNo;
        $this->snapshotHash = $snapshotHash;
        $this->signature = $signature;
        $this->signerRef = $signerRef;
    }

    /**
     * The exact payload that is canonicalised and hashed for this entry.
     *
     * This is the *single definition* of the hashed content, shared by the
     * write path and verification. It deliberately includes only immutable
     * fields — never {@see $legalHold} or the E3 signature — and pins every
     * value to its precise scalar type (e.g. `sequence_no` as an int, not the
     * string a database driver may return), because the canonical encoder
     * distinguishes `1` from `"1"`.
     *
     * @param array<string, mixed> $row a normalised row with keys matching the
     *                                  schema columns
     *
     * @return array<string, mixed>
     */
    public static function hashablePayload(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'stream_id' => (string) $row['stream_id'],
            'sequence_no' => (int) $row['sequence_no'],
            'occurred_at' => (string) $row['occurred_at'],
            'action' => (string) $row['action'],
            'entity_class' => (string) $row['entity_class'],
            'entity_id' => (string) $row['entity_id'],
            'actor_type' => (string) $row['actor_type'],
            'actor_id' => null === $row['actor_id'] ? null : (string) $row['actor_id'],
            'actor_label' => null === $row['actor_label'] ? null : (string) $row['actor_label'],
            'changes' => $row['changes'],
            'context' => $row['context'],
            'version_no' => null === $row['version_no'] ? null : (int) $row['version_no'],
            'snapshot_hash' => null === $row['snapshot_hash'] ? null : (string) $row['snapshot_hash'],
        ];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStreamId(): string
    {
        return $this->streamId;
    }

    public function getSequenceNo(): int
    {
        return $this->sequenceNo;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->occurredAt);
    }

    public function getOccurredAtString(): string
    {
        return $this->occurredAt;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getEntityId(): string
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

    /**
     * @return array<string, mixed>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    public function isLegalHold(): bool
    {
        return $this->legalHold;
    }

    public function getPreviousHash(): string
    {
        return $this->previousHash;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getVersionNo(): ?int
    {
        return $this->versionNo;
    }

    public function getSnapshotHash(): ?string
    {
        return $this->snapshotHash;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function getSignerRef(): ?string
    {
        return $this->signerRef;
    }
}
