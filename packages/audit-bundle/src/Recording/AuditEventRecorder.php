<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Recording;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Opus\AuditBundle\Actor\ActorResolverInterface;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Subject\SubjectResolverInterface;
use Opus\AuditBundle\Support\CanonicalTimestamp;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Records non-mutating, auditable occurrences (`#[AuditableAction]`: download,
 * export, view, failed access) into the lighter `audit_event` log.
 *
 * Append-only but deliberately *not* hash-chained or gapless-sequenced — read
 * traffic is high-volume and must not contend on the per-stream head lock. Call
 * it explicitly after the action succeeds (or to record a denied attempt).
 * Actor PII is encrypted just like on the chained trail.
 */
final class AuditEventRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ActorResolverInterface $actorResolver,
        private readonly SubjectResolverInterface $subjectResolver,
        private readonly AuditContextProvider $contextProvider,
        private readonly ActorContextEncryptor $actorContextEncryptor,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $extraContext additional, non-sensitive context
     *
     * @return string the new event id
     */
    public function record(
        string $action,
        ?string $entityClass = null,
        ?string $entityId = null,
        bool $succeeded = true,
        array $extraContext = [],
    ): string {
        $actor = $this->actorResolver->resolve();
        $actorSubjects = $this->subjectResolver->resolveForActor($actor);

        [$public, $sensitive] = $this->contextProvider->gather($actor);
        [$context, $label] = $this->actorContextEncryptor->apply(array_merge($public, $extraContext), $sensitive, $actor->label, $actorSubjects);

        $id = Uuid::v7()->toRfc4122();

        $this->connection->insert(Schema::EVENT_TABLE, [
            'id' => $id,
            'occurred_at' => CanonicalTimestamp::format($this->clock->now()),
            'action' => $action,
            'entity_class' => $entityClass,
            'entity_id' => $entityId,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'actor_label' => $label,
            'succeeded' => $succeeded,
            'context' => (string) json_encode($context, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        ], [
            'succeeded' => ParameterType::BOOLEAN,
        ]);

        return $id;
    }
}
