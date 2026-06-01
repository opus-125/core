<?php

declare(strict_types=1);

namespace Opus\AuditBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Audit logging bundle.
 *
 * The bundle is intentionally empty for now — service wiring and configuration
 * are added in {@see self::loadExtension()} / {@see self::configure()}.
 */
final class OpusAuditBundle extends AbstractBundle
{
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
