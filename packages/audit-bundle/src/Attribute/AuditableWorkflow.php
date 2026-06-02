<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Attribute;

/**
 * Opt an entity into automatic auditing of its Symfony Workflow transitions.
 *
 * With this attribute, every transition that the workflow component actually
 * *applies* to an instance of the class produces a single audit entry with
 * `action = transition` — recorded through the same {@see \Opus125\AuditBundle\Recording\AuditRecorder}
 * (same stream, same hash-chain) as the entity's other changes, and in the same
 * transaction as the marking change. Guard checks and `can()` probes never reach
 * this point, so the trail only ever shows transitions that truly happened.
 *
 * Activation is opt-in: only entities carrying this attribute (or workflows
 * flagged `audited: true` in their metadata) are tracked, so existing workflows
 * are never logged by surprise.
 *
 * Pair it with {@see Auditable} to fold the transition entries into the same
 * stream as the entity's create/update/delete entries; on its own the entries
 * default to a stream named after the entity class.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AuditableWorkflow
{
    /**
     * @param list<string> $transitions transition names to audit; empty audits
     *                                  every applied transition
     */
    public function __construct(
        /**
         * The entity property that stores the workflow marking (e.g. `status`).
         *
         * When set, that field is dropped from the ordinary field-change entry
         * whenever a transition is recorded for the same flush, so the status
         * change is logged once — as the richer `transition` entry — instead of
         * twice. Leave `null` to fall back to matching the field by its new
         * place value.
         */
        public ?string $marking = null,
        public array $transitions = [],
    ) {
    }
}
