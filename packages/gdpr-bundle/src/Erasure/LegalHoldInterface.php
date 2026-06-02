<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Erasure;

use Opus125\DataContracts\Subject\SubjectReference;

/**
 * Decides whether a subject's data is under a retention obligation that overrides
 * the right to erasure (GDPR Art. 17 (3) — legal hold).
 *
 * When a subject is held, erasure and crypto-shredding are **refused and the
 * reason recorded** rather than silently skipped. The bundle ships a no-op
 * default ({@see NullLegalHold}); projects implement this against their own
 * retention/case rules and alias it.
 */
interface LegalHoldInterface
{
    public function isHeld(SubjectReference $subject): bool;

    /**
     * A human-readable reason for the hold, surfaced in the erasure report.
     */
    public function reason(SubjectReference $subject): ?string;
}
