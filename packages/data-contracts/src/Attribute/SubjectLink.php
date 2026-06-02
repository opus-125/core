<?php

declare(strict_types=1);

namespace Opus125\DataContracts\Attribute;

/**
 * Connect personal data living on a *non-subject* entity to the person it
 * concerns — the single hop subject resolution follows.
 *
 * It points at the entity reached through this association, which is either the
 * {@see DataSubject} itself or an intermediate entity that carries its own
 * `#[SubjectLink]` (resolution chains the hops, e.g. `Position → Order →
 * Contact`). The link must be **declared, never guessed**: resolution refuses to
 * infer relationships, because a wrong guess could erase the wrong person's data.
 *
 * The same value may legitimately belong to more than one subject (a shared
 * address). Multiple links — or a link reached from several subjects — surface
 * every subject; erasure then treats genuinely shared data conservatively.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class SubjectLink
{
    /**
     * @param class-string $target the class reached through this association —
     *                             a {@see DataSubject} or an intermediate that
     *                             links onward
     */
    public function __construct(
        public string $target,
    ) {
    }
}
