<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Workflow;

use Opus125\AuditBundle\Metadata\AuditAttributeReader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\WorkflowEvents;

/**
 * Turns applied Symfony Workflow transitions into audit entries.
 *
 * It listens only to the global `workflow.completed` event, which fires once
 * per *applied* transition — after the marking has been written to the subject
 * and only when the transition actually ran. Guard events (which also fire on
 * rejected transitions and on plain `can()` probes) are deliberately ignored, so
 * the trail records real state changes, never attempts.
 *
 * A transition is audited when its subject's class carries
 * {@see \Opus125\AuditBundle\Attribute\AuditableWorkflow}, or when the workflow
 * itself is flagged `audited: true` in its metadata. Matching transitions are
 * buffered; the {@see \Opus125\AuditBundle\Recording\DoctrineAuditListener}
 * records them in the same flush — and transaction — as the marking change.
 */
final readonly class WorkflowAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AuditAttributeReader $reader,
        private WorkflowTransitionBuffer $buffer,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [WorkflowEvents::COMPLETED => 'onCompleted'];
    }

    /**
     * @param CompletedEvent<object> $event
     */
    public function onCompleted(CompletedEvent $event): void
    {
        $transition = $event->getTransition();
        if (null === $transition) {
            return;
        }

        $subject = $event->getSubject();
        if (!$this->shouldAudit($subject::class, $transition->getName(), $event)) {
            return;
        }

        $this->buffer->add(new PendingTransition(
            $subject,
            $event->getWorkflowName(),
            $transition->getName(),
            array_values($transition->getFroms()),
            array_values($transition->getTos()),
        ));
    }

    /**
     * @param class-string           $class
     * @param CompletedEvent<object> $event
     */
    private function shouldAudit(string $class, string $transition, CompletedEvent $event): bool
    {
        // Activation via the entity attribute, with its optional transition allow-list.
        if ($this->reader->isWorkflowAudited($class)) {
            return $this->reader->isTransitionAudited($class, $transition);
        }

        // Activation via workflow metadata (`audited: true`), which a single
        // transition may opt out of (`audited: false`).
        if (true === $event->getMetadata('audited', null)) {
            return false !== $event->getMetadata('audited', $event->getTransition());
        }

        return false;
    }
}
