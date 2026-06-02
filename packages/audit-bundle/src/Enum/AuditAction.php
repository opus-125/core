<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Enum;

/**
 * The kind of change an {@see \Opus125\AuditBundle\Model\AuditEntry} records.
 */
enum AuditAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * A state transition of the Symfony Workflow component, captured
     * automatically for entities marked {@see \Opus125\AuditBundle\Attribute\AuditableWorkflow}.
     * The from/to places live in `changes`, the workflow and transition names in
     * `context`.
     */
    case Transition = 'transition';

    /**
     * A domain event that is not a plain entity mutation (recorded explicitly
     * by application code rather than derived from a Doctrine change set).
     */
    case Custom = 'custom';
}
