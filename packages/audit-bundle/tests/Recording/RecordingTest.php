<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Recording;

use Opus125\AuditBundle\Actor\Actor;
use Opus125\AuditBundle\Crypto\SensitiveValueCipher;
use Opus125\AuditBundle\Enum\ActorType;
use Opus125\AuditBundle\Integrity\HashCalculator;
use Opus125\AuditBundle\Metadata\FieldSanitizer;
use Opus125\AuditBundle\Model\AuditEntry;
use Opus125\AuditBundle\Repository\AuditEntryRepository;
use Opus125\AuditBundle\Tests\Fixtures\Customer;
use Opus125\AuditBundle\Tests\Fixtures\Invoice;
use Opus125\AuditBundle\Tests\Fixtures\Tag;
use Opus125\AuditBundle\Tests\Support\AuditIntegrationTestCase;

final class RecordingTest extends AuditIntegrationTestCase
{
    public function testCreateProducesExactlyOneChainedEntry(): void
    {
        $this->createInvoice();

        $entries = $this->entries();
        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertSame('create', $entry->getAction());
        self::assertSame(1, $entry->getSequenceNo());
        self::assertSame(HashCalculator::GENESIS_HASH, $entry->getPreviousHash());
        self::assertSame('rechnung', $entry->getStream());
    }

    public function testUpdateCapturesFieldDiffs(): void
    {
        $invoice = $this->createInvoice();

        $invoice->setStatus('open');
        $invoice->setAmount(200);
        self::$em->flush();

        $changes = $this->decrypt($this->lastEntry());
        self::assertEqualsCanonicalizing(['old' => 'draft', 'new' => 'open'], $changes['status']);
        self::assertEqualsCanonicalizing(['old' => 0, 'new' => 200], $changes['amount']);
    }

    public function testSensitiveFieldIsEncryptedAtRestAndDecryptableOnRead(): void
    {
        $this->createInvoice('Müller GmbH');

        self::assertStringNotContainsString('Müller GmbH', $this->rawChanges());

        self::assertSame('Müller GmbH', $this->decrypt($this->lastEntry())['customerName']['new']);
    }

    public function testIgnoredFieldIsNeverRecorded(): void
    {
        $invoice = $this->createInvoice();

        $invoice->setInternalToken('tok-secret-value');
        $invoice->setStatus('open');
        self::$em->flush();

        self::assertStringNotContainsString('tok-secret-value', $this->rawChanges());
        self::assertArrayNotHasKey('internalToken', $this->lastEntry()->getChanges());
    }

    public function testSecretHeuristicMasksUndeclaredSecret(): void
    {
        $invoice = $this->createInvoice();

        $invoice->setApiKey('sk-live-supersecret');
        self::$em->flush();

        self::assertStringNotContainsString('sk-live-supersecret', $this->rawChanges());
        self::assertEqualsCanonicalizing(
            ['old' => FieldSanitizer::MASK, 'new' => FieldSanitizer::MASK],
            $this->lastEntry()->getChanges()['apiKey'],
        );
    }

    public function testDeleteRecordsPriorState(): void
    {
        $invoice = $this->createInvoice();

        self::$em->remove($invoice);
        self::$em->flush();

        $entry = $this->lastEntry();
        self::assertSame('delete', $entry->getAction());
        self::assertEqualsCanonicalizing(['old' => 'draft', 'new' => null], $this->decrypt($entry)['status']);
    }

    public function testCollectionChangeProducesOneConsolidatedEntry(): void
    {
        $invoice = $this->createInvoice();
        $tag = new Tag('priority');

        self::$em->persist($tag);
        $invoice->addTag($tag);
        self::$em->flush();

        $entries = $this->entries();
        self::assertCount(2, $entries);
        $changes = $entries[1]->getChanges();
        self::assertArrayHasKey('tags', $changes);
        self::assertSame([$tag->getId()], $changes['tags']['added']);
    }

