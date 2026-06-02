<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Retention;

use Opus125\AuditBundle\Metadata\AuditAttributeReader;
use Opus125\AuditBundle\Retention\AttributeRetentionPolicy;
use Opus125\AuditBundle\Retention\Purger;
use Opus125\AuditBundle\Retention\RetentionPolicyInterface;
use Opus125\AuditBundle\Tests\Fixtures\Customer;
use Opus125\AuditBundle\Tests\Fixtures\Invoice;
use Opus125\AuditBundle\Tests\Support\AuditIntegrationTestCase;

final class RetentionPurgeTest extends AuditIntegrationTestCase
{
    private const string NOW = '2026-06-01T00:00:00Z';

    public function testPurgesEntriesPastRetentionAndKeepsRecentOnes(): void
    {
        $this->createInvoiceAt('2010-01-01 00:00:00'); // past 10y
        $this->createInvoiceAt('2025-01-01 00:00:00'); // within 10y

        $removed = $this->services->purger->purge(new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $removed);
        self::assertSame(1, $this->countEntries());
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

        self::assertSame(0, new Purger(self::$em, $keepForever, $this->clock)->purge(new \DateTimeImmutable(self::NOW)));
        self::assertSame(1, $this->countEntries());
    }

    public function testAttributeRetentionResolvesTheDeclaredDuration(): void
    {
        $interval = new AttributeRetentionPolicy(new AuditAttributeReader())->retentionFor(Invoice::class);

        self::assertNotNull($interval);
        self::assertSame(10, $interval->y);
    }

    private function createInvoiceAt(string $when): void
    {
        $this->clock->modify($when);

        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');
        self::$em->persist($customer);
        self::$em->persist($invoice);
        self::$em->flush();
    }

    private function countEntries(): int
    {
        return (int) self::$connection->fetchOne('SELECT COUNT(*) FROM audit_entry');
    }
}
