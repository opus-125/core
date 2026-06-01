<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\DependencyInjection;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Command\ExportCommand;
use Opus\AuditBundle\Command\PurgeCommand;
use Opus\AuditBundle\Command\SealCommand;
use Opus\AuditBundle\Command\VerifyCommand;
use Opus\AuditBundle\Crypto\KeyStoreInterface;
use Opus\AuditBundle\Integrity\ChainBackendInterface;
use Opus\AuditBundle\Integrity\PostgresHashChain;
use Opus\AuditBundle\OpusAuditBundle;
use Opus\AuditBundle\Recording\AuditRecorder;
use Opus\AuditBundle\Recording\DoctrineAuditListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiles the bundle's container against stub Doctrine services (which a real
 * app supplies via DoctrineBundle) to prove the service graph wires up.
 */
final class OpusAuditBundleWiringTest extends TestCase
{
    private function compile(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        // Doctrine services a host application provides.
        $container->register(Connection::class, Connection::class)->setSynthetic(true)->setPublic(true);
        $container->register(EntityManagerInterface::class, EntityManagerInterface::class)->setSynthetic(true)->setPublic(true);

        $bundle = new OpusAuditBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('opus_audit', ['kek' => base64_encode(str_repeat("\x01", 32))]);

        // Keep services discoverable after compilation for assertions.
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
        self::assertTrue($container->has(KeyStoreInterface::class));
        self::assertTrue($container->has(ChainBackendInterface::class));
    }

    public function testChainBackendAliasResolvesToPostgresImplementation(): void
    {
        $container = $this->compile();

        self::assertSame(
            PostgresHashChain::class,
            (string) $container->getAlias(ChainBackendInterface::class),
        );
    }

    public function testListenerIsTaggedForDoctrine(): void
    {
        $container = $this->compile();

        $tags = $container->getDefinition(DoctrineAuditListener::class)->getTag('doctrine.event_listener');
        self::assertSame([['event' => 'onFlush']], $tags);
    }

    public function testCommandsAreRegisteredAndAutoconfigured(): void
    {
        $container = $this->compile();

        foreach ([VerifyCommand::class, SealCommand::class, PurgeCommand::class, ExportCommand::class] as $command) {
            self::assertTrue($container->has($command), $command);
            self::assertInstanceOf(\ReflectionClass::class, new \ReflectionClass($command));
            self::assertTrue(is_a($command, Command::class, true));
        }
    }

    public function testKekParameterIsSet(): void
    {
        $container = $this->compile();

        self::assertSame(base64_encode(str_repeat("\x01", 32)), $container->getParameter('opus_audit.kek'));
    }
}
