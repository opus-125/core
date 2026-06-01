<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Attribute;

/**
 * Mark a method as a non-mutating, auditable action (Spine).
 *
 * Reads, downloads, exports, access attempts — vendor-relevant events that do
 * not change entity state. These are recorded in the lighter, append-only
 * `audit_event` table rather than the gapless, hash-chained `audit_entry`
 * stream: read traffic is high-volume, and funnelling it through the per-stream
 * head lock would serialise every hot read and destroy read performance.
 *
 * The attribute names the action; recording it is the application's call (via
 * {@see \Opus\AuditBundle\Recording\AuditEventRecorder}) so that an event is
 * logged only *after* the action actually succeeded.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AuditableAction
{
    public function __construct(
        /**
         * The action name stored on the event, e.g. `'download'`, `'export'`.
         */
        public string $name,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Auditable action name must not be empty.');
        }
    }
}
