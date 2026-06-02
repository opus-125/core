<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Registry;

use Opus125\DataContracts\Attribute\DataSubject;
use Opus125\DataContracts\Attribute\PersonalData;
use Opus125\DataContracts\Attribute\SubjectLink;

/**
 * The Personal-Data-Registry: a declarative map of which entity fields carry
 * personal data, which entities are data subjects, and how the two connect.
 *
 * It reads the contract attributes off a class by reflection (walking the
 * parent chain so inherited properties count), with a small per-class cache.
 * Deliberately not a metadata object graph — direct attribute lookups keep the
 * configuration entirely on the entity, like the Audit bundle's reader.
 *
 * Every higher-level feature — access, portability, erasure, records of
 * processing — is an application *over* this map, so they can never drift out of
 * sync with the code the way a hand-maintained inventory would.
 */
final class PersonalDataRegistry
{
    /**
     * @var array<class-string, EntityClassMetadata>
     */
    private array $cache = [];

    /**
     * @param class-string $class
     */
    public function metadataFor(string $class): EntityClassMetadata
    {
        return $this->cache[$class] ??= $this->read($class);
    }

    /**
     * @param class-string $class
     */
    public function isDataSubject(string $class): bool
    {
        return $this->metadataFor($class)->isDataSubject;
    }

    /**
     * The personal-data fields declared on a class, keyed by property name.
     *
     * @param class-string $class
     *
     * @return array<string, FieldMetadata>
     */
    public function personalData(string $class): array
    {
        return $this->metadataFor($class)->personalData;
    }

    /**
     * @param class-string $class
     *
     * @return list<SubjectLinkMetadata>
     */
    public function links(string $class): array
    {
        return $this->metadataFor($class)->links;
    }

    /**
     * @param class-string $class
     */
    private function read(string $class): EntityClassMetadata
    {
        $reflection = new \ReflectionClass($class);

        $isSubject = false;
        for ($current = $reflection; false !== $current; $current = $current->getParentClass()) {
            if ([] !== $current->getAttributes(DataSubject::class)) {
                $isSubject = true;
                break;
            }
        }

        $personalData = [];
        $links = [];
        foreach ($this->properties($reflection) as $property) {
            $name = $property->getName();

            $personalAttributes = $property->getAttributes(PersonalData::class);
            if ([] !== $personalAttributes) {
                $attr = $personalAttributes[0]->newInstance();
                $personalData[$name] = new FieldMetadata(
                    $name,
                    $attr->category,
                    $attr->purpose,
                    $attr->basis,
                    $attr->sensitive,
                    $attr->erasureStrategy(),
                );
            }

            $linkAttributes = $property->getAttributes(SubjectLink::class);
            if ([] !== $linkAttributes) {
                $link = $linkAttributes[0]->newInstance();
                $links[] = new SubjectLinkMetadata($name, $link->target);
            }
        }

        return new EntityClassMetadata($class, $isSubject, $personalData, $links);
    }

    /**
     * Distinct declared properties from the class up its parent chain (a child
     * override shadows the parent's declaration).
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<\ReflectionProperty>
     */
    private function properties(\ReflectionClass $reflection): array
    {
        $seen = [];
        $properties = [];
        for ($current = $reflection; false !== $current; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if (!isset($seen[$property->getName()])) {
                    $seen[$property->getName()] = true;
                    $properties[] = $property;
                }
            }
        }

        return $properties;
    }
}
