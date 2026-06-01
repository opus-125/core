<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use Opus\AuditBundle\Repository\AuditEntryRepository;

/**
 * Default audit entry entity.
 *
 * Override it by mapping your own entity that extends {@see AbstractAuditEntry}
 * and setting `opus_audit.entry_class`.
 */
#[ORM\Entity(repositoryClass: AuditEntryRepository::class)]
#[ORM\Table(name: 'audit_entry')]
#[ORM\UniqueConstraint(name: 'uniq_audit_entry_stream_seq', columns: ['stream', 'sequence_no'])]
#[ORM\Index(name: 'idx_audit_entry_target', columns: ['entity_class', 'entity_id'])]
#[ORM\Index(name: 'idx_audit_entry_actor', columns: ['actor_id'])]
class AuditEntry extends AbstractAuditEntry
{
}
