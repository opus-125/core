<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface as AuditSubjectKeyProvider;
use Opus125\GdprBundle\Access\SubjectAccessService;
use Opus125\GdprBundle\Command\EraseCommand;
use Opus125\GdprBundle\Command\ExportCommand;
use Opus125\GdprBundle\Command\RopaExportCommand;
use Opus125\GdprBundle\Crypto\KeyStoreInterface;
use Opus125\GdprBundle\Erasure\ErasureService;
use Opus125\GdprBundle\Integration\Audit\AuditKeyProviderBridge;
use Opus125\GdprBundle\Opus125GdprBundle;
use Opus125\GdprBundle\Subject\SubjectResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiles the bundle's container against a stub EntityManager (which a real app
 * supplies via DoctrineBundle) to prove the service graph wires up.
 */
final class Opus125GdprBundleWiringTest extends TestCase
{
    private function compile(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.secret', 'test-secret');
        $container->register(EntityManagerInterface::class, EntityManagerInterface::class)->setSynthetic(true)->setPublic(true);

        $bundle = new Opus125GdprBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('opus125_gdpr', []);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $definition) {
                    $definition->setPublic(true);
                }
                foreach ($container->getAliases() as $alias) {
                    $alias->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, 100);

        $container->compile();

        return $container;
    }

    public function testCoreServicesAreRegistered(): void
    {
        $container = $this->compile();

        self::assertTrue($container->has(SubjectAccessService::class));
        self::assertTrue($container->has(ErasureService::class));
        self::assertTrue($container->has(KeyStoreInterface::class));
        self::assertTrue($container->has(SubjectResolver::class));
        self::assertTrue($container->has(ExportCommand::class));
        self::assertTrue($container->has(EraseCommand::class));
        self::assertTrue($container->has(RopaExportCommand::class));
    }

    public function testAuditBridgeIsRegisteredWhenAuditBundleIsPresent(): void
    {
        self::assertTrue(interface_exists(AuditSubjectKeyProvider::class), 'audit-bundle is a dev dependency in the monorepo');

        // It is registered, but NOT auto-aliased over the audit provider — adopting
        // GDPR never silently re-keys an existing audit trail.
        self::assertTrue($this->compile()->has(AuditKeyProviderBridge::class));
    }
}
