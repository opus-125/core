<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Opus\AuditBundle\Model\AuditEntry;

/**
 * Builds a Doctrine ORM EntityManager by hand.
 *
 * DoctrineBundle does not yet support Symfony 8, so the demo wires the ORM
 * directly. The EntityManager maps both the demo entities and the audit
 * bundle's model classes. With DoctrineBundle this whole class disappears —
 * Doctrine is configured in YAML and the audit listener is auto-registered via
 * its `doctrine.event_listener` tag.
 */
final class DoctrineFactory
{
    public static function createEntityManager(string $databaseUrl): EntityManagerInterface
    {
        $auditModelDir = \dirname((string) (new \ReflectionClass(AuditEntry::class))->getFileName());

        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__.'/../Entity', $auditModelDir],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        $params = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql'])->parse($databaseUrl);

        return new EntityManager(DriverManager::getConnection($params), $config);
    }

    public static function createConnection(EntityManagerInterface $entityManager): Connection
    {
        return $entityManager->getConnection();
    }
}
