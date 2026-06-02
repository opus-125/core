<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Ropa;

use Opus125\GdprBundle\Registry\PersonalDataRegistry;
use Opus125\GdprBundle\Ropa\RecordsOfProcessingGenerator;
use Opus125\GdprBundle\Tests\Fixtures\Contact;
use Opus125\GdprBundle\Tests\Fixtures\Order;
use Opus125\GdprBundle\Tests\Fixtures\PlainTag;
use Opus125\GdprBundle\Tests\Support\ArrayEntityClassLocator;
use PHPUnit\Framework\TestCase;

final class RecordsOfProcessingGeneratorTest extends TestCase
{
    public function testGroupsFieldsByPurpose(): void
    {
        $generator = new RecordsOfProcessingGenerator(
            new PersonalDataRegistry(),
            new ArrayEntityClassLocator([Contact::class, Order::class, PlainTag::class]),
        );

        $ropa = $generator->generate();
        self::assertNotSame('', $ropa['scope_note']);

        $byPurpose = [];
        foreach ($ropa['activities'] as $activity) {
            $byPurpose[$activity['purpose'] ?? ''] = $activity;
        }

        // crm groups Contact.name + Contact.email; shipping groups Order.shippingAddress; support groups Contact.note.
        self::assertArrayHasKey('crm', $byPurpose);
        self::assertContains('contract', $byPurpose['crm']['legal_bases']);
        self::assertContains('name', $byPurpose['crm']['categories']);

        self::assertTrue($byPurpose['support']['has_sensitive']);
        self::assertArrayHasKey('shipping', $byPurpose);
    }
}
