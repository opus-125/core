<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Crypto;

use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Crypto\DerivedSubjectKeyProvider;
use Opus\AuditBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Clock\MockClock;

final class CryptoShreddingTest extends DatabaseTestCase
{
    private DerivedSubjectKeyProvider $keyProvider;
    private CryptoShredder $shredder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyProvider = new DerivedSubjectKeyProvider('test-secret', self::$em, new MockClock());
        $this->shredder = new CryptoShredder($this->keyProvider, new Cipher());
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $envelope = $this->shredder->encryptValue('Müller GmbH', ['kunde-1']);

        self::assertTrue(CryptoShredder::isEnvelope($envelope));
        self::assertSame('Müller GmbH', $this->shredder->decryptValue($envelope));
    }

    public function testEnvelopeContainsNoPlaintext(): void
    {
        $envelope = $this->shredder->encryptValue('topsecret', ['kunde-1']);

        self::assertStringNotContainsString('topsecret', json_encode($envelope, \JSON_THROW_ON_ERROR));
    }

    public function testNonScalarValuesSurviveTheRoundTrip(): void
    {
        $value = ['iban' => 'AT00', 'limit' => 5000, 'flags' => [true, null]];

        self::assertSame($value, $this->shredder->decryptValue($this->shredder->encryptValue($value, ['kunde-1'])));
    }

    public function testShreddingMakesValueUnrecoverable(): void
    {
        $envelope = $this->shredder->encryptValue('Müller GmbH', ['kunde-1']);

        $this->keyProvider->shred('kunde-1');

        self::assertTrue($this->keyProvider->isShredded('kunde-1'));
        self::assertSame(CryptoShredder::REDACTED, $this->shredder->decryptValue($envelope));
    }

    public function testShreddingPersistsAcrossFreshProvider(): void
    {
        $envelope = $this->shredder->encryptValue('Müller GmbH', ['kunde-1']);
        $this->keyProvider->shred('kunde-1');

        $fresh = new CryptoShredder(new DerivedSubjectKeyProvider('test-secret', self::$em, new MockClock()), new Cipher());

        self::assertSame(CryptoShredder::REDACTED, $fresh->decryptValue($envelope));
    }

    public function testMultiSubjectShreddingAnyOneErasesTheValue(): void
    {
        $envelope = $this->shredder->encryptValue('shared note', ['kunde-1', 'kunde-2']);
        self::assertSame('shared note', $this->shredder->decryptValue($envelope));

        $this->keyProvider->shred('kunde-2');

        self::assertSame(CryptoShredder::REDACTED, $this->shredder->decryptValue($envelope));
    }

    public function testSubjectOrderDoesNotAffectDecryptability(): void
    {
        $envelope = $this->shredder->encryptValue('shared', ['b-subject', 'a-subject']);

        self::assertSame('shared', $this->shredder->decryptValue($envelope));
    }

    public function testShredIsIdempotent(): void
    {
        $this->keyProvider->shred('kunde-1');
        $this->keyProvider->shred('kunde-1');

        self::assertTrue($this->keyProvider->isShredded('kunde-1'));
    }
}
