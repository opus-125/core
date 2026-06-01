<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Repository\AuditEventRepository;

/**
 * A non-mutating, auditable occurrence — a read, download, export, view or
 * failed access attempt (Spine).
 *
 * Deliberately *separate* from {@see AuditEntry}: these are append-only but
 * **not** gapless-sequenced and **not** hash-chained. Read-type traffic is
 * high-volume, and funnelling it through the per-stream head lock would
 * serialise every hot read and wreck read performance. Events trade the
 * chain's tamper-evidence for throughput.
 */
#[ORM\Entity(repositoryClass: AuditEventRepository::class, readOnly: true)]
#[ORM\Table(name: Schema::EVENT_TABLE)]
#[ORM\Index(name: 'idx_audit_event_target', columns: ['entity_class', 'entity_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_event_actor', columns: ['actor_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_event_action', columns: ['action', 'occurred_at'])]
class AuditEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'occurred_at', type: Types::STRING, length: 32)]
    private string $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $action;

    #[ORM\Column(name: 'entity_class', type: Types::STRING, length: 255, nullable: true)]
    private ?string $entityClass;

    #[ORM\Column(name: 'entity_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $entityId;

    #[ORM\Column(name: 'actor_type', type: Types::STRING, length: 16, enumType: ActorType::class)]
    private ActorType $actorType;

    #[ORM\Column(name: 'actor_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(name: 'actor_label', type: Types::TEXT, nullable: true)]
    private ?string $actorLabel;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $succeeded;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $id,
        string $occurredAt,
        string $action,
        ?string $entityClass,
        ?string $entityId,
        ActorType $actorType,
        ?string $actorId,
        ?string $actorLabel,
        bool $succeeded,
        array $context,
    ) {
        $this->id = $id;
        $this->occurredAt = $occurredAt;
        $this->action = $action;
        $this->entityClass = $entityClass;
        $this->entityId = $entityId;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->actorLabel = $actorLabel;
        $this->succeeded = $succeeded;
        $this->context = $context;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->occurredAt);
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

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
