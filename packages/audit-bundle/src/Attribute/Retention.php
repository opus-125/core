<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Attribute;

/**
 * Declare how long an entity's audit entries must be kept (Spine).
 *
 * The duration is a human-readable relative expression understood by PHP's
 * {@see \DateTimeImmutable} modifier syntax, e.g. `'10 years'`, `'6 months'`,
 * `'90 days'`. It is validated eagerly so a typo fails at boot, not at purge
 * time.
 *
 * Retention models the *minimum* keep duration for statutory archiving
 * (GoBD/BAO): purge must not remove entries younger than this. Without the
 * attribute the policy is conservative — **no automatic purge at all** — because
 * deleting audit history by accident is far worse than keeping too much.
 *
 * Retention bounds *purge* (dropping whole entries past their statutory life);
 * it is orthogonal to crypto-shredding, which erases personal *content* on
 * request while keeping the (non-personal) entry for the archiving period.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Retention
{
    public function __construct(
        /**
         * Relative duration expression, e.g. `'10 years'`.
         */
        public string $duration,
    ) {
        if ('' === trim($duration)) {
            throw new \InvalidArgumentException('Retention duration must not be empty.');
        }

        // Validate that the expression is a well-formed positive interval by
        // applying it to a fixed reference instant.
        $reference = new \DateTimeImmutable('2000-01-01T00:00:00Z');

        try {
            $resolved = $reference->modify($duration);
        } catch (\DateMalformedStringException $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid retention duration "%s": %s', $duration, $e->getMessage()), 0, $e);
        }

        if ($resolved <= $reference) {
            throw new \InvalidArgumentException(\sprintf('Retention duration "%s" must describe a positive, future-directed interval.', $duration));
        }
    }
}
