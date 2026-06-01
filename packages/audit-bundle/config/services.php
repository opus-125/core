<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Opus\AuditBundle\Actor\ActorResolverInterface;
use Opus\AuditBundle\Actor\AuditContext;
use Opus\AuditBundle\Actor\SecurityActorResolver;
use Opus\AuditBundle\Command\ExportCommand;
use Opus\AuditBundle\Command\PurgeCommand;
use Opus\AuditBundle\Command\SealCommand;
use Opus\AuditBundle\Command\VerifyCommand;
use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Crypto\DoctrineKeyStore;
use Opus\AuditBundle\Crypto\KeyStoreFactory;
use Opus\AuditBundle\Crypto\KeyStoreInterface;
use Opus\AuditBundle\Export\AuditExporter;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\ChainBackendInterface;
use Opus\AuditBundle\Integrity\ChainVerifier;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\PostgresAdvisoryLock;
use Opus\AuditBundle\Integrity\PostgresHashChain;
use Opus\AuditBundle\Integrity\StreamLockInterface;
use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use Opus\AuditBundle\Metadata\FieldSanitizer;
use Opus\AuditBundle\Reading\AuditEntryReader;
use Opus\AuditBundle\Recording\ActorContextEncryptor;
use Opus\AuditBundle\Recording\AuditContextProvider;
use Opus\AuditBundle\Recording\AuditEventRecorder;
use Opus\AuditBundle\Recording\AuditRecorder;
use Opus\AuditBundle\Recording\ChangeSetNormalizer;
use Opus\AuditBundle\Recording\DoctrineAuditListener;
use Opus\AuditBundle\Retention\AttributeRetentionPolicy;
use Opus\AuditBundle\Retention\Purger;
use Opus\AuditBundle\Retention\RetentionPolicyInterface;
use Opus\AuditBundle\Sealing\Sealer;
use Opus\AuditBundle\Subject\AttributeSubjectResolver;
use Opus\AuditBundle\Subject\SubjectResolverInterface;
use Opus\AuditBundle\Transaction\AuditTransaction;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    // --- Clock (PSR) -------------------------------------------------------
    $services->set(NativeClock::class);
    $services->alias(ClockInterface::class, NativeClock::class);

    // --- Stateless primitives ---------------------------------------------
    $services->set(CanonicalJsonEncoder::class);
    $services->set(HashCalculator::class);
    $services->set(Cipher::class);
    $services->set(FieldSanitizer::class);
    $services->set(AuditMetadataFactory::class);
    $services->set(AuditContext::class);

    // --- Integrity backend -------------------------------------------------
    $services->set(PostgresAdvisoryLock::class)
        ->args([service(Connection::class)]);
    $services->alias(StreamLockInterface::class, PostgresAdvisoryLock::class);

    $services->set(PostgresHashChain::class)
        ->args([
            service(Connection::class),
            service(StreamLockInterface::class),
            service(HashCalculator::class),
            service(CanonicalJsonEncoder::class),
        ]);
    $services->alias(ChainBackendInterface::class, PostgresHashChain::class);

    $services->set(ChainVerifier::class)
        ->args([service(ChainBackendInterface::class), service(Connection::class)]);

    // --- Crypto-shredding --------------------------------------------------
    $services->set(DoctrineKeyStore::class)
        ->factory([KeyStoreFactory::class, 'createDoctrine'])
        ->args([
            service(Connection::class),
            service(Cipher::class),
            service(ClockInterface::class),
            '%opus_audit.kek%',
        ]);
    $services->alias(KeyStoreInterface::class, DoctrineKeyStore::class);
    $services->set(CryptoShredder::class);

    // --- Resolvers ---------------------------------------------------------
    $services->set(SecurityActorResolver::class)
        ->args([service(AuditContext::class), service('security.token_storage')->nullOnInvalid()]);
    $services->alias(ActorResolverInterface::class, SecurityActorResolver::class);

    $services->set(AttributeSubjectResolver::class);
    $services->alias(SubjectResolverInterface::class, AttributeSubjectResolver::class);

    // --- Recording ---------------------------------------------------------
    $services->set(ChangeSetNormalizer::class);
    $services->set(ActorContextEncryptor::class);
    $services->set(AuditContextProvider::class)
        ->args([service(AuditContext::class), service('request_stack')->nullOnInvalid()]);
    $services->set(AuditRecorder::class);
    $services->set(AuditEventRecorder::class);

    $services->set(DoctrineAuditListener::class)
        ->tag('doctrine.event_listener', ['event' => 'onFlush']);

    // --- Lifecycle & access ------------------------------------------------
    $services->set(AuditTransaction::class)
        ->args([service(Connection::class)]);
    $services->set(AuditEntryReader::class);
    $services->set(Sealer::class);
    $services->set(AttributeRetentionPolicy::class)
        ->args([service(AuditMetadataFactory::class), '%opus_audit.retention.default%']);
    $services->alias(RetentionPolicyInterface::class, AttributeRetentionPolicy::class);
    $services->set(Purger::class);
    $services->set(AuditExporter::class);

    // --- Console commands --------------------------------------------------
    $services->set(VerifyCommand::class);
    $services->set(SealCommand::class);
    $services->set(PurgeCommand::class);
    $services->set(ExportCommand::class);
};
