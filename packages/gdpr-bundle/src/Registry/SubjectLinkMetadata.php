<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Registry;

/**
 * One declared hop towards a data subject, from {@see \Opus125\DataContracts\Attribute\SubjectLink}.
 */
final readonly class SubjectLinkMetadata
{
    /**
     * @param class-string $target the class reached through {@see $property}
     */
    public function __construct(
        public string $property,
        public string $target,
    ) {
    }
}
