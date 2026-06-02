<?php

declare(strict_types=1);

use Opus125\AuditBundle\Actor\ActorResolverInterface;
use Opus125\AuditBundle\Actor\AuditContext;
use Opus125\AuditBundle\Actor\SecurityActorResolver;
use Opus125\AuditBundle\Command\PurgeCommand;
use Opus125\AuditBundle\Crypto\AppSecretSubjectKeyProvider;
use Opus125\AuditBundle\Crypto\Cipher;
use Opus125\AuditBundle\Crypto\SensitiveValueCipher;
use Opus125\AuditBundle\Crypto\SubjectKeyProviderInterface;
use Opus125\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus125\AuditBundle\Integrity\HashCalculator;
use Opus125\AuditBundle\Metadata\AuditAttributeReader;
use Opus125\AuditBundle\Metadata\FieldSanitizer;
use Opus125\AuditBundle\Recording\AuditContextProvider;
use Opus125\AuditBundle\Recording\AuditRecorder;
use Opus125\AuditBundle\Recording\ChangeSetNormalizer;
use Opus125\AuditBundle\Recording\DoctrineAuditListener;
use Opus125\AuditBundle\Retention\AttributeRetentionPolicy;
use Opus125\AuditBundle\Retention\Purger;
use Opus125\AuditBundle\Retention\RetentionPolicyInterface;
use Opus125\AuditBundle\Serializer\AuditEntryNormalizer;
use Opus125\AuditBundle\Workflow\WorkflowAuditSubscriber;
use Opus125\AuditBundle\Workflow\WorkflowTransitionBuffer;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Workflow\WorkflowEvents;

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
        ->arg('$workflowBuffer', service(WorkflowTransitionBuffer::class)->nullOnInvalid())
        ->tag('doctrine.event_listener', ['event' => 'onFlush']);

    // Workflow transition auditing — opt-in per entity via #[AuditableWorkflow].
    // The subscriber only loads when the Symfony Workflow component is present,
    // so the bundle never requires it.
    $services->set(WorkflowTransitionBuffer::class);
    if (class_exists(WorkflowEvents::class)) {
        $services->set(WorkflowAuditSubscriber::class);
    }

    // Read / export.
    $services->set(AuditEntryNormalizer::class);

    // Retention.
    $services->set(AttributeRetentionPolicy::class);
    $services->alias(RetentionPolicyInterface::class, AttributeRetentionPolicy::class);
    $services->set(Purger::class);

    // Console.
    $services->set(PurgeCommand::class);
};
