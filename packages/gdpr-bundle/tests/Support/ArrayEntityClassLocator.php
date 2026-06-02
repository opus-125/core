<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Support;

use Opus125\GdprBundle\Registry\EntityClassLocator;

/**
 * A fixed list of classes — lets registry-driven features be unit-tested without
 * a database.
 */
final class ArrayEntityClassLocator implements EntityClassLocator
{
    /**
     * @param list<class-string> $classes
     */
    public function __construct(
        private readonly array $classes,
    ) {
    }

    public function relevantClasses(): array
    {
        return $this->classes;
    }
}
