<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Metadata\AuditMetadata;
use Opus\AuditBundle\Metadata\FieldSanitizer;
use Opus\AuditBundle\Support\CanonicalTimestamp;
use Opus\AuditBundle\Support\EntityIdentifier;

/**
 * Turns a Doctrine change set (scalar fields *and* collection diffs) into the
 * audit `changes` map, applying the field-level policy:
 *
 *  - `#[AuditIgnore]` → dropped entirely;
 *  - `#[Sensitive]`   → old/new encrypted per data subject (crypto-shred-able);
 *  - deny-heuristic match → masked with a fixed placeholder;
 *  - otherwise        → normalised to a JSON-able value (associations as their
 *                       identifier, dates/enums to canonical forms).
 *
 * Collection changes are recorded as `{field: {added: [...ids], removed: [...ids]}}`
 * — without them, ManyToMany/OneToMany mutations would silently vanish from the
 * trail (the single most common audit-logging bug).
 */
final class ChangeSetNormalizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CryptoShredder $shredder,
        private readonly FieldSanitizer $sanitizer,
    ) {
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}>                       $fieldChangeSet    field => [old, new]
     * @param array<string, array{added: list<mixed>, removed: list<mixed>}> $collectionChanges field => diff
     * @param list<string>                                                   $subjects          data subjects for encryption
     *
     * @return array<string, mixed>
     */
    public function normalize(
        AuditMetadata $metadata,
        array $fieldChangeSet,
        array $collectionChanges,
        array $subjects,
    ): array {
        $changes = [];

        foreach ($fieldChangeSet as $field => [$old, $new]) {
            if ($metadata->isFieldIgnored($field)) {
                continue;
            }

            if ($metadata->isFieldSensitive($field) && [] !== $subjects) {
                $changes[$field] = [
                    'old' => $this->encrypt($old, $subjects),
                    'new' => $this->encrypt($new, $subjects),
                ];

                continue;
            }

            if ($this->sanitizer->isSensitiveName($field)) {
                $changes[$field] = ['old' => FieldSanitizer::MASK, 'new' => FieldSanitizer::MASK];

                continue;
            }

            $changes[$field] = [
                'old' => $this->normalizeValue($old),
                'new' => $this->normalizeValue($new),
            ];
        }

        foreach ($collectionChanges as $field => $diff) {
            if ($metadata->isFieldIgnored($field)) {
                continue;
            }

            $changes[$field] = [
                'added' => array_map($this->normalizeValue(...), $diff['added']),
                'removed' => array_map($this->normalizeValue(...), $diff['removed']),
            ];
        }

        return $changes;
    }

    /**
     * @param list<string> $subjects
     *
     * @return array<string, mixed>|null
     */
    private function encrypt(mixed $value, array $subjects): ?array
    {
        if (null === $value) {
            return null;
        }

        return $this->shredder->encryptValue($this->normalizeValue($value), $subjects);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return CanonicalTimestamp::format($value);
        }

        if (\is_object($value)) {
            if (!$this->entityManager->getMetadataFactory()->isTransient($value::class)) {
                return EntityIdentifier::of($this->entityManager, $value);
            }

            if ($value instanceof \Stringable) {
                return (string) $value;
            }

            return ['__class' => $value::class];
        }

        // Arrays (e.g. JSON columns) pass through; their contents are scalar.
        return $value;
    }
}
