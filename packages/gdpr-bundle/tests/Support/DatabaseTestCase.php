<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Opus125\GdprBundle\Model\SubjectKey;
use Opus125\GdprBundle\Tests\Fixtures\Contact;
use Opus125\GdprBundle\Tests\Fixtures\CyclicNode;
use Opus125\GdprBundle\Tests\Fixtures\Household;
use Opus125\GdprBundle\Tests\Fixtures\Order;
use Opus125\GdprBundle\Tests\Fixtures\OrderLine;
use Opus125\GdprBundle\Tests\Fixtures\PlainTag;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests needing a real PostgreSQL-backed EntityManager. The
 * schema (SubjectKey + fixtures) is built once per class; each test starts from
 * truncated tables. If PostgreSQL is unreachable the class is skipped, so the
 * pure-unit suite still runs.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static EntityManagerInterface $em;
    protected static Connection $connection;

    /**
     * @return list<class-string>
     */
    protected static function allEntities(): array
    {
        return [SubjectKey::class, Contact::class, Order::class, OrderLine::class, Household::class, CyclicNode::class, PlainTag::class];
    }

    public static function setUpBeforeClass(): void
    {
        try {
            self::$em = OrmFactory::createEntityManager();
            self::$connection = self::$em->getConnection();
            self::$connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL is not available: '.$e->getMessage());
        }

        OrmFactory::resetSchema(self::$em, static::allEntities());
    }

    protected function setUp(): void
    {
        $platform = self::$connection->getDatabasePlatform();
        $tables = [];
        foreach (static::allEntities() as $class) {
            $tables[] = $platform->quoteSingleIdentifier(self::$em->getClassMetadata($class)->getTableName());
        }

        self::$connection->executeStatement('TRUNCATE '.implode(', ', array_unique($tables)).' RESTART IDENTITY CASCADE');
        self::$em->clear();
    }
}
