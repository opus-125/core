<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Subject;

use Opus\AuditBundle\Actor\ActorInterface;

/**
 * Resolves the data subject identifiers whose keys protect a record's sensitive
 * content, for crypto-shredding.
 *
 * A BC-critical seam (§5). Crucially it covers *both* roles in which personal
 * data appears: the entity a record is about ({@see resolveForEntity()}) and the
 * acting person whose identity/label is itself personal data
 * ({@see resolveForActor()}).
 *
 * Subject identifiers are opaque strings; their only requirement is stability —
 * the same person must always map to the same id so that shredding finds every
 * value protected by their key.
 */
interface SubjectResolverInterface
{
    /**
     * @return list<string> subject ids for the entity's `#[Sensitive]` content
     */
    public function resolveForEntity(object $entity): array;

    /**
     * @return list<string> subject ids for the actor (empty for non-personal
     *                      actors such as system/anonymous)
     */
    public function resolveForActor(ActorInterface $actor): array;
}
