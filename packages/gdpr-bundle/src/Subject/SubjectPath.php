<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Subject;

/**
 * A resolved route from an entity class to a data-subject class: the ordered
 * association properties to follow.
 *
 * Used to build the inverse query — "all records of class X belonging to subject
 * S" — by joining along {@see $path} down to the subject.
 */
final readonly class SubjectPath
{
    /**
     * @param class-string $subjectClass the `#[DataSubject]` class reached
     * @param list<string> $path         association properties from the source
     *                                   class down to the subject (never empty
     *                                   for a linked class; empty when the source
     *                                   *is* the subject)
     */
    public function __construct(
        public string $subjectClass,
        public array $path,
    ) {
    }
}
