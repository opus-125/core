<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Serializer;

use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Crypto\SubjectKeyProviderInterface;
use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Serializer\AuditEntryNormalizer;
use PHPUnit\Framework\TestCase;

final class AuditEntryNormalizerTest extends TestCase
{
    public function testNormalizesAndDecryptsSensitiveValues(): void
    {
        $shredder = new CryptoShredder($this->fixedKeyProvider(), new Cipher());
        $normalizer = new AuditEntryNormalizer($shredder);

        $entry = new AuditEntry();
        $entry->initialize(
            'id-1',
            'rechnung',
            new \DateTimeImmutable('2026-06-01T10:00:00Z'),
            'update',
            'App\\Entity\\Rechnung',
            '42',
            ActorType::User,
            'user-1',
            null,
            ['customerName' => ['old' => null, 'new' => $shredder->encryptValue('Müller GmbH', ['kunde-1'])], 'status' => ['old' => 'draft', 'new' => 'open']],
            ['route' => 'edit'],
        );
        $entry->assignSequence(1, HashCalculator::GENESIS_HASH);
        $entry->setHash(str_repeat('a', 64));

        $data = $normalizer->normalize($entry);

        self::assertTrue($normalizer->supportsNormalization($entry));
        self::assertSame('update', $data['action']);
        self::assertSame('rechnung', $data['stream']);
        // Sensitive value is decrypted; plain value passes through.
        self::assertSame('Müller GmbH', $data['changes']['customerName']['new']);
        self::assertSame(['old' => 'draft', 'new' => 'open'], $data['changes']['status']);
    }

    public function testRendersShreddedValueAsRedacted(): void
    {
        $provider = new class implements SubjectKeyProviderInterface {
            public function keyForSubject(string $subjectId): ?string
            {
                return null; // everything shredded
            }

            public function shred(string $subjectId): void
            {
            }

            public function isShredded(string $subjectId): bool
            {
                return true;
            }
        };
        $normalizer = new AuditEntryNormalizer(new CryptoShredder($provider, new Cipher()));

        $entry = new AuditEntry();
        $entry->initialize('id', 's', new \DateTimeImmutable(), 'update', null, null, ActorType::System, null, null, [
            'secret' => ['old' => null, 'new' => ['__opus_enc' => 1, 'subjects' => ['x'], 'ct' => base64_encode('garbage')]],
        ], []);
        $entry->assignSequence(1, HashCalculator::GENESIS_HASH);
        $entry->setHash(str_repeat('a', 64));

        $data = $normalizer->normalize($entry);

        self::assertSame(CryptoShredder::REDACTED, $data['changes']['secret']['new']);
    }

    private function fixedKeyProvider(): SubjectKeyProviderInterface
    {
        return new class implements SubjectKeyProviderInterface {
            public function keyForSubject(string $subjectId): ?string
            {
                return str_repeat("\x11", Cipher::KEY_BYTES);
            }

            public function shred(string $subjectId): void
            {
            }

            public function isShredded(string $subjectId): bool
            {
                return false;
            }
        };
    }
}
