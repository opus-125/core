<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Erasure;

/**
 * The record of erasing (or deliberately not erasing) one field on one entity.
 */
final readonly class FieldErasure
{
    /**
     * @param class-string $entityClass
     */
    public function __construct(
        public string $entityClass,
        public string $entityId,
        public string $property,
        public ErasureOutcome $outcome,
    ) {
    }
}
