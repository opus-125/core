<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\AuditBundle\Command\PurgeCommand;
use Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface;
use Opus125\AuditBundle\Opus125AuditBundle;
use Opus125\AuditBundle\Recording\AuditRecorder;
use Opus125\AuditBundle\Recording\DoctrineAuditListener;
use Opus125\AuditBundle\Serializer\AuditEntryNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiles the bundle's container against a stub EntityManager (which a real app
 * supplies via DoctrineBundle) to prove the service graph wires up.
 */
final class Opus125AuditBundleWiringTest extends TestCase
{
    private function compile(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.secret', 'test-secret');
        $container->register(EntityManagerInterface::class, EntityManagerInterface::class)->setSynthetic(true)->setPublic(true);

        $bundle = new Opus125AuditBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('opus125_audit', []);

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

        self::assertTrue($container->has(AuditRecorder::class));
        self::assertTrue($container->has(DoctrineAuditListener::class));
        self::assertTrue($container->has(SubjectKeyProviderInterface::class));
        self::assertTrue($container->has(AuditEntryNormalizer::class));
        self::assertTrue($container->has(PurgeCommand::class));
    }

    public function testListenerIsTaggedForDoctrine(): void
    {
        $tags = $this->compile()->getDefinition(DoctrineAuditListener::class)->getTag('doctrine.event_listener');

        self::assertSame([['event' => 'onFlush']], $tags);
    }
}
