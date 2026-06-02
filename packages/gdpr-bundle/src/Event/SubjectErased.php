<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Event;

use Opus125\GdprBundle\Erasure\ErasureReport;

/**
 * Dispatched after a subject's data has been erased (PSR-14).
 *
 * The hook the spec calls for: "every erasure is itself an auditable event". The
 * GDPR bundle does not depend on the Audit bundle, so instead of recording an
 * audit entry directly it emits this event — a project (or a thin Audit-bundle
 * subscriber) listens and records it however it wishes. Not dispatched for a
 * legal-hold-blocked request, whose report carries the reason instead.
 */
final readonly class SubjectErased
{
    public function __construct(
        public ErasureReport $report,
    ) {
    }
}
