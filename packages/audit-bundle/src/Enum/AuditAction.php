<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Enum;

/**
 * The kind of change an {@see \Opus\AuditBundle\Model\AuditEntry} records.
 */
enum AuditAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * A domain event that is not a plain entity mutation (recorded explicitly
     * by application code rather than derived from a Doctrine change set).
     */
    case Custom = 'custom';
}
