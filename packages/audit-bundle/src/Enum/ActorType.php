<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Enum;

/**
 * Who (or what) caused an audited change.
 *
 * The absence of a resolvable actor is never represented as a silent `null`:
 * it resolves to {@see self::System}, so every entry names a responsible party.
 */
enum ActorType: string
{
    case User = 'user';
    case System = 'system';
    case Cli = 'cli';
    case Messenger = 'messenger';
    case Anonymous = 'anonymous';
}
