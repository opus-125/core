<?php

declare(strict_types=1);

namespace Opus125\DataContracts\Tests\Attribute;

use Opus125\DataContracts\Attribute\PersonalData;
use Opus125\DataContracts\Erasure\ErasureStrategy;
use PHPUnit\Framework\TestCase;

final class PersonalDataTest extends TestCase
{
    public function testStoresMetadata(): void
    {
        $attr = new PersonalData(category: 'contact', purpose: 'crm', basis: 'contract', sensitive: true);

        self::assertSame('contact', $attr->category);
        self::assertSame('crm', $attr->purpose);
        self::assertSame('contract', $attr->basis);
        self::assertTrue($attr->sensitive);
        self::assertNull($attr->erasureStrategy());
    }

    public function testAcceptsErasureAsEnumOrString(): void
    {
        self::assertSame(ErasureStrategy::Pseudonymize, new PersonalData('name', erasure: ErasureStrategy::Pseudonymize)->erasureStrategy());
        self::assertSame(ErasureStrategy::CryptoShred, new PersonalData('name', erasure: 'crypto_shred')->erasureStrategy());
    }

    public function testRejectsEmptyCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PersonalData('');
    }

    public function testRejectsUnknownErasureStrategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PersonalData('name', erasure: 'incinerate');
    }
}
