<?php

declare(strict_types=1);

use Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface as AuditSubjectKeyProvider;
use Opus125\DataContracts\Subject\SubjectResolverInterface;
use Opus125\GdprBundle\Access\Export\CsvExporter;
use Opus125\GdprBundle\Access\Export\JsonExporter;
use Opus125\GdprBundle\Access\SubjectAccessService;
use Opus125\GdprBundle\Command\EraseCommand;
use Opus125\GdprBundle\Command\ExportCommand;
use Opus125\GdprBundle\Command\RopaExportCommand;
use Opus125\GdprBundle\Crypto\Cipher;
use Opus125\GdprBundle\Crypto\DoctrineKeyStore;
use Opus125\GdprBundle\Crypto\KeyStoreInterface;
use Opus125\GdprBundle\Erasure\ErasureService;
use Opus125\GdprBundle\Erasure\LegalHoldInterface;
use Opus125\GdprBundle\Erasure\NullLegalHold;
use Opus125\GdprBundle\Erasure\Pseudonymizer;
use Opus125\GdprBundle\Erasure\ReferenceChecker;
use Opus125\GdprBundle\Integration\Audit\AuditKeyProviderBridge;
use Opus125\GdprBundle\Registry\EntityClassLocator;
use Opus125\GdprBundle\Registry\MappedEntityLocator;
use Opus125\GdprBundle\Registry\PersonalDataRegistry;
use Opus125\GdprBundle\Ropa\RecordsOfProcessingGenerator;
use Opus125\GdprBundle\Subject\SubjectPathResolver;
use Opus125\GdprBundle\Subject\SubjectRecordCollector;
use Opus125\GdprBundle\Subject\SubjectResolver;
use Opus125\GdprBundle\Support\SubjectLoader;
use Opus125\GdprBundle\Support\ValueNormalizer;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    // Clock (PSR). Harmlessly shared with any other Opus125 bundle.
    $services->set(NativeClock::class);
    $services->alias(ClockInterface::class, NativeClock::class);

    // Registry — the declarative personal-data map everything reads from.
    $services->set(PersonalDataRegistry::class);
    $services->set(MappedEntityLocator::class);
    $services->alias(EntityClassLocator::class, MappedEntityLocator::class);
    $services->set(SubjectPathResolver::class);

    // Subject resolution + traversal.
    $services->set(SubjectResolver::class);
    $services->alias(SubjectResolverInterface::class, SubjectResolver::class);
    $services->set(SubjectRecordCollector::class);
    $services->set(SubjectLoader::class);
    $services->set(ValueNormalizer::class);

    // Access & portability (Art. 15 / 20).
    $services->set(SubjectAccessService::class);
    $services->set(JsonExporter::class);
    $services->set(CsvExporter::class);

    // Crypto core: per-subject DEKs wrapped under an APP_SECRET-derived KEK.
    $services->set(Cipher::class);
    $services->set(DoctrineKeyStore::class)
        ->arg('$secret', '%kernel.secret%');
    $services->alias(KeyStoreInterface::class, DoctrineKeyStore::class);

    // Erasure (Art. 17).
    $services->set(Pseudonymizer::class)
        ->arg('$secret', '%kernel.secret%');
    $services->set(ReferenceChecker::class);
    $services->set(NullLegalHold::class);
    $services->alias(LegalHoldInterface::class, NullLegalHold::class);
    $services->set(ErasureService::class)
        ->arg('$eventDispatcher', service(EventDispatcherInterface::class)->nullOnInvalid());

    // Records of processing (Art. 30).
    $services->set(RecordsOfProcessingGenerator::class);

    // Console.
    $services->set(ExportCommand::class);
    $services->set(EraseCommand::class);
    $services->set(RopaExportCommand::class);

    // Optional, additive Audit integration — only when the Audit bundle is
    // present. The bridge is registered ready to be aliased as the audit key
    // provider; we do not override that alias automatically, so adopting GDPR
    // never silently re-keys an existing audit trail. Wire it explicitly:
    //
    //     Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface:
    //         '@Opus125\GdprBundle\Integration\Audit\AuditKeyProviderBridge'
    if (interface_exists(AuditSubjectKeyProvider::class)) {
        $services->set(AuditKeyProviderBridge::class);
    }
};
