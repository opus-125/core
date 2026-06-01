<?php

declare(strict_types=1);

namespace Opus\AuditBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Opus\AuditBundle\Model\AuditEntry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The Opus audit bundle.
 *
 * Records create/update/delete of `#[Auditable]` entities (and explicit
 * actions) into a tamper-evident, append-only trail, with optional per-field
 * encryption that supports GDPR crypto-shredding. It works on any Doctrine DBAL
 * platform; configuration is infrastructure only and every value has a safe
 * default.
 */
final class OpusAuditBundle extends AbstractBundle
{
    protected string $extensionAlias = 'opus_audit';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('entry_class')
                    ->info('The audit entry entity. Override with your own class extending AbstractAuditEntry.')
                    ->defaultValue(AuditEntry::class)
                ->end()
                ->arrayNode('retention')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default')
                            ->info('Fallback retention duration (e.g. "10 years") for classes without #[Retention]. null = keep forever.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // When DoctrineBundle is installed, register the audit entity mappings
        // automatically (guarded so a hand-wired EntityManager works too).
        if (class_exists(DoctrineOrmMappingsPass::class)) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
                ['Opus\\AuditBundle\\Model'],
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
        $retention = \is_array($config['retention'] ?? null) ? $config['retention'] : [];

        $builder->setParameter('opus_audit.entry_class', $config['entry_class'] ?? AuditEntry::class);
        $builder->setParameter('opus_audit.retention.default', $retention['default'] ?? null);

        $container->import('../config/services.php');
    }
}
