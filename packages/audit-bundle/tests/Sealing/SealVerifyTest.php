<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Sealing;

use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Tests\Fixtures\Customer;
use Opus\AuditBundle\Tests\Fixtures\Invoice;
use Opus\AuditBundle\Tests\Support\AuditIntegrationTestCase;

final class SealVerifyTest extends AuditIntegrationTestCase
{
    public function testSealRecordsTheCurrentHead(): void
    {
        $this->createInvoices(3);

        $sealId = $this->services->sealer->seal('rechnung');
        self::assertNotNull($sealId);

        $seal = self::$connection->fetchAssociative(
            \sprintf('SELECT last_sequence, head_hash FROM %s WHERE id = :id', Schema::SEAL_TABLE),
            ['id' => $sealId],
        );
        self::assertNotFalse($seal);
        self::assertSame(3, (int) $seal['last_sequence']);
    }

    public function testIncrementalVerificationOnlyChecksSinceTheLastSeal(): void
    {
        $this->createInvoices(2);
        $this->services->sealer->seal('rechnung');
        $this->createInvoices(3); // sequences 3,4,5

        $result = $this->services->verifier->verifyIncremental('rechnung');

        self::assertTrue($result->valid);
        self::assertSame(3, $result->checkedCount, 'only entries after the seal are walked');
    }

    public function testIncrementalVerificationMissesPreSealTamperButFullCatchesIt(): void
    {
        $this->createInvoices(3);
        $this->services->sealer->seal('rechnung'); // seals through seq 3
        $this->createInvoices(1); // seq 4

        // Tamper an entry that predates the seal.
        self::$connection->executeStatement(
            \sprintf('UPDATE %s SET changes = :c WHERE sequence_no = 2', Schema::ENTRY_TABLE),
            ['c' => '{"status":{"new":"hacked","old":"draft"}}'],
        );

        // Incremental only walks seq 4 → does not see it.
        self::assertTrue($this->services->verifier->verifyIncremental('rechnung')->valid);

        // Full walk re-checks from genesis → catches it.
        $full = $this->services->verifier->verifyFull('rechnung');
        self::assertFalse($full->valid);
        self::assertSame(2, $full->brokenAtSequence);
    }

    public function testSealWithoutNewEntriesVerifiesNothingButStaysValid(): void
    {
        $this->createInvoices(2);
        $this->services->sealer->seal('rechnung');

        $result = $this->services->verifier->verifyIncremental('rechnung');

        self::assertTrue($result->valid);
        self::assertSame(0, $result->checkedCount);
    }

    private function createInvoices(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $customer = new Customer('Acme');
            $invoice = new Invoice($customer, 'Acme');
            $this->services->transaction->run(static function () use ($customer, $invoice): void {
                self::$em->persist($customer);
                self::$em->persist($invoice);
                self::$em->flush();
            });
        }
    }
}
