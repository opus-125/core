<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Actor;

/**
 * Determines the current {@see Actor} for an audited change.
 *
 * A BC-critical seam (§5). The default resolves the actor from the security
 * token (HTTP), honours {@see AuditContext} overrides (CLI/Messenger via
 * `runAs`), recognises impersonation, and falls back to a system actor — never
 * a silent null.
 */
interface ActorResolverInterface
{
    public function resolve(): ActorInterface;
}
