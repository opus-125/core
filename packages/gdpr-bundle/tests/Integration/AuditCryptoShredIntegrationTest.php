<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Opus125\AuditBundle\Crypto\SensitiveValueCipher;
use Opus125\AuditBundle\Model\AuditEntry;
use Opus125\AuditBundle\Model\AuditEntryInterface;
use Opus125\AuditBundle\Tests\Support\AuditServices;
use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Integration\Audit\AuditKeyProviderBridge;
use Opus125\GdprBundle\Model\SubjectKey;
use Opus125\GdprBundle\Tests\Fixtures\AuditableSubject;
use Opus125\GdprBundle\Tests\Support\GdprServices;
use Opus125\GdprBundle\Tests\Support\OrmFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The optional, additive Audit integration: with the bridge wired as the audit
 * key provider, crypto-shredding a subject in the GDPR bundle renders their
 * `#[Sensitive]` audit values unreadable **and** leaves the audit hash-chain
 * valid.
 */
final class AuditCryptoShredIntegrationTest extends TestCase
{
    private static EntityManagerInterface $em;
    private static Connection $connection;

    public static function setUpBeforeClass(): void
    {
        $auditModel = \dirname((string) new \ReflectionClass(AuditEntry::class)->getFileName());
        $gdprModel = \dirname((string) new \ReflectionClass(SubjectKey::class)->getFileName());

        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [$auditModel, $gdprModel, \dirname(__DIR__).'/Fixtures'],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(AuditEntryInterface::class, AuditEntry::class, []);
        $eventManager = new \Doctrine\Common\EventManager();
        $eventManager->addEventSubscriber($resolver);

        try {
            self::$connection = OrmFactory::createConnection();
            self::$connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL is not available: '.$e->getMessage());
        }

        self::$em = new EntityManager(self::$connection, $config, $eventManager);

        $schemaTool = new SchemaTool(self::$em);
        $metadata = array_map(self::$em->getClassMetadata(...), [AuditEntry::class, SubjectKey::class, AuditableSubject::class]);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function setUp(): void
    {
        self::$connection->executeStatement('TRUNCATE audit_entry, gdpr_subject_key, gdpr_fixture_auditable_subject RESTART IDENTITY CASCADE');
        self::$em->clear();
    }

    public function testCryptoShredRendersAuditValueUnreadableYetChainStaysValid(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-06-01T12:00:00Z'));
        $gdpr = GdprServices::create(self::$em, $clock);

        // Wire the bridge as the audit key provider — the one point of contact.
        $bridge = new AuditKeyProviderBridge(self::$em, $gdpr->resolver, $gdpr->keyStore);
        $audit = AuditServices::create(self::$em, $clock, $bridge);
        self::$em->getEventManager()->addEventListener([Events::onFlush], $audit->listener);

        // Write an audited, sensitive value.
        $subject = new AuditableSubject('Maria', 'confidential diagnosis');
        self::$em->persist($subject);
        self::$em->flush();
        $id = $subject->getId();
        self::$em->clear();

        // Before erasure: the sensitive audit value decrypts.
        self::assertSame('confidential diagnosis', $this->auditedNote($audit));

        // Crypto-shred the subject through the GDPR bundle.
        $report = $gdpr->erasure->erase(new SubjectReference(AuditableSubject::class, $id));
        self::assertTrue($report->keyShredded);
        self::$em->clear();

        // After erasure: the same audit value reads back redacted...
        self::assertSame(SensitiveValueCipher::REDACTED, $this->auditedNote($audit));

        // ...while the hash-chain still verifies (the ciphertext bytes remain).
        /** @var \Opus125\AuditBundle\Repository\AuditEntryRepository $repo */
        $repo = self::$em->getRepository(AuditEntry::class);
        self::assertTrue($repo->verify('auditable_subject')->valid);
    }

    private function auditedNote(AuditServices $audit): mixed
    {
        /** @var list<AuditEntryInterface> $entries */
        $entries = self::$em->getRepository(AuditEntry::class)->findByStream('auditable_subject');
        self::assertNotEmpty($entries);

        $normalized = $audit->normalizer->normalize($entries[0]);

        /* @var array{changes: array{note: array{new: mixed}}} $normalized */
        return $normalized['changes']['note']['new'];
    }
}
