<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Model\AuditEvent;
use Opus\AuditBundle\Model\AuditSeal;
use Opus\AuditBundle\Model\CryptoKey;
use Opus\AuditBundle\Model\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real PostgreSQL-backed EntityManager.
 *
 * The schema (audit tables + test fixtures) is built once per test class; each
 * test starts from truncated tables. If PostgreSQL is unreachable the whole
 * class is skipped rather than failing, so the pure-unit suite still runs in
 * environments without a database.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static EntityManagerInterface $em;
    protected static Connection $connection;

    /**
     * Fixture entity classes the concrete test needs in the schema, in addition
     * to the audit tables.
     *
     * @return list<class-string>
     */
    protected static function fixtureEntities(): array
    {
        return [];
    }

    public static function setUpBeforeClass(): void
    {
        try {
            self::$em = OrmFactory::createEntityManager(
                extraMappingPaths: [\dirname(__DIR__).'/Fixtures'],
            );
            self::$connection = self::$em->getConnection();
            self::$connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL is not available: '.$e->getMessage());
        }

        OrmFactory::resetSchema(self::$em, self::allEntities());
    }

    protected function setUp(): void
    {
        $tables = [Schema::ENTRY_TABLE, Schema::EVENT_TABLE, Schema::SEAL_TABLE, Schema::KEY_TABLE];

        foreach (self::fixtureEntities() as $class) {
            $tables[] = self::$em->getClassMetadata($class)->getTableName();
        }

        self::$connection->executeStatement(
            'TRUNCATE '.implode(', ', array_map(
                self::$connection->getDatabasePlatform()->quoteSingleIdentifier(...),
                array_unique($tables),
            )).' RESTART IDENTITY CASCADE',
        );

        self::$em->clear();
    }

    /**
     * @return list<class-string>
     */
    private static function allEntities(): array
    {
        return [
            AuditEntry::class,
            AuditEvent::class,
            AuditSeal::class,
            CryptoKey::class,
            ...static::fixtureEntities(),
        ];
    }
}
