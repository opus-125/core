<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Erasure;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Subject\SubjectResolver;

/**
 * Guards genuinely shared data: a value (e.g. an address) reachable from more
 * than one subject must not be erased on behalf of just one of them.
 *
 * Erasing a linked record asks this checker first; if the record resolves to any
 * subject other than the one being erased, it is left untouched (the conservative
 * default — "if in doubt, do not erase"). The subject's *own* record is never
 * shared in this sense and is always erasable.
 */
final class ReferenceChecker
{
    public function __construct(
        private readonly SubjectResolver $resolver,
    ) {
    }

    /**
     * Whether $entity also belongs to a subject other than $erasing — i.e.
     * erasing it would strip data from someone who did not request it.
     */
    public function isSharedBeyond(object $entity, SubjectReference $erasing): bool
    {
        foreach ($this->resolver->resolve($entity) as $owner) {
            if (!$owner->equals($erasing)) {
                return true;
            }
        }

        return false;
    }
}
