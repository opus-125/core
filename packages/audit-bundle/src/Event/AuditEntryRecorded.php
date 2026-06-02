<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Event;

use Opus\AuditBundle\Model\AuditEntryInterface;

/**
 * Dispatched after an audit entry has been built and scheduled for insertion in
 * the current flush.
 *
 * Listen to it to extend behaviour without changing the bundle — e.g. mirror
 * entries elsewhere, enrich context, or record your own erasure bookkeeping when
 * a sensitive change is seen. The entry is already part of the unit of work.
 */
final class AuditEntryRecorded
{
    public function __construct(
        public readonly AuditEntryInterface $entry,
    ) {
    }
}
