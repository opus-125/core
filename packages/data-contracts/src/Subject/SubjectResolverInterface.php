<?php

declare(strict_types=1);

namespace Opus125\DataContracts\Subject;

/**
 * Navigates from an arbitrary record to the data subject(s) it concerns.
 *
 * This is the heart — and the sharpest edge — of the registry: traversing a
 * declared object graph ({@see \Opus125\DataContracts\Attribute\DataSubject} /
 * {@see \Opus125\DataContracts\Attribute\SubjectLink}) from any entity back to
 * the natural person. Implementations must:
 *
 *  - follow only **declared** links, never inferred ones;
 *  - handle **multi-level** chains (`Position → Order → Contact`);
 *  - return **every** subject for genuinely shared data (more than one);
 *  - detect and break **cycles** in the object graph (visited set);
 *  - be **conservative** — when belonging cannot be established, surface nothing
 *    rather than guess, so callers err towards not erasing.
 *
 * Lives in the contracts package so either bundle can depend on the capability
 * without depending on the other's implementation.
 */
interface SubjectResolverInterface
{
    /**
     * Resolve the data subjects an entity belongs to.
     *
     * @return list<SubjectReference> de-duplicated; empty when none can be
     *                                established (including a non-personal entity)
     */
    public function resolve(object $entity): array;
}
