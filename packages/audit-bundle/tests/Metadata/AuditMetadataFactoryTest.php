<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Metadata;

use Opus\AuditBundle\Attribute\Auditable;
use Opus\AuditBundle\Attribute\AuditIgnore;
use Opus\AuditBundle\Attribute\DataSubject;
use Opus\AuditBundle\Attribute\Retention;
use Opus\AuditBundle\Attribute\Sensitive;
use Opus\AuditBundle\Metadata\AuditMetadataFactory;
use PHPUnit\Framework\TestCase;

final class AuditMetadataFactoryTest extends TestCase
{
    private AuditMetadataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AuditMetadataFactory();
    }

    public function testReadsClassLevelAttributes(): void
    {
        $meta = $this->factory->getMetadata(MetaInvoiceFixture::class);

        self::assertTrue($meta->auditable);
        self::assertSame('rechnung', $meta->stream);
        self::assertSame('10 years', $meta->retention);
    }

    public function testStreamDefaultsToClassName(): void
    {
        $meta = $this->factory->getMetadata(MetaPlainFixture::class);

        self::assertTrue($meta->auditable);
        self::assertSame(MetaPlainFixture::class, $meta->stream);
        self::assertNull($meta->retention);
    }

    public function testNonAuditableClassIsReportedAsSuch(): void
    {
        $meta = $this->factory->getMetadata(MetaNotAuditedFixture::class);

        self::assertFalse($meta->auditable);
        self::assertFalse($this->factory->isAuditable(MetaNotAuditedFixture::class));
    }

    public function testFieldClassification(): void
    {
        $meta = $this->factory->getMetadata(MetaInvoiceFixture::class);

        self::assertTrue($meta->isFieldIgnored('internalToken'));
        self::assertFalse($meta->isFieldIgnored('status'));

        self::assertTrue($meta->isFieldSensitive('customerName'));
        self::assertFalse($meta->isFieldSensitive('status'));

        self::assertSame(['customer'], $meta->subjectFields());
    }

    public function testAttributesAreInheritedFromParent(): void
    {
        $meta = $this->factory->getMetadata(MetaChildFixture::class);

        // #[Auditable]/#[Retention] declared on the parent apply to the child.
        self::assertTrue($meta->auditable);
        self::assertSame('5 years', $meta->retention);
        // Private parent property annotations are still seen.
        self::assertTrue($meta->isFieldSensitive('inheritedSecret'));
        // Child's own annotations too.
        self::assertTrue($meta->isFieldIgnored('childOnly'));
    }

    public function testMetadataIsCachedAndStable(): void
    {
        $first = $this->factory->getMetadata(MetaInvoiceFixture::class);
        $second = $this->factory->getMetadata(MetaInvoiceFixture::class);

        self::assertSame($first, $second);
    }
}

#[Auditable(stream: 'rechnung')]
#[Retention('10 years')]
class MetaInvoiceFixture
{
    #[AuditIgnore]
    private string $internalToken = '';

    #[Sensitive]
    private string $customerName = '';

    #[DataSubject]
    private ?object $customer = null;

    private string $status = '';
}

#[Auditable]
class MetaPlainFixture
{
    private string $name = '';
}

class MetaNotAuditedFixture
{
    private string $name = '';
}

#[Auditable]
#[Retention('5 years')]
class MetaParentFixture
{
    #[Sensitive]
    private string $inheritedSecret = '';
}

class MetaChildFixture extends MetaParentFixture
{
    #[AuditIgnore]
    private string $childOnly = '';
}
