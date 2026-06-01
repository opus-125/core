<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Integrity;

use Opus\AuditBundle\Integrity\Exception\NonCanonicalizableValueException;
use Opus\AuditBundle\Support\CanonicalTimestamp;

/**
 * Deterministic, canonical JSON serialisation for the audit hash-chain.
 *
 * The audit trail's tamper-evidence rests on a single guarantee: the *same*
 * logical payload always produces the *same* byte string, no matter how it was
 * assembled in memory. `json_encode()` alone does not give that — object key
 * order, float formatting and date representations all leak insertion order and
 * environment state into the bytes. This encoder pins them down:
 *
 *  - **Object keys are sorted** by their raw UTF-8 byte sequence, recursively.
 *  - **Lists keep their order** (a list is semantically ordered; an object is
 *    not). A PHP array is treated as a list iff {@see array_is_list()} is true,
 *    so the empty array `[]` encodes as an empty JSON array.
 *  - **Floats keep an explicit fraction** (`1.0` stays `1.0`, distinct from the
 *    integer `1`) and non-finite floats are rejected.
 *  - **`DateTimeInterface`** is normalised to UTC and rendered as RFC 3339 with
 *    fixed six-digit microseconds (`2026-06-01T10:30:00.123456Z`), so two
 *    instants that are equal but carry different time zones hash identically.
 *  - **`BackedEnum`** collapses to its backing value, a non-backed `UnitEnum`
 *    to its name.
 *  - **`JsonSerializable`** is expanded via its `jsonSerialize()` payload.
 *  - **`Stringable`** is rendered as its string cast (lowest precedence).
 *  - Slashes and multibyte characters are left unescaped so the byte output is
 *    stable across PHP builds.
 *
 * Anything that cannot be represented deterministically — resources, closures,
 * arbitrary objects, non-finite floats — raises
 * {@see NonCanonicalizableValueException} rather than being coerced.
 *
 * The scheme is deliberately self-contained and documented here rather than
 * delegating to RFC 8785 (JCS): the audit chain only needs *internal*
 * determinism, not cross-implementation interop, and a small owned encoder is
 * far easier to keep stable and test than a full JCS dependency. This wire
 * format is BC-critical — changing it invalidates every previously computed
 * hash — and is locked by golden-vector tests.
 */
final class CanonicalJsonEncoder
{
    /**
     * The flags are fixed on purpose; every one of them affects the output
     * bytes and therefore the hash.
     */
    private const int FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Encode a value into its canonical JSON byte string.
     *
     * @throws NonCanonicalizableValueException if the value (or any nested
     *                                          value) has no deterministic
     *                                          canonical representation
     */
    public function encode(mixed $value): string
    {
        return json_encode($this->normalize($value, '$'), self::FLAGS);
    }

    /**
     * Reduce a value to a tree that `json_encode()` serialises deterministically:
     * scalars, `null`, sequential `list<mixed>` arrays (JSON arrays) and
     * `\stdClass` instances with already-sorted properties (JSON objects).
     *
     * @param string $path JSON-path-ish breadcrumb, used only for error messages
     */
    private function normalize(mixed $value, string $path): mixed
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return $value;
        }

        if (\is_float($value)) {
            if (!is_finite($value)) {
                throw NonCanonicalizableValueException::forNonFiniteFloat($value, $path);
            }

            return $value;
        }

        if (\is_array($value)) {
            return $this->normalizeArray($value, $path);
        }

        if (\is_object($value)) {
            return $this->normalizeObject($value, $path);
        }

        // Resources and any other non-encodable type.
        throw NonCanonicalizableValueException::forValue($value, $path);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<mixed>|\stdClass
     */
    private function normalizeArray(array $value, string $path): array|\stdClass
    {
        if (array_is_list($value)) {
            $list = [];
            foreach ($value as $index => $item) {
                $list[] = $this->normalize($item, $path.'['.$index.']');
            }

            return $list;
        }

        return $this->normalizeObjectMap($value, $path);
    }

    private function normalizeObject(object $value, string $path): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return CanonicalTimestamp::format($value);
        }

        if ($value instanceof \BackedEnum) {
            return $this->normalize($value->value, $path);
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalize($value->jsonSerialize(), $path);
        }

        if ($value instanceof \stdClass) {
            return $this->normalizeObjectMap((array) $value, $path);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw NonCanonicalizableValueException::forValue($value, $path);
    }

    /**
     * Build a `\stdClass` whose properties are the normalised entries of an
     * associative map, ordered by their key's raw UTF-8 bytes.
     *
     * Returning a `\stdClass` (rather than a sorted array) guarantees JSON
     * *object* output even for integer-like keys: sorting an array such as
     * `[1 => …, 0 => …]` would otherwise turn it back into a list.
     *
     * @param array<array-key, mixed> $map
     */
    private function normalizeObjectMap(array $map, string $path): \stdClass
    {
        $keys = array_map(strval(...), array_keys($map));
        sort($keys, \SORT_STRING);

        $object = new \stdClass();
        foreach ($keys as $key) {
            // PHP normalises integer-like string keys to int on access, so a
            // string $key always resolves to the original entry in $map.
            $object->{$key} = $this->normalize($map[$key], $path.'.'.$key);
        }

        return $object;
    }
}
