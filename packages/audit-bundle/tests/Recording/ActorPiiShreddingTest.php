<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Recording;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Model\AuditEntry;
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
        $this->services->auditContext->runAs(Actor::user('clerk-anna', 'Anna Berger'), static function (): void {
            $customer = new Customer('Acme');
            $invoice = new Invoice($customer, 'Acme');
            self::$em->persist($customer);
            self::$em->persist($invoice);
            self::$em->flush();
        });

        $rawLabel = (string) self::$connection->fetchOne('SELECT actor_label FROM audit_entry ORDER BY sequence_no LIMIT 1');
        self::assertStringNotContainsString('Anna Berger', $rawLabel);

        $entry = $this->firstEntry();
        self::assertSame('clerk-anna', $entry->getActorId());
        self::assertSame('Anna Berger', $this->services->normalizer->decryptLabel($entry->getActorLabel()));

        $this->services->keyProvider->shred('actor:user:clerk-anna');
        self::assertSame(CryptoShredder::REDACTED, $this->services->normalizer->decryptLabel($this->firstEntry()->getActorLabel()));
    }

    public function testSystemActorHasNoEncryptedLabel(): void
    {
        $customer = new Customer('Acme');
        $invoice = new Invoice($customer, 'Acme');
        self::$em->persist($customer);
        self::$em->persist($invoice);
        self::$em->flush();

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
