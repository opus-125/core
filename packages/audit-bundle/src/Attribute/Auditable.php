<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Attribute;

/**
 * Opt an entity class into the audit trail (Spine).
 *
 * Without this attribute an entity is **not** audited — nothing surprising
 * happens by default. Applying it makes every create/update/delete of the
 * entity produce an {@see \Opus\AuditBundle\Model\AuditEntry}.
 *
 * The optional {@see $stream} chooses the chain partition the entries are
 * serialised into. Entries within one stream share a gapless `sequence_no` and
 * are hash-chained together; different streams are independent. The default
 * stream is the fully-qualified entity class name.
 *
 * Stream granularity is a deliberate trade-off (see the "atomicity trilemma"):
 * a coarse stream gives broad tamper-evidence but serialises all writes to it,
 * while a per-aggregate-instance stream keeps hot entities concurrent. Pick the
 * finest granularity that still groups what must be provably ordered together.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Auditable
{
    public function __construct(
        /**
         * Chain partition for this entity's entries. `null` uses the entity
         * class name as the stream id.
         */
        public ?string $stream = null,
    ) {
    }
}
