<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Registry;

use Opus125\DataContracts\Erasure\ErasureStrategy;

/**
 * One personal-data field, as declared by {@see \Opus125\DataContracts\Attribute\PersonalData}.
 *
 * A flat, read-only projection of the attribute onto a named property — the unit
 * the access export, erasure and records-of-processing all iterate over.
 */
final readonly class FieldMetadata
{
    public function __construct(
        public string $property,
        public string $category,
        public ?string $purpose,
        public ?string $basis,
        public bool $sensitive,
        public ?ErasureStrategy $erasure,
    ) {
    }
}
