<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Retention;

use Doctrine\DBAL\ParameterType;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Retention\Purger;
use Opus\AuditBundle\Retention\RetentionPolicyInterface;
use Opus\AuditBundle\Tests\Fixtures\Customer;
use Opus\AuditBundle\Tests\Fixtures\Invoice;
use Opus\AuditBundle\Tests\Support\AuditIntegrationTestCase;

final class RetentionPurgeTest extends AuditIntegrationTestCase
{
    private const string LATER = '2026-06-01T00:00:00Z';

    public function testPurgesPastRetentionBlockAndKeepsChainVerifiable(): void
    {
        $this->createInvoiceAt('2010-01-01 00:00:00'); // seq 1 — past 10y
        $this->createInvoiceAt('2012-01-01 00:00:00'); // seq 2 — past 10y
        $this->createInvoiceAt('2025-01-01 00:00:00'); // seq 3 — within 10y

        $report = $this->services->purger->purge('rechnung', new \DateTimeImmutable(self::LATER));

        self::assertSame(2, $report->purgedCount);
        self::assertSame(2, $report->newGenesisSequence);

        // Remaining entry only.
        self::assertSame(1, (int) self::$connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', Schema::ENTRY_TABLE)));

        // A genesis seal anchors the shortened chain, which still verifies.
        $result = $this->services->verifier->verifyFull('rechnung');
        self::assertTrue($result->valid, $result->message);
    }

    public function testLegalHoldStopsPurgeAtTheHeldEntry(): void
    {
        $this->createInvoiceAt('2010-01-01 00:00:00'); // seq 1
        $this->createInvoiceAt('2011-01-01 00:00:00'); // seq 2 — will be held
        $this->createInvoiceAt('2012-01-01 00:00:00'); // seq 3

        self::$connection->executeStatement(
            \sprintf('UPDATE %s SET legal_hold = :h WHERE sequence_no = 2', Schema::ENTRY_TABLE),
            ['h' => true],
            ['h' => ParameterType::BOOLEAN],
        );

        $report = $this->services->purger->purge('rechnung', new \DateTimeImmutable(self::LATER));

        // Only seq 1 (before the hold) is removed; the hold blocks the rest.
        self::assertSame(1, $report->purgedCount);
        self::assertSame(2, (int) self::$connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', Schema::ENTRY_TABLE)));
    }

    public function testKeepForeverPolicyPurgesNothing(): void
    {
        $this->createInvoiceAt('2000-01-01 00:00:00');

        $keepForever = new class implements RetentionPolicyInterface {
            public function retentionFor(string $entityClass): ?\DateInterval
            {
                return null;
            }
        };

        $purger = new Purger(self::$connection, $keepForever, $this->clock);
        $report = $purger->purge('rechnung', new \DateTimeImmutable(self::LATER));

        self::assertSame(0, $report->purgedCount);
        self::assertSame(1, (int) self::$connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', Schema::ENTRY_TABLE)));
    }

    private function createInvoiceAt(string $when): void
    {
        $this->clock->modify($when);

        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');

        $this->services->transaction->run(static function () use ($customer, $invoice): void {
            self::$em->persist($customer);
            self::$em->persist($invoice);
            self::$em->flush();
        });
    }
}
