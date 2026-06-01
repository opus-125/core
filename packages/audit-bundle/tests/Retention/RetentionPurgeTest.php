<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Retention;

use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Retention\AttributeRetentionPolicy;
use Opus\AuditBundle\Retention\Purger;
use Opus\AuditBundle\Retention\RetentionPolicyInterface;
use Opus\AuditBundle\Tests\Fixtures\Customer;
use Opus\AuditBundle\Tests\Fixtures\Invoice;
use Opus\AuditBundle\Tests\Support\AuditIntegrationTestCase;

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

        $purger = new Purger(self::$em, $keepForever, $this->clock, AuditEntry::class);

        self::assertSame(0, $purger->purge(new \DateTimeImmutable(self::NOW)));
        self::assertSame(1, $this->countEntries());
    }

    public function testAttributeRetentionResolvesTheDeclaredDuration(): void
    {
        $policy = new AttributeRetentionPolicy(new AuditMetadataFactory());

        $interval = $policy->retentionFor(Invoice::class);

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
