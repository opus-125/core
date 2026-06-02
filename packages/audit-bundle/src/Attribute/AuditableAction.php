<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Attribute;

/**
 * Mark a method as a non-mutating, auditable action (a read, download, export,
 * view, …) to be recorded as an audit entry with no field changes.
 *
 * The action name is optional: by default it is derived from the method name
 * with a trailing `Action`/`action` stripped, so `downloadAction()` records
 * `download`. Pass an explicit name to override.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AuditableAction
{
    public function __construct(
        public ?string $name = null,
    ) {
        if (null !== $name && '' === trim($name)) {
            throw new \InvalidArgumentException('Auditable action name, when given, must not be empty.');
        }
    }

    /**
     * Resolve the effective action name for the method it annotates.
     */
    public function resolveName(string $methodName): string
    {
        if (null !== $this->name) {
            return $this->name;
        }

        return preg_replace('/Action$/i', '', $methodName) ?: $methodName;
    }
}
