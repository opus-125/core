<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Registry;

/**
 * Supplies the entity classes the registry-driven features iterate over — those
 * carrying personal data, a subject link, or being a data subject.
 *
 * The default {@see MappedEntityLocator} derives the list from Doctrine's mapped
 * metadata; the interface keeps the consumers (collection, records of
 * processing) testable without a database.
 */
interface EntityClassLocator
{
    /**
     * @return list<class-string>
     */
    public function relevantClasses(): array;
}
