<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Serializer;

use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\SensitiveValueCipher;
use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Serializer\AuditEntryNormalizer;
use Opus\AuditBundle\Tests\Support\TestSubjectKeyProvider;
use PHPUnit\Framework\TestCase;

final class AuditEntryNormalizerTest extends TestCase
{
    public function testNormalizesAndDecryptsSensitiveValues(): void
    {
        $keys = new TestSubjectKeyProvider();
        $valueCipher = new SensitiveValueCipher(new Cipher());
        $normalizer = new AuditEntryNormalizer($keys, $valueCipher);

        $key = $keys->keyFor(\stdClass::class, '42');
        self::assertNotNull($key);

        $entry = $this->entry($valueCipher, $key);
        $data = $normalizer->normalize($entry);

        self::assertTrue($normalizer->supportsNormalization($entry));
        self::assertSame('update', $data['action']);
        self::assertSame('Müller GmbH', $data['changes']['customerName']['new']);
        self::assertSame(['old' => 'draft', 'new' => 'open'], $data['changes']['status']);
    }

    public function testRendersShreddedValueAsRedacted(): void
    {
        $keys = new TestSubjectKeyProvider();
        $valueCipher = new SensitiveValueCipher(new Cipher());
        $normalizer = new AuditEntryNormalizer($keys, $valueCipher);

        $key = $keys->keyFor(\stdClass::class, '42');
        self::assertNotNull($key);
        $entry = $this->entry($valueCipher, $key);

        $keys->shred(\stdClass::class, '42');

        $data = $normalizer->normalize($entry);
        self::assertSame(SensitiveValueCipher::REDACTED, $data['changes']['customerName']['new']);
    }

    private function entry(SensitiveValueCipher $cipher, string $key): AuditEntry
    {
        $entry = new AuditEntry();
        $entry->initialize(
            'id-1',
            'rechnung',
            new \DateTimeImmutable('2026-06-01T10:00:00Z'),
            'update',
            \stdClass::class,
            '42',
            ActorType::User,
            'user-1',
            'Alice',
            [
                'customerName' => ['old' => null, 'new' => $cipher->encrypt('Müller GmbH', $key)],
                'status' => ['old' => 'draft', 'new' => 'open'],
            ],
            ['route' => 'edit'],
        );
        $entry->assignSequence(1, HashCalculator::GENESIS_HASH);
        $entry->setHash(str_repeat('a', 64));

        return $entry;
    }
}
