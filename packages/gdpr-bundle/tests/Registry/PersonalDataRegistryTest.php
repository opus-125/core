<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Registry;

use Opus125\DataContracts\Erasure\ErasureStrategy;
use Opus125\GdprBundle\Registry\PersonalDataRegistry;
use Opus125\GdprBundle\Tests\Fixtures\Contact;
use Opus125\GdprBundle\Tests\Fixtures\Order;
use Opus125\GdprBundle\Tests\Fixtures\PlainTag;
use PHPUnit\Framework\TestCase;

final class PersonalDataRegistryTest extends TestCase
{
    public function testReadsSubjectAndFieldMetadata(): void
    {
        $registry = new PersonalDataRegistry();

        self::assertTrue($registry->isDataSubject(Contact::class));

        $fields = $registry->personalData(Contact::class);
        self::assertSame(['name', 'email', 'note'], array_keys($fields));

        $name = $fields['name'];
        self::assertSame('name', $name->category);
        self::assertSame('crm', $name->purpose);
        self::assertSame('contract', $name->basis);
        self::assertFalse($name->sensitive);
        self::assertSame(ErasureStrategy::Pseudonymize, $name->erasure);

        self::assertTrue($fields['note']->sensitive);
        self::assertSame(ErasureStrategy::CryptoShred, $fields['note']->erasure);
    }

    public function testReadsSubjectLinks(): void
    {
        $registry = new PersonalDataRegistry();

        self::assertFalse($registry->isDataSubject(Order::class));
        $links = $registry->links(Order::class);
        self::assertCount(1, $links);
        self::assertSame('contact', $links[0]->property);
        self::assertSame(Contact::class, $links[0]->target);
    }

    public function testNonPersonalEntityIsIrrelevant(): void
    {
        $registry = new PersonalDataRegistry();
        $meta = $registry->metadataFor(PlainTag::class);

        self::assertFalse($meta->isRelevant());
        self::assertFalse($meta->hasPersonalData());
        self::assertSame([], $registry->personalData(PlainTag::class));
    }
}
