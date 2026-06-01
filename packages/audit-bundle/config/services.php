<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Opus\AuditBundle\Actor\ActorResolverInterface;
use Opus\AuditBundle\Actor\AuditContext;
use Opus\AuditBundle\Actor\SecurityActorResolver;
use Opus\AuditBundle\Command\PurgeCommand;
use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Crypto\DerivedSubjectKeyProvider;
use Opus\AuditBundle\Crypto\SubjectKeyProviderInterface;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\NullStreamLock;
use Opus\AuditBundle\Integrity\StreamLockInterface;
use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use Opus\AuditBundle\Metadata\FieldSanitizer;
use Opus\AuditBundle\Recording\ActorContextEncryptor;
use Opus\AuditBundle\Recording\AuditContextProvider;
use Opus\AuditBundle\Recording\AuditRecorder;
use Opus\AuditBundle\Recording\ChangeSetNormalizer;
use Opus\AuditBundle\Recording\DoctrineAuditListener;
use Opus\AuditBundle\Retention\AttributeRetentionPolicy;
use Opus\AuditBundle\Retention\Purger;
use Opus\AuditBundle\Retention\RetentionPolicyInterface;
use Opus\AuditBundle\Serializer\AuditEntryNormalizer;
use Opus\AuditBundle\Subject\AttributeSubjectResolver;
use Opus\AuditBundle\Subject\SubjectResolverInterface;
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

    // --- Per-stream lock (no-op by default; DB-agnostic) -------------------
    $services->set(NullStreamLock::class);
    $services->alias(StreamLockInterface::class, NullStreamLock::class);

    // --- Crypto-shredding --------------------------------------------------
    $services->set(DerivedSubjectKeyProvider::class)
        ->args([
            '%kernel.secret%',
            service(EntityManagerInterface::class),
            service(ClockInterface::class),
        ]);
    $services->alias(SubjectKeyProviderInterface::class, DerivedSubjectKeyProvider::class);
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

    $services->set(AuditRecorder::class)
        ->arg('$entryClass', '%opus_audit.entry_class%');

    $services->set(DoctrineAuditListener::class)
        ->tag('doctrine.event_listener', ['event' => 'onFlush']);

    // --- Read / export -----------------------------------------------------
    $services->set(AuditEntryNormalizer::class);

    // --- Retention ---------------------------------------------------------
    $services->set(AttributeRetentionPolicy::class)
        ->args([service(AuditMetadataFactory::class), '%opus_audit.retention.default%']);
    $services->alias(RetentionPolicyInterface::class, AttributeRetentionPolicy::class);
    $services->set(Purger::class)
        ->arg('$entryClass', '%opus_audit.entry_class%');

    // --- Console -----------------------------------------------------------
    $services->set(PurgeCommand::class);
};
