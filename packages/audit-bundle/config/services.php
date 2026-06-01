<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Register the bundle's services here, e.g.:
    //
    // $services = $container->services()
    //     ->defaults()
    //         ->autowire()
    //         ->autoconfigure();
    //
    // $services->load('Opus\\AuditBundle\\', '../src/')
    //     ->exclude('../src/{OpusAuditBundle.php}');
};
