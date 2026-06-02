<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Opus\AuditBundle\Actor\ActorResolverInterface;
use Opus\AuditBundle\Actor\AuditContext;
use Opus\AuditBundle\Actor\SecurityActorResolver;
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
use Opus\AuditBundle\Serializer\AuditEntryNormalizer;
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

        return new self(
            new DoctrineAuditListener($recorder, $reader),
            $recorder,
            $keyProvider,
            $reader,
            $actorResolver,
            $auditContext,
            new Purger($em, new AttributeRetentionPolicy($reader), $clock),
            new AuditEntryNormalizer($keyProvider, $cipher),
        );
    }

    public function attachTo(EventManager $eventManager): void
    {
        $eventManager->addEventListener([Events::onFlush], $this->listener);
    }
}
