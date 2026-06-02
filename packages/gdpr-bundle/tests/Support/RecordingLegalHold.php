<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Support;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Erasure\LegalHoldInterface;

/**
 * Test legal hold: holds a fixed set of subjects with a canned reason.
 */
final class RecordingLegalHold implements LegalHoldInterface
{
    /**
     * @var array<string, true>
     */
    private array $held = [];

    public function __construct(
        private readonly string $reason = 'statutory retention',
    ) {
    }

    public function hold(SubjectReference $subject): void
    {
        $this->held[(string) $subject] = true;
    }

    public function isHeld(SubjectReference $subject): bool
    {
        return isset($this->held[(string) $subject]);
    }

    public function reason(SubjectReference $subject): ?string
    {
        return $this->isHeld($subject) ? $this->reason : null;
    }
}
