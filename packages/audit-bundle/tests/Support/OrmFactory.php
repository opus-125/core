<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Model\AuditEntryInterface;

/**
 * Bootstraps a real Doctrine ORM EntityManager against the test PostgreSQL
 * database, without DoctrineBundle.
 *
 * The connection target is taken from the `OPUS_AUDIT_TEST_DSN` environment
 * variable, defaulting to the local cluster used by the test suite. Integration
 * tests that touch advisory locks / gapless sequences require PostgreSQL; the
 * spec targets it explicitly.
 */
final class OrmFactory
{
    private const string DEFAULT_DSN = 'pdo-pgsql://postgres@127.0.0.1:5432/opus_audit_test';

    public static function createConnection(): Connection
    {
        $params = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql'])
            ->parse(getenv('OPUS_AUDIT_TEST_DSN') ?: self::DEFAULT_DSN);

        return DriverManager::getConnection($params);
    }

    /**
     * @param list<string> $extraMappingPaths
     */
    public static function createEntityManager(
        ?Connection $connection = null,
        array $extraMappingPaths = [],
        ?EventManager $eventManager = null,
    ): EntityManagerInterface {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: array_merge([\dirname(__DIR__, 2).'/src/Model'], $extraMappingPaths),
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        $connection ??= self::createConnection();

        // Resolve the audit entry interface to the default entity, like
        // DoctrineBundle's resolve_target_entities does in a real app.
        $eventManager ??= new EventManager();
        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(AuditEntryInterface::class, AuditEntry::class, []);
        $eventManager->addEventSubscriber($resolver);

        return new EntityManager($connection, $config, $eventManager);
    }

    /**
     * Drop and recreate the schema for the given entity classes.
     *
     * @param list<class-string> $entityClasses
     */
    public static function resetSchema(EntityManagerInterface $em, array $entityClasses): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = array_map(
            static fn (string $class) => $em->getClassMetadata($class),
            $entityClasses,
        );

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }
}
