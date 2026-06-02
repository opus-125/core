<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Opus125\AuditBundle\Actor\ActorResolverInterface;
use Opus125\AuditBundle\Actor\AuditContext;
use Opus125\AuditBundle\Actor\SecurityActorResolver;
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
use Opus125\AuditBundle\Serializer\AuditEntryNormalizer;
use Opus125\AuditBundle\Workflow\WorkflowTransitionBuffer;
use Psr\Clock\ClockInterface;

/**
 * Wires the audit service graph for integration tests — the same wiring the
 * container produces, assembled by hand so tests run without a full kernel.
 */
final class AuditServices
{
    private function __construct(
        public readonly DoctrineAuditListener $listener,
        public readonly AuditRecorder $recorder,
        public readonly SubjectKeyProviderInterface $keyProvider,
        public readonly AuditAttributeReader $reader,
        public readonly ActorResolverInterface $actorResolver,
        public readonly AuditContext $auditContext,
        public readonly Purger $purger,
        public readonly AuditEntryNormalizer $normalizer,
        public readonly WorkflowTransitionBuffer $workflowBuffer,
    ) {
    }

    public static function create(
        EntityManagerInterface $em,
        ClockInterface $clock,
        SubjectKeyProviderInterface $keyProvider,
    ): self {
        $encoder = new CanonicalJsonEncoder();
        $cipher = new SensitiveValueCipher(new Cipher());
        $reader = new AuditAttributeReader();

        $auditContext = new AuditContext();
        $actorResolver = new SecurityActorResolver($auditContext, null);
        $changeSetNormalizer = new ChangeSetNormalizer($em, $reader, new FieldSanitizer(), $cipher);

        $recorder = new AuditRecorder(
            $em,
            $reader,
            $changeSetNormalizer,
            $actorResolver,
            $keyProvider,
            new AuditContextProvider($auditContext, null),
            new HashCalculator($encoder),
            $encoder,
            $clock,
        );

        $workflowBuffer = new WorkflowTransitionBuffer();

        return new self(
            new DoctrineAuditListener($recorder, $reader, $workflowBuffer),
            $recorder,
            $keyProvider,
            $reader,
            $actorResolver,
            $auditContext,
            new Purger($em, new AttributeRetentionPolicy($reader), $clock),
            new AuditEntryNormalizer($keyProvider, $cipher),
            $workflowBuffer,
        );
    }

    public function attachTo(EventManager $eventManager): void
    {
        $eventManager->addEventListener([Events::onFlush], $this->listener);
    }
}
