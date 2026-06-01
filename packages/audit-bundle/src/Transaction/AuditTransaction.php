<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Transaction;

use Doctrine\DBAL\Connection;

/**
 * Runs a unit of work inside a database transaction so audit entries commit
 * atomically with the business changes that produced them.
 *
 * The bundle records audit rows during `onFlush`, which fires *before* Doctrine
 * opens its own transaction; only an enclosing transaction guarantees the audit
 * INSERTs and the business writes share a single commit boundary (and keeps the
 * per-stream advisory lock held across the sequence read and the insert). Wrap
 * every mutation of an audited entity:
 *
 * ```php
 * $auditTransaction->run(function () use ($em, $invoice): void {
 *     $invoice->setStatus('open');
 *     $em->flush();
 * });
 * ```
 *
 * Nested calls collapse onto the outermost transaction, so composing audited
 * operations is safe.
 */
final class AuditTransaction
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param callable():mixed $operation
     */
    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (Connection $connection): mixed => $operation());
    }
}
