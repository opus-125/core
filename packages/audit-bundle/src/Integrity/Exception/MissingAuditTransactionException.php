<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity\Exception;

/**
 * Raised when audit rows would be written without an enclosing transaction.
 *
 * Audit entries are inserted during `onFlush`, which Doctrine dispatches
 * *before* it opens its own transaction. Without a surrounding transaction the
 * audit INSERT would auto-commit independently of the business change — breaking
 * atomicity (a phantom audit row could survive a rolled-back change) and
 * defeating the per-stream advisory lock that keeps `sequence_no` gapless.
 *
 * The fix is to wrap the mutation in a transaction, e.g. via the bundle's
 * {@see \Opus\AuditBundle\Transaction\AuditTransaction} helper.
 */
final class MissingAuditTransactionException extends \RuntimeException
{
    public static function create(): self
    {
        return new self(
            'Audited changes must be flushed inside a transaction so the audit '
            .'entries commit atomically with them. Wrap the operation with '
            .'AuditTransaction::run() (or begin a transaction before flushing).',
        );
    }
}
