<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Metadata;

use Opus\AuditBundle\Attribute\Auditable;
use Opus\AuditBundle\Attribute\AuditIgnore;
use Opus\AuditBundle\Attribute\DataSubject;
use Opus\AuditBundle\Attribute\Retention;
use Opus\AuditBundle\Attribute\Sensitive;

/**
 * Builds and caches {@see AuditMetadata} for entity classes by reading the
 * audit attributes via reflection.
 *
 * Reflection runs once per class; results are memoised for the lifetime of the
 * service. Property attributes are collected across the whole class hierarchy
 * (including private properties declared on parent classes), so audit
 * configuration is inherited like the properties it annotates.
 */
final class AuditMetadataFactory
{
    /**
     * @var array<class-string, AuditMetadata>
     */
    private array $cache = [];

    /**
     * @param class-string $class
     */
    public function getMetadata(string $class): AuditMetadata
    {
        return $this->cache[$class] ??= $this->build($class);
    }

    /**
     * @param class-string $class
     */
    public function isAuditable(string $class): bool
    {
        return $this->getMetadata($class)->auditable;
    }

    /**
     * @param class-string $class
     */
    private function build(string $class): AuditMetadata
    {
        $reflection = new \ReflectionClass($class);

        $auditableAttr = $this->firstAttribute($reflection, Auditable::class);
        $retentionAttr = $this->firstAttribute($reflection, Retention::class);

        $stream = ($auditableAttr instanceof Auditable ? $auditableAttr->stream : null) ?? $class;

        $ignored = [];
        $sensitive = [];
        $subjects = [];

        foreach ($this->collectProperties($reflection) as $property) {
            $name = $property->getName();

            if ([] !== $property->getAttributes(AuditIgnore::class)) {
                $ignored[$name] = true;
            }

            if ([] !== $property->getAttributes(Sensitive::class)) {
                $sensitive[$name] = true;
            }

            if ([] !== $property->getAttributes(DataSubject::class)) {
                $subjects[] = $name;
            }
        }

        return new AuditMetadata(
            class: $class,
            auditable: $auditableAttr instanceof Auditable,
            stream: $stream,
            retention: $retentionAttr instanceof Retention ? $retentionAttr->duration : null,
            ignoredFields: $ignored,
            sensitiveFields: $sensitive,
            subjectFields: $subjects,
        );
    }

    /**
     * @template T of object
     *
     * @param \ReflectionClass<object> $reflection
     * @param class-string<T>          $attribute
     *
     * @return T|null
     */
    private function firstAttribute(\ReflectionClass $reflection, string $attribute): ?object
    {
        // Walk up so an #[Auditable]/#[Retention] on a mapped superclass still
        // applies to its children.
        for ($current = $reflection; false !== $current; $current = $current->getParentClass()) {
            $attributes = $current->getAttributes($attribute);

            if ([] !== $attributes) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }

    /**
     * Collect every declared property across the class hierarchy, child first,
     * de-duplicated by name (a child redeclaration wins).
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<\ReflectionProperty>
     */
    private function collectProperties(\ReflectionClass $reflection): array
    {
        $seen = [];
        $properties = [];

        for ($current = $reflection; false !== $current; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                $name = $property->getName();

                if (isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;
                $properties[] = $property;
            }
        }

        return $properties;
    }
}
