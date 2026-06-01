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
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Crypto\DerivedSubjectKeyProvider;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\NullStreamLock;
use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use Opus\AuditBundle\Metadata\FieldSanitizer;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Recording\ActorContextEncryptor;
use Opus\AuditBundle\Recording\AuditContextProvider;
use Opus\AuditBundle\Recording\AuditRecorder;
use Opus\AuditBundle\Recording\ChangeSetNormalizer;
use Opus\AuditBundle\Recording\DoctrineAuditListener;
use Opus\AuditBundle\Retention\AttributeRetentionPolicy;
use Opus\AuditBundle\Retention\Purger;
use Opus\AuditBundle\Serializer\AuditEntryNormalizer;
use Opus\AuditBundle\Subject\AttributeSubjectResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Wires the Spine service graph for integration tests — the same wiring the
 * container produces, assembled by hand so tests run without a full kernel.
 */
final class AuditServices
{
    public const string TEST_SECRET = 'test-application-secret';

    private function __construct(
        public readonly DoctrineAuditListener $listener,
        public readonly AuditRecorder $recorder,
        public readonly DerivedSubjectKeyProvider $keyProvider,
        public readonly CryptoShredder $shredder,
        public readonly AuditMetadataFactory $metadataFactory,
        public readonly AttributeSubjectResolver $subjectResolver,
        public readonly ActorResolverInterface $actorResolver,
        public readonly AuditContext $auditContext,
        public readonly Purger $purger,
        public readonly AuditEntryNormalizer $normalizer,
    ) {
    }

    public static function create(
        EntityManagerInterface $em,
        ClockInterface $clock,
        ?TokenStorageInterface $tokenStorage = null,
        ?RequestStack $requestStack = null,
    ): self {
        $encoder = new CanonicalJsonEncoder();
        $hashCalculator = new HashCalculator($encoder);
        $cipher = new Cipher();

        $keyProvider = new DerivedSubjectKeyProvider(self::TEST_SECRET, $em, $clock);
        $shredder = new CryptoShredder($keyProvider, $cipher);

        $metadataFactory = new AuditMetadataFactory();
        $normalizer = new ChangeSetNormalizer($em, $shredder, new FieldSanitizer());

        $auditContext = new AuditContext();
        $actorResolver = new SecurityActorResolver($auditContext, $tokenStorage);
        $subjectResolver = new AttributeSubjectResolver($metadataFactory, $em);
        $contextProvider = new AuditContextProvider($auditContext, $requestStack);

        $recorder = new AuditRecorder(
            $em,
            $metadataFactory,
            $normalizer,
            $actorResolver,
            $subjectResolver,
            $contextProvider,
            new ActorContextEncryptor($shredder),
            $hashCalculator,
            $encoder,
            new NullStreamLock(),
            $clock,
            AuditEntry::class,
        );

        return new self(
            new DoctrineAuditListener($recorder, $metadataFactory),
            $recorder,
            $keyProvider,
            $shredder,
            $metadataFactory,
            $subjectResolver,
            $actorResolver,
            $auditContext,
            new Purger($em, new AttributeRetentionPolicy($metadataFactory), $clock, AuditEntry::class),
            new AuditEntryNormalizer($shredder),
        );
    }

    public function attachTo(EventManager $eventManager): void
    {
        $eventManager->addEventListener([Events::onFlush], $this->listener);
    }
}
