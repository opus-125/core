<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Support;

use Doctrine\ORM\Events;
use Opus\AuditBundle\Recording\DoctrineAuditListener;
use Opus\AuditBundle\Tests\Fixtures\Customer;
use Opus\AuditBundle\Tests\Fixtures\Invoice;
use Opus\AuditBundle\Tests\Fixtures\Tag;
use Symfony\Component\Clock\MockClock;

/**
 * Base class for end-to-end Spine tests: a PostgreSQL EntityManager with the
 * audit listener attached and a freshly wired service graph per test (so the
 * keystore's in-memory DEK cache never leaks across truncations).
 */
abstract class AuditIntegrationTestCase extends DatabaseTestCase
{
    protected AuditServices $services;
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
        $this->services = AuditServices::create(self::$em, $this->clock);

        $eventManager->addEventListener([Events::onFlush], $this->services->listener);
        self::$attached = $this->services->listener;
    }
}
