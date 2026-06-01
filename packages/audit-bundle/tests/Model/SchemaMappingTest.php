<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Model;

use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Tests\Support\DatabaseTestCase;

/**
 * Guards the contract between the ORM mapping (read path) and the {@see Schema}
 * constants used by the DBAL write path: a drift here would let the recorder
 * write a column the entity does not map, or vice versa.
 */
final class SchemaMappingTest extends DatabaseTestCase
{
    public function testEntryTableNameMatches(): void
    {
        self::assertSame(Schema::ENTRY_TABLE, self::$em->getClassMetadata(AuditEntry::class)->getTableName());
    }

    public function testEntryColumnsMatchSchemaConstant(): void
    {
        $metadata = self::$em->getClassMetadata(AuditEntry::class);

        $mapped = [];
        foreach ($metadata->getFieldNames() as $field) {
            $mapped[] = $metadata->getColumnName($field);
        }
        foreach ($metadata->getAssociationNames() as $assoc) {
            foreach ($metadata->getAssociationMapping($assoc)->joinColumns ?? [] as $joinColumn) {
                $mapped[] = $joinColumn->name;
            }
        }

        sort($mapped);
        $expected = Schema::ENTRY_COLUMNS;
        sort($expected);

        self::assertSame($expected, $mapped);
    }
}
