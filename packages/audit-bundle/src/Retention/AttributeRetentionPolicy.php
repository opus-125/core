<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Retention;

use Opus\AuditBundle\Metadata\AuditMetadataFactory;

/**
 * Default {@see RetentionPolicyInterface}: the duration declared by
 * `#[Retention(...)]` on the entity, falling back to a configured global
 * default, and to "keep forever" when neither is set.
 */
final class AttributeRetentionPolicy implements RetentionPolicyInterface
{
    public function __construct(
        private readonly AuditMetadataFactory $metadataFactory,
        private readonly ?string $defaultDuration = null,
    ) {
    }

    public function retentionFor(string $entityClass): ?\DateInterval
    {
        $duration = $this->metadataFactory->getMetadata($entityClass)->retention ?? $this->defaultDuration;

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
