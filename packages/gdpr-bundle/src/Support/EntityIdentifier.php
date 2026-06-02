<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Support;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Renders a managed entity's identifier as a stable string — single-field ids as
 * their scalar value, composite keys as a key-sorted JSON object — so a subject
 * is named identically however it was loaded.
 *
 * Mirrors the Audit bundle's identifier rendering on purpose: a subject's string
 * id must match across both bundles for the crypto-shred bridge to line up.
 */
final class EntityIdentifier
{
    private function __construct()
    {
    }

    public static function of(EntityManagerInterface $em, object $entity): string
    {
        $class = $em->getClassMetadata($entity::class)->getName();
        $ids = $em->getClassMetadata($class)->getIdentifierValues($entity);

        if (1 === \count($ids)) {
            return self::scalar($em, reset($ids));
        }

        ksort($ids);

        return (string) json_encode(
            array_map(static fn (mixed $v): string => self::scalar($em, $v), $ids),
            \JSON_THROW_ON_ERROR,
        );
    }

    public static function scalar(EntityManagerInterface $em, mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (\is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        if (\is_object($value)) {
            return self::of($em, $value);
        }

        throw new \InvalidArgumentException('Cannot derive an identifier string from the given value.');
    }
}
