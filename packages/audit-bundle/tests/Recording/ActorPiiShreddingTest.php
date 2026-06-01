<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Recording;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Tests\Fixtures\Customer;
use Opus\AuditBundle\Tests\Fixtures\Invoice;
use Opus\AuditBundle\Tests\Support\AuditIntegrationTestCase;

/**
 * The acting person's identity is personal data too: the actor label is stored
 * encrypted under the actor's own subject key and is erased when that subject is
 * shredded.
 */
final class ActorPiiShreddingTest extends AuditIntegrationTestCase
{
    public function testActorLabelIsEncryptedAtRestAndDecryptableThenShreddable(): void
    {
        $this->services->auditContext->runAs(Actor::user('clerk-anna', 'Anna Berger'), function (): void {
            $customer = new Customer('Acme');
            $invoice = new Invoice($customer, 'Acme');
            $this->services->transaction->run(static function () use ($customer, $invoice): void {
                self::$em->persist($customer);
                self::$em->persist($invoice);
                self::$em->flush();
            });
        });

        // Stored label is not the clear name.
        $rawLabel = (string) self::$connection->fetchOne(
            \sprintf('SELECT actor_label FROM %s ORDER BY sequence_no LIMIT 1', Schema::ENTRY_TABLE),
        );
        self::assertStringNotContainsString('Anna Berger', $rawLabel);

        // The reader decrypts it for display.
        $entry = $this->firstEntry();
        self::assertSame('clerk-anna', $entry->getActorId());
        self::assertSame('Anna Berger', $this->services->reader->decryptLabel($entry->getActorLabel()));

        // Shredding the actor subject erases the label.
        $this->services->keyStore->shred('actor:user:clerk-anna');
        $entry = $this->firstEntry();
        self::assertSame(CryptoShredder::REDACTED, $this->services->reader->decryptLabel($entry->getActorLabel()));
    }

    public function testSystemActorHasNoEncryptedLabel(): void
    {
        // No runAs, no token → system actor with no subject; nothing to encrypt.
        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');
        $this->services->transaction->run(static function () use ($customer, $invoice): void {
            self::$em->persist($customer);
            self::$em->persist($invoice);
            self::$em->flush();
        });

        self::assertNull($this->firstEntry()->getActorLabel());
    }

    private function firstEntry(): AuditEntry
    {
        self::$em->clear();
        /** @var AuditEntry $entry */
        $entry = self::$em->getRepository(AuditEntry::class)->findOneBy([], ['sequenceNo' => 'ASC']);

        return $entry;
    }
}
