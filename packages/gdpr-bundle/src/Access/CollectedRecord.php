<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Access;

use Opus125\GdprBundle\Registry\FieldMetadata;

/**
 * One entity instance's personal data, as gathered for a subject access request.
 */
final readonly class CollectedRecord
{
    /**
     * @param class-string                                    $entityClass
     * @param list<array{field: FieldMetadata, value: mixed}> $fields
     */
    public function __construct(
        public string $entityClass,
        public string $entityId,
        public array $fields,
    ) {
    }
}
