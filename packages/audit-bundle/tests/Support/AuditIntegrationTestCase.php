<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Support;

use Doctrine\ORM\Events;
use Opus125\AuditBundle\Recording\DoctrineAuditListener;
use Opus125\AuditBundle\Tests\Fixtures\Customer;
use Opus125\AuditBundle\Tests\Fixtures\Invoice;
use Opus125\AuditBundle\Tests\Fixtures\Tag;
use Symfony\Component\Clock\MockClock;

/**
 * Base class for end-to-end audit tests: a PostgreSQL EntityManager with the
 * audit listener attached and a freshly wired service graph per test.
 */
abstract class AuditIntegrationTestCase extends DatabaseTestCase
{
    protected AuditServices $services;
    protected TestSubjectKeyProvider $keyProvider;
    protected MockClock $clock;

    private static ?DoctrineAuditListener $attached = null;

    protected static function fixtureEntities(): array
    {
        return [Customer::class, Tag::class, Invoice::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $eventManager = self::$em->getEventManager();
        if (null !== self::$attached) {
            $eventManager->removeEventListener([Events::onFlush], self::$attached);
        }

        $this->clock = new MockClock(new \DateTimeImmutable('2026-06-01T12:00:00.000000Z'));
        $this->keyProvider = new TestSubjectKeyProvider();
        $this->services = AuditServices::create(self::$em, $this->clock, $this->keyProvider);

        $eventManager->addEventListener([Events::onFlush], $this->services->listener);
        self::$attached = $this->services->listener;
    }
}
