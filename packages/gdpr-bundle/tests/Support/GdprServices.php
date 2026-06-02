<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Opus125\GdprBundle\Access\Export\CsvExporter;
use Opus125\GdprBundle\Access\Export\JsonExporter;
use Opus125\GdprBundle\Access\SubjectAccessService;
use Opus125\GdprBundle\Crypto\Cipher;
use Opus125\GdprBundle\Crypto\DoctrineKeyStore;
use Opus125\GdprBundle\Crypto\KeyStoreInterface;
use Opus125\GdprBundle\Erasure\ErasureService;
use Opus125\GdprBundle\Erasure\LegalHoldInterface;
use Opus125\GdprBundle\Erasure\NullLegalHold;
use Opus125\GdprBundle\Erasure\Pseudonymizer;
use Opus125\GdprBundle\Erasure\ReferenceChecker;
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

/**
 * Hand-wires the GDPR service graph for integration tests — the same wiring the
 * container produces, assembled without a kernel.
 */
final class GdprServices
{
    private function __construct(
        public readonly PersonalDataRegistry $registry,
        public readonly SubjectResolver $resolver,
        public readonly SubjectPathResolver $pathResolver,
        public readonly SubjectAccessService $access,
        public readonly ErasureService $erasure,
        public readonly KeyStoreInterface $keyStore,
        public readonly RecordsOfProcessingGenerator $ropa,
        public readonly JsonExporter $jsonExporter,
        public readonly CsvExporter $csvExporter,
    ) {
    }

    public static function create(
        EntityManagerInterface $em,
        ClockInterface $clock,
        string $secret = 'test-secret',
        ?LegalHoldInterface $legalHold = null,
        ?EventDispatcherInterface $dispatcher = null,
    ): self {
        $registry = new PersonalDataRegistry();
        $locator = new MappedEntityLocator($em, $registry);
        $pathResolver = new SubjectPathResolver($registry);
        $resolver = new SubjectResolver($em, $registry);
        $collector = new SubjectRecordCollector($em, $pathResolver, $locator);
        $subjectLoader = new SubjectLoader($em);
        $cipher = new Cipher();
        $keyStore = new DoctrineKeyStore($em, $cipher, $clock, $secret);

        $access = new SubjectAccessService($em, $registry, $collector, $subjectLoader, new ValueNormalizer($em));

        $erasure = new ErasureService(
            $em,
            $registry,
            $collector,
            $subjectLoader,
            $legalHold ?? new NullLegalHold(),
            new Pseudonymizer($secret),
            new ReferenceChecker($resolver),
            $keyStore,
            $dispatcher,
        );

        return new self(
            $registry,
            $resolver,
            $pathResolver,
            $access,
            $erasure,
            $keyStore,
            new RecordsOfProcessingGenerator($registry, $locator),
            new JsonExporter(),
            new CsvExporter(),
        );
    }
}
