<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\AuditBundle\Crypto\SensitiveValueCipher;
use Opus125\AuditBundle\Metadata\AuditAttributeReader;
use Opus125\AuditBundle\Metadata\FieldSanitizer;
use Opus125\AuditBundle\Support\EntityIdentifier;

/**
 * Turns a Doctrine change set (scalar fields and collection diffs) into the
 * audit `changes` map, applying the per-field policy:
 *
 *  - `#[AuditIgnore]` → dropped;
 *  - `#[Sensitive]`   → old/new encrypted with the entity's subject key (or
 *                       stored already-redacted when no key is available);
 *  - deny-heuristic match → masked;
 *  - otherwise        → normalised to a JSON-able value (associations as their
 *                       identifier, dates/enums to canonical forms).
 *
 * Collection changes are recorded as `{field: {added: [...ids], removed: [...ids]}}`.
 */
final class ChangeSetNormalizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditAttributeReader $reader,
        private readonly FieldSanitizer $sanitizer,
        private readonly SensitiveValueCipher $cipher,
    ) {
    }

    /**
     * @param class-string                                                   $class
     * @param array<string, array{0: mixed, 1: mixed}>                       $fieldChangeSet
     * @param array<string, array{added: list<mixed>, removed: list<mixed>}> $collectionChanges
     * @param string|null                                                    $key               the subject key, or null if erased
     *
     * @return array<string, mixed>
     */
    public function normalize(string $class, array $fieldChangeSet, array $collectionChanges, ?string $key): array
    {
        $changes = [];

        foreach ($fieldChangeSet as $field => [$old, $new]) {
            if ($this->reader->isIgnored($class, $field)) {
                continue;
            }

            if ($this->reader->isSensitive($class, $field)) {
                $changes[$field] = ['old' => $this->encrypt($old, $key), 'new' => $this->encrypt($new, $key)];

                continue;
            }

            if ($this->sanitizer->isSensitiveName($field)) {
                $changes[$field] = ['old' => FieldSanitizer::MASK, 'new' => FieldSanitizer::MASK];

                continue;
            }

            $changes[$field] = ['old' => $this->normalizeValue($old), 'new' => $this->normalizeValue($new)];
        }

        foreach ($collectionChanges as $field => $diff) {
            if ($this->reader->isIgnored($class, $field)) {
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
     * @return array<string, mixed>|null
     */
    private function encrypt(mixed $value, ?string $key): ?array
    {
        if (null === $value) {
            return null;
        }

        if (null === $key) {
            return $this->cipher->redactedEnvelope();
        }

        return $this->cipher->encrypt($this->normalizeValue($value), $key);
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
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(\DateTimeInterface::RFC3339_EXTENDED);
        }

        if (\is_object($value)) {
            if (!$this->entityManager->getMetadataFactory()->isTransient($value::class)) {
                return EntityIdentifier::of($this->entityManager, $value);
            }

            return $value instanceof \Stringable ? (string) $value : ['__class' => $value::class];
        }

        return $value;
    }
}
