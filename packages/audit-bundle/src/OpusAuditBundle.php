<?php

declare(strict_types=1);

namespace Opus\AuditBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The Opus audit bundle.
 *
 * Configuration (`opus_audit`) describes infrastructure only — everything
 * behavioural is declared on entities via attributes. All values have safe
 * defaults; an empty configuration is runnable, except that crypto-shredding
 * requires a master key (`kek`).
 */
final class OpusAuditBundle extends AbstractBundle
{
    protected string $extensionAlias = 'opus_audit';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('kek')
                    ->info('Base64 of a 32-byte master key (KEK) wrapping the per-subject DEKs. Required for crypto-shredding.')
                    ->defaultValue('%env(default::OPUS_AUDIT_KEK)%')
                ->end()
                ->enumNode('chain_backend')
                    ->info('Integrity backend. Only the default linear Postgres hash chain ships in v1.')
                    ->values(['postgres'])
                    ->defaultValue('postgres')
                ->end()
                ->enumNode('keystore')
                    ->info('DEK storage. Only the Doctrine-backed keystore ships in v1.')
                    ->values(['doctrine'])
                    ->defaultValue('doctrine')
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
        // automatically so consumers don't have to. Guarded by class_exists so
        // the bundle stays usable with a hand-wired EntityManager too.
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

        $builder->setParameter('opus_audit.kek', $config['kek'] ?? '');
        $builder->setParameter('opus_audit.retention.default', $retention['default'] ?? null);

        $container->import('../config/services.php');
    }
}
