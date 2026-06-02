<?php

declare(strict_types=1);

use Opus\AuditBundle\Actor\ActorResolverInterface;
use Opus\AuditBundle\Actor\AuditContext;
use Opus\AuditBundle\Actor\SecurityActorResolver;
use Opus\AuditBundle\Command\PurgeCommand;
use Opus\AuditBundle\Crypto\AppSecretSubjectKeyProvider;
use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\SensitiveValueCipher;
use Opus\AuditBundle\Crypto\SubjectKeyProviderInterface;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Metadata\AuditAttributeReader;
use Opus\AuditBundle\Metadata\FieldSanitizer;
use Opus\AuditBundle\Recording\AuditContextProvider;
use Opus\AuditBundle\Recording\AuditRecorder;
use Opus\AuditBundle\Recording\ChangeSetNormalizer;
use Opus\AuditBundle\Recording\DoctrineAuditListener;
use Opus\AuditBundle\Retention\AttributeRetentionPolicy;
use Opus\AuditBundle\Retention\Purger;
use Opus\AuditBundle\Retention\RetentionPolicyInterface;
use Opus\AuditBundle\Serializer\AuditEntryNormalizer;
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

    // Clock (PSR).
    $services->set(NativeClock::class);
    $services->alias(ClockInterface::class, NativeClock::class);

    // Stateless primitives.
    $services->set(CanonicalJsonEncoder::class);
    $services->set(HashCalculator::class);
    $services->set(Cipher::class);
    $services->set(SensitiveValueCipher::class);
    $services->set(FieldSanitizer::class);
    $services->set(AuditAttributeReader::class);
    $services->set(AuditContext::class);

    // Crypto-shredding key provider (default: derived from APP_SECRET).
    $services->set(AppSecretSubjectKeyProvider::class)
        ->args(['%kernel.secret%']);
    $services->alias(SubjectKeyProviderInterface::class, AppSecretSubjectKeyProvider::class);

    // Actor resolution.
    $services->set(SecurityActorResolver::class)
        ->args([service(AuditContext::class), service('security.token_storage')->nullOnInvalid()]);
    $services->alias(ActorResolverInterface::class, SecurityActorResolver::class);

    // Recording.
    $services->set(ChangeSetNormalizer::class);
    $services->set(AuditContextProvider::class)
        ->args([service(AuditContext::class), service('request_stack')->nullOnInvalid()]);
    $services->set(AuditRecorder::class)
        ->arg('$eventDispatcher', service(EventDispatcherInterface::class)->nullOnInvalid());
    $services->set(DoctrineAuditListener::class)
        ->tag('doctrine.event_listener', ['event' => 'onFlush']);

    // Read / export.
    $services->set(AuditEntryNormalizer::class);

    // Retention.
    $services->set(AttributeRetentionPolicy::class);
    $services->alias(RetentionPolicyInterface::class, AttributeRetentionPolicy::class);
    $services->set(Purger::class);

    // Console.
    $services->set(PurgeCommand::class);
};
