<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Workflow;

/**
 * A workflow transition that has been applied to a subject but whose audit
 * entry is deferred until the subject's marking change is flushed, so the entry
 * lands in the same transaction.
 *
 * Carries only scalars and the subject object — no Symfony Workflow types — so
 * the parts of the bundle that consume it never need the Workflow component.
 */
final readonly class PendingTransition
{
    /**
     * @param list<string> $froms the marking places left
     * @param list<string> $tos   the marking places entered
     */
    public function __construct(
        public object $subject,
        public string $workflow,
        public string $transition,
        public array $froms,
        public array $tos,
    ) {
    }
}
