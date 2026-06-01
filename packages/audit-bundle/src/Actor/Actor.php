<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Actor;

use Opus\AuditBundle\Enum\ActorType;

/**
 * Default {@see ActorInterface} value object.
 *
 * There is always an actor: when none can be determined the resolver returns
 * {@see system()} rather than null, so every audit entry names someone.
 */
final readonly class Actor implements ActorInterface
{
    /**
     * @param array<string, scalar> $attributes extra, non-identifying context (e.g. an impersonation marker)
     */
    public function __construct(
        public ActorType $type,
        public ?string $id = null,
        public ?string $label = null,
        public array $attributes = [],
    ) {
    }

    public static function system(?string $label = null): self
    {
        return new self(ActorType::System, null, $label);
    }

    public static function user(string $id, ?string $label = null): self
    {
        return new self(ActorType::User, $id, $label ?? $id);
    }

    public static function cli(string $id, ?string $label = null): self
    {
        return new self(ActorType::Cli, $id, $label ?? $id);
    }

    public function getAuditActorId(): ?string
    {
        return $this->id;
    }

    public function getAuditActorLabel(): ?string
    {
        return $this->label;
    }

    public function getAuditActorType(): ActorType
    {
        return $this->type;
    }
}
