<?php

declare(strict_types=1);

namespace Opus125\AuditBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Opus125\AuditBundle\Model\AuditEntry;
use Opus125\AuditBundle\Model\AuditEntryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Audit logging for Symfony with Doctrine.
 *
 * Zero configuration: with DoctrineBundle installed the bundle registers its
 * entity mapping, resolves {@see AuditEntryInterface} to the default
 * {@see AuditEntry}, and wires the onFlush listener automatically. Override the
 * entity by mapping your own and pointing `resolve_target_entities` at it; the
 * crypto key comes from `APP_SECRET` by default.
 */
final class Opus125AuditBundle extends AbstractBundle
{
    protected string $extensionAlias = 'opus125_audit';

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'resolve_target_entities' => [
                        AuditEntryInterface::class => AuditEntry::class,
                    ],
                ],
            ]);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (class_exists(DoctrineOrmMappingsPass::class)) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
                ['Opus125\\AuditBundle\\Model'],
                [__DIR__.'/Model'],
            ));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(
        array $config,
        ContainerConfigurator $container,
        ContainerBuilder $builder,
    ): void {
        $container->import('../config/services.php');
    }
}
