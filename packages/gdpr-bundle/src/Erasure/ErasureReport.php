<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Erasure;

use Opus125\DataContracts\Subject\SubjectReference;

/**
 * The outcome of an erasure request: what was nullified/pseudonymized, what was
 * left as shared, whether the subject's key was crypto-shredded, and — if the
 * whole request was refused — the legal-hold reason.
 *
 * An erasure that is itself an auditable event: the report is the evidence.
 */
final readonly class ErasureReport
{
    /**
     * @param list<FieldErasure> $fields
     */
    public function __construct(
        public SubjectReference $subject,
        public bool $blocked,
        public ?string $blockReason,
        public bool $keyShredded,
        public array $fields,
    ) {
    }

    public static function blocked(SubjectReference $subject, ?string $reason): self
    {
        return new self($subject, true, $reason, false, []);
    }

    /**
     * @param list<FieldErasure> $fields
     */
    public static function completed(SubjectReference $subject, bool $keyShredded, array $fields): self
    {
        return new self($subject, false, null, $keyShredded, $fields);
    }

    /**
     * @return list<FieldErasure>
     */
    public function withOutcome(ErasureOutcome $outcome): array
    {
        return array_values(array_filter($this->fields, static fn (FieldErasure $f): bool => $f->outcome === $outcome));
    }
}
