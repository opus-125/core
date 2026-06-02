<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Retention;

use Opus125\AuditBundle\Metadata\AuditAttributeReader;

/**
 * Default {@see RetentionPolicyInterface}: the duration declared by
 * `#[Retention(...)]` on the entity, and "keep forever" when none is set.
 *
 * Need a global default or tiered rules? Replace this service with your own
 * implementation of {@see RetentionPolicyInterface}.
 */
final class AttributeRetentionPolicy implements RetentionPolicyInterface
{
    public function __construct(
        private readonly AuditAttributeReader $reader,
    ) {
    }

    public function retentionFor(string $entityClass): ?\DateInterval
    {
        $duration = $this->reader->retention($entityClass);

        if (null === $duration) {
            return null;
        }

        try {
            return \DateInterval::createFromDateString($duration);
        } catch (\DateMalformedIntervalStringException $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid retention duration "%s".', $duration), 0, $e);
        }
    }
}
