<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Actor;

use Opus\AuditBundle\Enum\ActorType;

/**
 * Something that can be recorded as the responsible party of an audited change.
 *
 * Implement this on your own `User` entity to use it directly as the audit
 * actor, or use the lightweight {@see Actor} value object. There is always an
 * actor — the resolver falls back to a system actor rather than null.
 */
interface ActorInterface
{
    /**
     * Stable identifier of the actor (e.g. the user identifier). Null for the
     * system/anonymous actor.
     */
    public function getAuditActorId(): ?string;

    /**
     * Human-readable label, denormalised onto the entry (personal data — it is
     * encrypted at rest when the actor is a data subject).
     */
    public function getAuditActorLabel(): ?string;

    public function getAuditActorType(): ActorType;
}
