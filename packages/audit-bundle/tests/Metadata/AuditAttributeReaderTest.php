<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Metadata;

use Opus125\AuditBundle\Attribute\Auditable;
use Opus125\AuditBundle\Attribute\AuditIgnore;
use Opus125\AuditBundle\Attribute\Retention;
use Opus125\AuditBundle\Attribute\Sensitive;
use Opus125\AuditBundle\Metadata\AuditAttributeReader;
use PHPUnit\Framework\TestCase;

final class AuditAttributeReaderTest extends TestCase
{
    private AuditAttributeReader $reader;

    protected function setUp(): void
    {
        $this->reader = new AuditAttributeReader();
    }

    public function testReadsClassAttributes(): void
    {
        self::assertTrue($this->reader->isAuditable(ReaderInvoiceFixture::class));
        self::assertSame('rechnung', $this->reader->stream(ReaderInvoiceFixture::class));
        self::assertSame('10 years', $this->reader->retention(ReaderInvoiceFixture::class));
    }

    public function testStreamDefaultsToClassName(): void
    {
        self::assertSame(ReaderPlainFixture::class, $this->reader->stream(ReaderPlainFixture::class));
        self::assertNull($this->reader->retention(ReaderPlainFixture::class));
    }

    public function testNonAuditableClass(): void
    {
        self::assertFalse($this->reader->isAuditable(ReaderNotAuditedFixture::class));
    }

    public function testFieldClassification(): void
    {
        self::assertTrue($this->reader->isIgnored(ReaderInvoiceFixture::class, 'internalToken'));
        self::assertFalse($this->reader->isIgnored(ReaderInvoiceFixture::class, 'status'));
        self::assertTrue($this->reader->isSensitive(ReaderInvoiceFixture::class, 'customerName'));
        self::assertFalse($this->reader->isSensitive(ReaderInvoiceFixture::class, 'status'));
    }

    public function testAttributesAreInheritedAndCached(): void
    {
        self::assertTrue($this->reader->isAuditable(ReaderChildFixture::class));
        self::assertSame('5 years', $this->reader->retention(ReaderChildFixture::class));
        self::assertTrue($this->reader->isSensitive(ReaderChildFixture::class, 'inheritedSecret'));
        self::assertTrue($this->reader->isIgnored(ReaderChildFixture::class, 'childOnly'));
    }
}

#[Auditable(stream: 'rechnung')]
#[Retention('10 years')]
class ReaderInvoiceFixture
{
    #[AuditIgnore]
    private string $internalToken = '';
    #[Sensitive]
    private string $customerName = '';
    private string $status = '';
}

#[Auditable]
class ReaderPlainFixture
{
    private string $name = '';
}

class ReaderNotAuditedFixture
{
    private string $name = '';
}

#[Auditable]
#[Retention('5 years')]
class ReaderParentFixture
{
    #[Sensitive]
    private string $inheritedSecret = '';
}

class ReaderChildFixture extends ReaderParentFixture
{
    #[AuditIgnore]
    private string $childOnly = '';
}
