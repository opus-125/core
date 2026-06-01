<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Actor;

/**
 * Ambient, request-scoped audit context.
 *
 * Two responsibilities:
 *
 *  - **Actor override (`runAs`).** Where there is no security token — CLI
 *    commands, Messenger handlers, scheduled jobs — the responsible actor must
 *    be stated explicitly. {@see runAs()} pushes an actor for the duration of a
 *    callable; nested runs stack and unwind correctly even on exceptions.
 *  - **Context bag.** Free-form circumstances (a correlation id, a `reason`,
 *    arbitrary annotations) that should be attached to every entry recorded
 *    while they are set.
 */
final class AuditContext
{
    /**
     * @var list<Actor>
     */
    private array $actorStack = [];

    /**
     * @var array<string, mixed>
     */
    private array $bag = [];

    /**
     * Run $callback with $actor set as the current actor, then restore the
     * previous actor (even if $callback throws).
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function runAs(Actor $actor, callable $callback): mixed
    {
        $this->actorStack[] = $actor;

        try {
            return $callback();
        } finally {
            array_pop($this->actorStack);
        }
    }

    /**
     * Push an actor without a callable (for long-running workers that resolve
     * the actor per message). Pair with {@see popActor()}.
     */
    public function pushActor(Actor $actor): void
    {
        $this->actorStack[] = $actor;
    }

    public function popActor(): void
    {
        array_pop($this->actorStack);
    }

    public function currentActor(): ?Actor
    {
        return $this->actorStack[array_key_last($this->actorStack)] ?? null;
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
        $this->actorStack = [];
        $this->bag = [];
    }
}
