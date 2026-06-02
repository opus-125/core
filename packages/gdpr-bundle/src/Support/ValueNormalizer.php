<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Support;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Reduces a property value to a JSON/CSV-friendly scalar for the access export:
 * scalars pass through, enums collapse to their value/name, dates render as
 * UTC RFC 3339, and related entities become their identifier string.
 */
final class ValueNormalizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function normalize(mixed $value): mixed
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

        if (is_iterable($value)) {
            $list = [];
            foreach ($value as $item) {
                $list[] = $this->normalize($item);
            }

            return $list;
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
