<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Workflow;

/**
 * Holds the transitions applied since the last flush.
 *
 * The {@see WorkflowAuditSubscriber} fills it during `workflow.completed` (which
 * fires before the developer flushes); the {@see \Opus125\AuditBundle\Recording\DoctrineAuditListener}
 * drains it inside `onFlush`, recording each transition in the same transaction
 * as the marking change it describes. Draining empties the buffer, so a
 * transition that is never flushed cannot leak into a later, unrelated flush.
 */
final class WorkflowTransitionBuffer
{
    /**
     * @var list<PendingTransition>
     */
    private array $pending = [];

    public function add(PendingTransition $transition): void
    {
        $this->pending[] = $transition;
    }

    /**
     * Return and forget everything buffered so far.
     *
     * @return list<PendingTransition>
     */
    public function drain(): array
    {
        $pending = $this->pending;
        $this->pending = [];

        return $pending;
    }
}
