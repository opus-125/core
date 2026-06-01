<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Model;

/**
 * Single source of truth for the physical table and column names of the audit
 * schema.
 *
 * Audit rows are written through DBAL (not the ORM) so that the INSERT happens
 * inside the wrapping transaction during `onFlush` — see
 * {@see \Opus\AuditBundle\Recording\DoctrineAuditListener}. That write path and
 * the ORM read mappings must agree on names; these constants keep them aligned,
 * and {@see \Opus\AuditBundle\Tests\Model\SchemaMappingTest} asserts the ORM
 * metadata matches.
 */
final class Schema
{
    public const string ENTRY_TABLE = 'audit_entry';
    public const string EVENT_TABLE = 'audit_event';
    public const string SEAL_TABLE = 'audit_seal';
    public const string KEY_TABLE = 'audit_crypto_key';

    /**
     * Columns of `audit_entry`, in canonical declaration order.
     *
     * @var list<string>
     */
    public const array ENTRY_COLUMNS = [
        'id',
        'stream_id',
        'sequence_no',
        'occurred_at',
        'action',
        'entity_class',
        'entity_id',
        'actor_type',
        'actor_id',
        'actor_label',
        'changes',
        'context',
        'legal_hold',
        'previous_hash',
        'hash',
        'version_no',
        'snapshot_hash',
        'signature',
        'signer_ref',
    ];

    private function __construct()
    {
    }
}
