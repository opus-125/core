<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Retention;

/**
 * Supplies the minimum retention duration for an entity class's audit entries.
 *
 * A BC-critical seam (§5). The default reads `#[Retention(...)]`; adapters can
 * provide tiered or category-based policies. Returning null means "keep forever
 * / no automatic purge", the conservative default — purge must never remove
 * entries for a class with no explicit policy.
 */
interface RetentionPolicyInterface
{
    /**
     * @param class-string $entityClass
     */
    public function retentionFor(string $entityClass): ?\DateInterval;
}
