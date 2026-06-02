<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Metadata;

use Opus125\AuditBundle\Attribute\Auditable;
use Opus125\AuditBundle\Attribute\AuditableWorkflow;
use Opus125\AuditBundle\Attribute\AuditIgnore;
use Opus125\AuditBundle\Attribute\Retention;
use Opus125\AuditBundle\Attribute\Sensitive;

/**
 * Reads the audit attributes off an entity class, with a small per-class cache.
 *
 * Deliberately not a metadata object graph — just direct attribute lookups so
 * the configuration lives entirely on the entity.
 */
final class AuditAttributeReader
{
    /**
     * @var array<class-string, array{auditable: bool, stream: string, retention: string|null, ignored: array<string, true>, sensitive: array<string, true>, workflowAudited: bool, workflowMarking: string|null, workflowTransitions: array<string, true>}>
     */
    private array $cache = [];

    /**
     * @param class-string $class
     */
    public function isAuditable(string $class): bool
    {
        return $this->read($class)['auditable'];
    }

    /**
     * @param class-string $class
     */
    public function stream(string $class): string
    {
        return $this->read($class)['stream'];
    }

    /**
     * @param class-string $class
     */
    public function retention(string $class): ?string
    {
        return $this->read($class)['retention'];
    }

    /**
     * @param class-string $class
     */
    public function isIgnored(string $class, string $field): bool
    {
        return isset($this->read($class)['ignored'][$field]);
    }

    /**
     * @param class-string $class
     */
    public function isSensitive(string $class, string $field): bool
    {
        return isset($this->read($class)['sensitive'][$field]);
    }

    /**
     * Whether the class opts its Symfony Workflow transitions into the trail.
     *
     * @param class-string $class
     */
    public function isWorkflowAudited(string $class): bool
    {
        return $this->read($class)['workflowAudited'];
    }

    /**
     * Whether a specific transition should be audited. An empty allow-list on
     * the attribute audits every applied transition.
     *
     * @param class-string $class
     */
    public function isTransitionAudited(string $class, string $transition): bool
    {
        $meta = $this->read($class);

        if (!$meta['workflowAudited']) {
            return false;
        }

        return [] === $meta['workflowTransitions'] || isset($meta['workflowTransitions'][$transition]);
    }

    /**
     * The entity property holding the workflow marking, if declared, so the
     * recorder can keep it from being logged twice.
     *
     * @param class-string $class
     */
    public function workflowMarking(string $class): ?string
    {
        return $this->read($class)['workflowMarking'];
    }

    /**
     * @param class-string $class
     *
     * @return array{auditable: bool, stream: string, retention: string|null, ignored: array<string, true>, sensitive: array<string, true>, workflowAudited: bool, workflowMarking: string|null, workflowTransitions: array<string, true>}
     */
    private function read(string $class): array
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        $reflection = new \ReflectionClass($class);
        $auditable = $this->classAttribute($reflection, Auditable::class);
        $retention = $this->classAttribute($reflection, Retention::class);
        $workflow = $this->classAttribute($reflection, AuditableWorkflow::class);

        $ignored = [];
        $sensitive = [];
        foreach ($this->properties($reflection) as $property) {
            if ([] !== $property->getAttributes(AuditIgnore::class)) {
                $ignored[$property->getName()] = true;
            }
            if ([] !== $property->getAttributes(Sensitive::class)) {
                $sensitive[$property->getName()] = true;
            }
        }

        return $this->cache[$class] = [
            'auditable' => $auditable instanceof Auditable,
            'stream' => ($auditable instanceof Auditable ? $auditable->stream : null) ?? $class,
            'retention' => $retention instanceof Retention ? $retention->duration : null,
            'ignored' => $ignored,
            'sensitive' => $sensitive,
            'workflowAudited' => $workflow instanceof AuditableWorkflow,
            'workflowMarking' => $workflow instanceof AuditableWorkflow ? $workflow->marking : null,
            'workflowTransitions' => $workflow instanceof AuditableWorkflow ? array_fill_keys($workflow->transitions, true) : [],
        ];
    }

    /**
     * @template T of object
     *
     * @param \ReflectionClass<object> $reflection
     * @param class-string<T>          $attribute
     *
     * @return T|null
     */
    private function classAttribute(\ReflectionClass $reflection, string $attribute): ?object
    {
        for ($current = $reflection; false !== $current; $current = $current->getParentClass()) {
            $attributes = $current->getAttributes($attribute);
            if ([] !== $attributes) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }

    /**
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
