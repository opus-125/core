<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Actor;

/**
 * Ambient, request-scoped audit context.
 *
 *  - **Current actor.** Where there is no security token — CLI commands,
 *    Messenger handlers, scheduled jobs — set the responsible actor explicitly.
 *    {@see runAs()} sets it for the duration of a callable and restores the
 *    previous one afterwards (even on exceptions).
 *  - **Context bag.** Free-form circumstances (a correlation id, a `reason`, …)
 *    attached to every entry recorded while they are set.
 */
final class AuditContext
{
    private ?ActorInterface $currentActor = null;

    /**
     * @var array<string, mixed>
     */
    private array $bag = [];

    public function setActor(?ActorInterface $actor): void
    {
        $this->currentActor = $actor;
    }

    public function currentActor(): ?ActorInterface
    {
        return $this->currentActor;
    }

    /**
     * Run $callback with $actor as the current actor, then restore the previous
     * one (even if $callback throws).
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function runAs(ActorInterface $actor, callable $callback): mixed
    {
        $previous = $this->currentActor;
        $this->currentActor = $actor;

        try {
            return $callback();
        } finally {
            $this->currentActor = $previous;
        }
    }

    public function setReason(?string $reason): void
    {
        $this->set('reason', $reason);
    }

    public function setCorrelationId(?string $correlationId): void
    {
        $this->set('correlation_id', $correlationId);
    }

    public function set(string $key, mixed $value): void
    {
        if (null === $value) {
            unset($this->bag[$key]);

            return;
        }

        $this->bag[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->bag;
    }

    public function clear(): void
    {
        $this->currentActor = null;
        $this->bag = [];
    }
}
