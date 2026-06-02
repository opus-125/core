<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Registry;

/**
 * The personal-data shape of one entity class: whether it is a data subject,
 * which of its fields are personal data, and how it links onward to a subject.
 */
final readonly class EntityClassMetadata
{
    /**
     * @param class-string                 $class
     * @param array<string, FieldMetadata> $personalData keyed by property name
     * @param list<SubjectLinkMetadata>    $links
     */
    public function __construct(
        public string $class,
        public bool $isDataSubject,
        public array $personalData,
        public array $links,
    ) {
    }

    public function hasPersonalData(): bool
    {
        return [] !== $this->personalData;
    }

    /**
     * Whether the registry knows anything about this class at all — i.e. it
     * carries at least one personal-data field, a subject link, or is a subject.
     */
    public function isRelevant(): bool
    {
        return $this->isDataSubject || [] !== $this->personalData || [] !== $this->links;
    }
}
