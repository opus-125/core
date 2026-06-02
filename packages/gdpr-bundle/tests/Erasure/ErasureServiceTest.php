<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Erasure;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Erasure\ErasureOutcome;
use Opus125\GdprBundle\Event\SubjectErased;
use Opus125\GdprBundle\Tests\Fixtures\Contact;
use Opus125\GdprBundle\Tests\Fixtures\Household;
use Opus125\GdprBundle\Tests\Fixtures\Order;
use Opus125\GdprBundle\Tests\Support\DatabaseTestCase;
use Opus125\GdprBundle\Tests\Support\GdprServices;
use Opus125\GdprBundle\Tests\Support\RecordingEventDispatcher;
use Opus125\GdprBundle\Tests\Support\RecordingLegalHold;
use Symfony\Component\Clock\MockClock;

final class ErasureServiceTest extends DatabaseTestCase
{
    private function ref(Contact $c): SubjectReference
    {
        return new SubjectReference(Contact::class, $c->getId());
    }

    public function testNullifyAndPseudonymizeAndCryptoShred(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $services = GdprServices::create(self::$em, new MockClock(), dispatcher: $dispatcher);

        $maria = new Contact('Maria', 'maria@example.org', 'allergic to penicillin');
        $order = new Order($maria, 'Hauptstr 1');
        self::$em->persist($maria);
        self::$em->persist($order);
        self::$em->flush();

        // Mint the subject key (as the audit bridge would on write) so we can
        // observe crypto-shredding destroy it.
        $ref = $this->ref($maria);
        self::assertNotNull($services->keyStore->ensureKey($ref));

        $report = $services->erasure->erase($ref);
        self::$em->clear();

        self::assertFalse($report->blocked);
        self::assertTrue($report->keyShredded);

        $reloaded = self::$em->find(Contact::class, $maria->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getEmail(), 'nullify clears the live value');
        self::assertStringStartsWith('anon-', $reloaded->getName(), 'pseudonymize replaces with a stable token');
        self::assertNotSame('Maria', $reloaded->getName());

        // crypto_shred destroyed the subject key irreversibly.
        self::assertNull($services->keyStore->keyFor($ref));
        self::assertTrue($services->keyStore->isShredded($ref));

        // The linked order's address was nullified too (it belongs to Maria alone).
        $reloadedOrder = self::$em->find(Order::class, $order->getId());
        self::assertNotNull($reloadedOrder);
        self::assertSame('', $reloadedOrder->getShippingAddress());

        // The erasure was announced for auditing.
        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(SubjectErased::class, $dispatcher->events[0]);
    }

    public function testPseudonymizePreservesReferentialIntegrity(): void
    {
        $services = GdprServices::create(self::$em, new MockClock());

        $maria = new Contact('Maria');
        $order = new Order($maria, 'Hauptstr 1');
        self::$em->persist($maria);
        self::$em->persist($order);
        self::$em->flush();

        $services->erasure->erase($this->ref($maria));
        self::$em->clear();

        // The order still resolves to the same (now pseudonymised) contact row.
        $reloadedOrder = self::$em->find(Order::class, $order->getId());
        self::assertNotNull($reloadedOrder);
        self::assertNotNull($reloadedOrder->getContact());
        self::assertSame($maria->getId(), $reloadedOrder->getContact()->getId());
    }

    public function testSharedDataIsNotErased(): void
    {
        $services = GdprServices::create(self::$em, new MockClock());

        $a = new Contact('Maria');
        $b = new Contact('Josef');
        $household = new Household($a, $b, 'Shared Hauptstr 1');
        foreach ([$a, $b, $household] as $e) {
            self::$em->persist($e);
        }
        self::$em->flush();

        $report = $services->erasure->erase($this->ref($a));
        self::$em->clear();

        $reloaded = self::$em->find(Household::class, $household->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Shared Hauptstr 1', $reloaded->getAddressLine(), 'shared data survives erasure of one owner');
        self::assertNotEmpty($report->withOutcome(ErasureOutcome::SkippedShared));
    }

    public function testLegalHoldBlocksErasure(): void
    {
        $hold = new RecordingLegalHold('pending tax audit');
        $services = GdprServices::create(self::$em, new MockClock(), legalHold: $hold);

        $maria = new Contact('Maria', 'maria@example.org');
        self::$em->persist($maria);
        self::$em->flush();

        $ref = $this->ref($maria);
        $hold->hold($ref);

        $report = $services->erasure->erase($ref);
        self::$em->clear();

        self::assertTrue($report->blocked);
        self::assertSame('pending tax audit', $report->blockReason);

        $reloaded = self::$em->find(Contact::class, $maria->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Maria', $reloaded->getName(), 'nothing is erased under legal hold');
        self::assertSame('maria@example.org', $reloaded->getEmail());
    }
}
