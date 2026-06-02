<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use Opus125\AuditBundle\Repository\AuditEntryRepository;

/**
 * Default audit entry entity.
 *
 * To use your own table, map an entity that implements {@see AuditEntryInterface}
 * (most easily `use AuditEntryTrait;`) and point the interface at it with
 * Doctrine `resolve_target_entities` — no bundle configuration needed.
 */
#[ORM\Entity(repositoryClass: AuditEntryRepository::class)]
#[ORM\Table(name: 'audit_entry')]
#[ORM\UniqueConstraint(name: 'uniq_audit_entry_stream_seq', columns: ['stream', 'sequence_no'])]
#[ORM\Index(name: 'idx_audit_entry_target', columns: ['entity_class', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_entry_actor', columns: ['actor_id'])]
class AuditEntry implements AuditEntryInterface
{
    use AuditEntryTrait;
}
