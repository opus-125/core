<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Recording;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Integrity\Exception\MissingAuditTransactionException;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Metadata\FieldSanitizer;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Tests\Fixtures\Customer;
use Opus\AuditBundle\Tests\Fixtures\Invoice;
use Opus\AuditBundle\Tests\Fixtures\Tag;
use Opus\AuditBundle\Tests\Support\AuditIntegrationTestCase;

final class RecordingTest extends AuditIntegrationTestCase
{
    public function testCreateProducesExactlyOneChainedEntry(): void
    {
        $this->createInvoice();

        $entries = $this->entries();
        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertSame(AuditAction::Create, $entry->getAction());
        self::assertSame(1, $entry->getSequenceNo());
        self::assertSame(HashCalculator::GENESIS_HASH, $entry->getPreviousHash());
        self::assertSame('rechnung', $entry->getStreamId());
        self::assertSame('2026-06-01T12:00:00.000000Z', $entry->getOccurredAtString());
    }

    public function testUpdateCapturesFieldDiffs(): void
    {
        $invoice = $this->createInvoice();

        $this->services->transaction->run(static function () use ($invoice): void {
            $invoice->setStatus('open');
            $invoice->setAmount(200);
            self::$em->flush();
        });

        $changes = $this->reader()->decryptMap($this->lastEntry()->getChanges());

        self::assertEqualsCanonicalizing(['old' => 'draft', 'new' => 'open'], $changes['status']);
        self::assertEqualsCanonicalizing(['old' => 0, 'new' => 200], $changes['amount']);
    }

    public function testSensitiveFieldIsEncryptedAtRestAndDecryptableOnRead(): void
    {
        $invoice = $this->createInvoice('Müller GmbH');

        $rawChanges = $this->rawColumn('changes');
        self::assertStringNotContainsString('Müller GmbH', $rawChanges, 'sensitive value must not be stored in clear');

        $decrypted = $this->reader()->decryptMap($this->lastEntry()->getChanges());
        self::assertSame('Müller GmbH', $decrypted['customerName']['new']);
    }

    public function testIgnoredFieldIsNeverRecorded(): void
    {
        $invoice = $this->createInvoice();

        $this->services->transaction->run(static function () use ($invoice): void {
            $invoice->setInternalToken('tok-secret-value');
            $invoice->setStatus('open');
            self::$em->flush();
        });

        self::assertStringNotContainsString('tok-secret-value', $this->rawColumn('changes'));
        self::assertArrayNotHasKey('internalToken', $this->lastEntry()->getChanges());
    }

    public function testSecretHeuristicMasksUndeclaredSecret(): void
    {
        $invoice = $this->createInvoice();

        $this->services->transaction->run(static function () use ($invoice): void {
            $invoice->setApiKey('sk-live-supersecret');
            self::$em->flush();
        });

        self::assertStringNotContainsString('sk-live-supersecret', $this->rawColumn('changes'));
        self::assertEqualsCanonicalizing(
            ['old' => FieldSanitizer::MASK, 'new' => FieldSanitizer::MASK],
            $this->lastEntry()->getChanges()['apiKey'],
        );
    }

    public function testDeleteRecordsPriorState(): void
    {
        $invoice = $this->createInvoice();

        $this->services->transaction->run(static function () use ($invoice): void {
            self::$em->remove($invoice);
            self::$em->flush();
        });

        $entry = $this->lastEntry();
        self::assertSame(AuditAction::Delete, $entry->getAction());

        $changes = $this->reader()->decryptMap($entry->getChanges());
        self::assertEqualsCanonicalizing(['old' => 'draft', 'new' => null], $changes['status']);
    }

    public function testCollectionChangeProducesOneConsolidatedEntry(): void
    {
        $invoice = $this->createInvoice();
        $tag = new Tag('priority');

        $this->services->transaction->run(static function () use ($invoice, $tag): void {
            self::$em->persist($tag);
            $invoice->addTag($tag);
            self::$em->flush();
        });

        // Exactly one new entry (create + this one), and it carries the diff.
        $entries = $this->entries();
        self::assertCount(2, $entries);

        $changes = $entries[1]->getChanges();
        self::assertArrayHasKey('tags', $changes);
        self::assertSame([$tag->getId()], $changes['tags']['added']);
        self::assertSame([], $changes['tags']['removed']);
    }

