<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Erasure;

use Opus125\DataContracts\Subject\SubjectReference;

/**
 * Default {@see LegalHoldInterface}: nothing is ever held. Replace it with a
 * project implementation to enforce statutory retention over erasure.
 */
final class NullLegalHold implements LegalHoldInterface
{
    public function isHeld(SubjectReference $subject): bool
    {
        return false;
    }

    public function reason(SubjectReference $subject): ?string
    {
        return null;
    }
}
