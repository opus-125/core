<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Bootstraps a real Doctrine ORM EntityManager against the test PostgreSQL
 * database, mapping the GDPR `SubjectKey` entity plus the test fixtures.
 *
 * Reuses the audit suite's DSN variable (`OPUS125_AUDIT_TEST_DSN`) so a single
 * database serves the whole monorepo; the GDPR tables are namespaced
 * (`gdpr_*`).
 */
final class OrmFactory
{
    private const string DEFAULT_DSN = 'pdo-pgsql://postgres@127.0.0.1:5432/opus125_audit_test';

    public static function createConnection(): Connection
    {
        $params = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql'])
            ->parse(getenv('OPUS125_AUDIT_TEST_DSN') ?: self::DEFAULT_DSN);

        return DriverManager::getConnection($params);
    }

    public static function createEntityManager(?Connection $connection = null): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [
                \dirname(__DIR__, 2).'/src/Model',
                \dirname(__DIR__).'/Fixtures',
            ],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        return new EntityManager($connection ?? self::createConnection(), $config);
    }

    /**
     * @param list<class-string> $entityClasses
     */
    public static function resetSchema(EntityManagerInterface $em, array $entityClasses): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = array_map(static fn (string $class) => $em->getClassMetadata($class), $entityClasses);

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }
}
