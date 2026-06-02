<?php

declare(strict_types=1);

namespace Opus125\GdprBundle;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Registry-driven GDPR tooling for Symfony with Doctrine.
 *
 * Zero configuration: with DoctrineBundle installed the bundle maps its
 * {@see Model\SubjectKey} entity and wires the registry,
 * subject access, erasure and records-of-processing services automatically. The
 * key-wrapping secret comes from `APP_SECRET` by default.
 *
 * Integration with the Audit bundle is **optional and additive**: when that
 * bundle is installed the {@see Integration\Audit\AuditKeyProviderBridge}
 * service is registered, ready to be aliased as the audit key provider so
 * crypto-shredding also covers the audit trail. Neither bundle requires the
 * other.
 */
final class Opus125GdprBundle extends AbstractBundle
{
    protected string $extensionAlias = 'opus125_gdpr';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (class_exists(DoctrineOrmMappingsPass::class)) {
            $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
                ['Opus125\\GdprBundle\\Model'],
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
