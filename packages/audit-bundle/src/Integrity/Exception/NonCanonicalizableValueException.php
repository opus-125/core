<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Integrity\Exception;

/**
 * Thrown when a value cannot be turned into a deterministic, canonical form.
 *
 * Canonicalisation feeds the audit hash-chain, so it must never silently coerce
 * an ambiguous or non-deterministic value (a non-finite float, a resource, a
 * closure, …): doing so would produce hashes that depend on hidden state. The
 * encoder fails loudly instead.
 */
final class NonCanonicalizableValueException extends \InvalidArgumentException
{
    public static function forValue(mixed $value, string $path): self
    {
        $type = \is_object($value) ? $value::class : \gettype($value);

        return new self(\sprintf(
            'Value of type "%s" at path "%s" cannot be canonicalised for hashing.',
            $type,
            $path,
        ));
    }

    public static function forNonFiniteFloat(float $value, string $path): self
    {
        $repr = is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');

        return new self(\sprintf(
            'Non-finite float (%s) at path "%s" cannot be canonicalised for hashing.',
            $repr,
            $path,
        ));
    }
}
