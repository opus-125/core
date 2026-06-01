<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Metadata;

/**
 * Immutable, compiled audit configuration for a single entity class.
 *
 * Produced once per class by {@see AuditMetadataFactory} from the
 * {@see \Opus\AuditBundle\Attribute} declarations and cached. Holding the
 * resolved configuration in one value object keeps the hot recording path free
 * of reflection.
 */
final readonly class AuditMetadata
{
    /**
     * @param class-string       $class           the entity class this describes
     * @param bool               $auditable       whether the class opted in via #[Auditable]
     * @param string             $stream          resolved chain partition (attribute value or class name)
     * @param string|null        $retention       relative retention duration, or null for "no auto-purge"
     * @param array<string,true> $ignoredFields   field names excluded from the log entirely
     * @param array<string,true> $sensitiveFields field names recorded encrypted (crypto-shred-able)
     * @param list<string>       $subjectFields   properties identifying the data subject(s)
     */
    public function __construct(
        public string $class,
        public bool $auditable,
        public string $stream,
        public ?string $retention,
        private array $ignoredFields,
        private array $sensitiveFields,
        private array $subjectFields,
    ) {
    }

    public function isFieldIgnored(string $field): bool
    {
        return isset($this->ignoredFields[$field]);
    }

    /**
     * Whether the field was *declared* `#[Sensitive]`. The heuristic safety net
     * ({@see FieldSanitizer}) is applied separately and masks rather than
     * encrypts.
     */
    public function isFieldSensitive(string $field): bool
    {
        return isset($this->sensitiveFields[$field]);
    }

    /**
     * Properties marked #[DataSubject], in declaration order.
     *
     * @return list<string>
     */
    public function subjectFields(): array
    {
        return $this->subjectFields;
    }
}
