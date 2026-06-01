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
use Opus\AuditBundle\Crypto\DoctrineKeyStore;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\ChainVerifier;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\PostgresAdvisoryLock;
use Opus\AuditBundle\Integrity\PostgresHashChain;
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
use Opus\AuditBundle\Sealing\Sealer;
use Opus\AuditBundle\Subject\AttributeSubjectResolver;
use Opus\AuditBundle\Transaction\AuditTransaction;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Wires the full Spine service graph for integration tests — the same wiring
 * the bundle's container produces, assembled by hand so tests run without a
 * full kernel.
 */
final class AuditServices
{
    private function __construct(
        public readonly DoctrineAuditListener $listener,
        public readonly AuditRecorder $recorder,
        public readonly PostgresHashChain $chain,
        public readonly DoctrineKeyStore $keyStore,
        public readonly CryptoShredder $shredder,
        public readonly AuditMetadataFactory $metadataFactory,
        public readonly AttributeSubjectResolver $subjectResolver,
        public readonly ActorResolverInterface $actorResolver,
        public readonly AuditContext $auditContext,
        public readonly AuditTransaction $transaction,
        public readonly AuditEntryReader $reader,
        public readonly Sealer $sealer,
        public readonly Purger $purger,
        public readonly AuditEventRecorder $eventRecorder,
        public readonly ChainVerifier $verifier,
    ) {
    }

    public static function create(
        EntityManagerInterface $em,
        ClockInterface $clock,
        ?TokenStorageInterface $tokenStorage = null,
        ?RequestStack $requestStack = null,
    ): self {
        $connection = $em->getConnection();
        $encoder = new CanonicalJsonEncoder();
        $hashCalculator = new HashCalculator($encoder);
        $cipher = new Cipher();

        $chain = new PostgresHashChain($connection, new PostgresAdvisoryLock($connection), $hashCalculator, $encoder);
        $keyStore = new DoctrineKeyStore($connection, $cipher, $clock, str_repeat("\x2a", Cipher::KEY_BYTES), 'test-kek');
        $shredder = new CryptoShredder($keyStore, $cipher);

        $metadataFactory = new AuditMetadataFactory();
        $sanitizer = new FieldSanitizer();
        $normalizer = new ChangeSetNormalizer($em, $shredder, $sanitizer);

        $auditContext = new AuditContext();
        $actorResolver = new SecurityActorResolver($auditContext, $tokenStorage);
        $subjectResolver = new AttributeSubjectResolver($metadataFactory, $em);
        $contextProvider = new AuditContextProvider($auditContext, $requestStack);
        $actorContextEncryptor = new ActorContextEncryptor($shredder);

        $recorder = new AuditRecorder(
            $em,
            $metadataFactory,
            $normalizer,
            $chain,
            $actorResolver,
            $subjectResolver,
            $contextProvider,
            $actorContextEncryptor,
            $clock,
        );

        $listener = new DoctrineAuditListener($recorder, $metadataFactory);
        $retentionPolicy = new AttributeRetentionPolicy($metadataFactory);

        return new self(
            $listener,
            $recorder,
            $chain,
            $keyStore,
            $shredder,
            $metadataFactory,
            $subjectResolver,
            $actorResolver,
            $auditContext,
            new AuditTransaction($connection),
            new AuditEntryReader($shredder),
            new Sealer($connection, $clock),
            new Purger($connection, $retentionPolicy, $clock),
            new AuditEventRecorder($connection, $actorResolver, $subjectResolver, $contextProvider, $actorContextEncryptor, $clock),
            new ChainVerifier($chain, $connection),
        );
    }

    public function attachTo(EventManager $eventManager): void
    {
        $eventManager->addEventListener([Events::onFlush], $this->listener);
    }
}