    public function testRollbackLeavesNoAuditEntry(): void
    {
        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');

        try {
            $this->services->transaction->run(static function () use ($customer, $invoice): void {
                self::$em->persist($customer);
                self::$em->persist($invoice);
                self::$em->flush();

                throw new \RuntimeException('boom after flush');
            });
            self::fail('Expected the transaction to bubble the exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom after flush', $e->getMessage());
        }

        self::assertSame(0, $this->countEntries(), 'a rolled-back change must leave no phantom audit row');
    }

    public function testActorDefaultsToSystemWithoutAToken(): void
    {
        $this->createInvoice();

        $entry = $this->lastEntry();
        self::assertSame(ActorType::System, $entry->getActorType());
        self::assertNull($entry->getActorId());
    }

    public function testRunAsSetsTheActor(): void
    {
        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');

        $this->services->auditContext->runAs(Actor::cli('nightly-import'), function () use ($customer, $invoice): void {
            $this->services->transaction->run(static function () use ($customer, $invoice): void {
                self::$em->persist($customer);
                self::$em->persist($invoice);
                self::$em->flush();
            });
        });

        $entry = $this->lastEntry();
        self::assertSame(ActorType::Cli, $entry->getActorType());
        self::assertSame('nightly-import', $entry->getActorId());
    }

    public function testCryptoShreddingErasesContentButKeepsChainValid(): void
    {
        $invoice = $this->createInvoice('Müller GmbH');
        $subjects = $this->services->subjectResolver->resolveForEntity($invoice);

        foreach ($subjects as $subject) {
            $this->services->keyStore->shred($subject);
        }

        // Content is gone …
        $decrypted = $this->reader()->decryptMap($this->lastEntry()->getChanges());
        self::assertSame(CryptoShredder::REDACTED, $decrypted['customerName']['new']);

        // … but the chain is still intact (ciphertext bytes were not touched).
        $result = $this->services->chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);
        self::assertTrue($result->valid, $result->message);
    }

    public function testBulkDqlBypassesCaptureAsDocumented(): void
    {
        $this->createInvoice();
        self::assertSame(1, $this->countEntries());

        // Bulk DQL bypasses the UnitOfWork — the listener cannot see it. This is
        // the Spine's documented detective-only limitation (closed by E4).
        $this->services->transaction->run(static function (): void {
            self::$em->createQuery('UPDATE '.Invoice::class.' i SET i.status = :s')
                ->setParameter('s', 'archived')
                ->execute();
        });

        self::assertSame(1, $this->countEntries(), 'bulk DQL is intentionally not captured');
    }

    public function testFlushWithoutTransactionIsRejected(): void
    {
        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');

        self::$em->persist($customer);
        self::$em->persist($invoice);

        $this->expectException(MissingAuditTransactionException::class);
        self::$em->flush();
    }

    private function createInvoice(string $customerName = 'Acme GmbH'): Invoice
    {
        $customer = new Customer($customerName);
        $invoice = new Invoice($customer, $customerName);

        $this->services->transaction->run(static function () use ($customer, $invoice): void {
            self::$em->persist($customer);
            self::$em->persist($invoice);
            self::$em->flush();
        });

        return $invoice;
    }

    private function reader(): \Opus\AuditBundle\Reading\AuditEntryReader
    {
        return $this->services->reader;
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

    private function countEntries(): int
    {
        return (int) self::$connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', Schema::ENTRY_TABLE));
    }

    private function rawColumn(string $column): string
    {
        return implode('|', self::$connection->fetchFirstColumn(
            \sprintf('SELECT %s FROM %s ORDER BY sequence_no', $column, Schema::ENTRY_TABLE),
        ));
    }
}
