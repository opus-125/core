<?php

declare(strict_types=1);

namespace App\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Opus\AuditBundle\Recording\DoctrineAuditListener;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches the audit listener to the (manually built) EntityManager's event
 * manager, once, at the start of a request or console command.
 *
 * Doing it lazily here breaks the natural cycle (the listener needs the
 * EntityManager, the EntityManager would otherwise need the listener) without a
 * proxy. With DoctrineBundle this is unnecessary — the `doctrine.event_listener`
 * tag wires the listener automatically.
 */
final class AuditListenerRegistrar implements EventSubscriberInterface
{
    private bool $registered = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DoctrineAuditListener $listener,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['register', 4096],
            ConsoleEvents::COMMAND => ['register', 4096],
        ];
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->entityManager->getEventManager()->addEventListener([Events::onFlush], $this->listener);
        $this->registered = true;
    }
}