    public function testRecordingNeedsNoExplicitTransaction(): void
    {
        $this->createInvoice();
        self::assertSame(1, $this->countEntries());
    }

    public function testRollbackLeavesNoAuditEntry(): void
    {
        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');

        self::$connection->beginTransaction();
        self::$em->persist($customer);
        self::$em->persist($invoice);
        self::$em->flush();
        self::$connection->rollBack();

        self::$em->clear();
        self::assertSame(0, $this->countEntries(), 'a rolled-back change must leave no phantom audit row');
    }

    public function testActorDefaultsToSystemWithoutAToken(): void
    {
        $this->createInvoice();

        self::assertSame(ActorType::System, $this->lastEntry()->getActorType());
        self::assertNull($this->lastEntry()->getActorId());
    }

    public function testRunAsSetsTheActor(): void
    {
        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');

        $this->services->auditContext->runAs(Actor::cli('nightly-import'), static function () use ($customer, $invoice): void {
            self::$em->persist($customer);
            self::$em->persist($invoice);
            self::$em->flush();
        });

        self::assertSame(ActorType::Cli, $this->lastEntry()->getActorType());
        self::assertSame('nightly-import', $this->lastEntry()->getActorId());
    }

    public function testCryptoShreddingErasesContentButKeepsChainValid(): void
    {
        $invoice = $this->createInvoice('Müller GmbH');

        // The project erases the subject — here, by telling the key provider.
        $this->keyProvider->shred(Invoice::class, $invoice->getId());

        self::assertSame(SensitiveValueCipher::REDACTED, $this->decrypt($this->lastEntry())['customerName']['new']);
        self::assertTrue($this->repository()->verify('rechnung')->valid);
    }

    public function testVerifyDetectsTampering(): void
    {
        $this->createInvoice();
        $this->createInvoice();

        self::$connection->executeStatement(
            "UPDATE audit_entry SET changes = '{\"status\":{\"new\":\"hacked\",\"old\":\"x\"}}' WHERE sequence_no = 1",
        );

        $result = $this->repository()->verify('rechnung');
        self::assertFalse($result->valid);
        self::assertSame(1, $result->brokenAtSequence);
    }

    public function testBulkDqlBypassesCaptureAsDocumented(): void
    {
        $this->createInvoice();
        self::assertSame(1, $this->countEntries());

        self::$em->createQuery('UPDATE '.Invoice::class.' i SET i.status = :s')->setParameter('s', 'archived')->execute();

        self::assertSame(1, $this->countEntries(), 'bulk DQL is intentionally not captured');
    }

    private function createInvoice(string $customerName = 'Acme GmbH'): Invoice
    {
        $customer = new Customer($customerName);
        $invoice = new Invoice($customer, $customerName);
        self::$em->persist($customer);
        self::$em->persist($invoice);
        self::$em->flush();

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function decrypt(AuditEntry $entry): array
    {
        return $this->services->normalizer->normalize($entry)['changes'];
    }

    /**
     * @return list<AuditEntry>
     */
    private function entries(): array
    {
        self::$em->clear();

        return self::$em->getRepository(AuditEntry::class)->findBy([], ['sequenceNo' => 'ASC']);
    }

    private function lastEntry(): AuditEntry
    {
        $entries = $this->entries();

        return $entries[array_key_last($entries)];
    }

    private function repository(): AuditEntryRepository
    {
        self::$em->clear();
        /** @var AuditEntryRepository $repository */
        $repository = self::$em->getRepository(AuditEntry::class);

        return $repository;
    }

    private function countEntries(): int
    {
        return (int) self::$connection->fetchOne('SELECT COUNT(*) FROM audit_entry');
    }

    private function rawChanges(): string
    {
        return implode('|', self::$connection->fetchFirstColumn('SELECT changes FROM audit_entry ORDER BY sequence_no'));
    }
}
