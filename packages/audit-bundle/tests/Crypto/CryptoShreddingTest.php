<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Crypto;

use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Crypto\DoctrineKeyStore;
use Opus\AuditBundle\Crypto\Exception\LegalHoldViolationException;
use Opus\AuditBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Clock\MockClock;

final class CryptoShreddingTest extends DatabaseTestCase
{
    private DoctrineKeyStore $keyStore;
    private CryptoShredder $shredder;

    protected function setUp(): void
    {
        parent::setUp();

        $cipher = new Cipher();
        $this->keyStore = new DoctrineKeyStore(
            self::$connection,
            $cipher,
            new MockClock(new \DateTimeImmutable('2026-06-01T00:00:00Z')),
            str_repeat("\x01", Cipher::KEY_BYTES),
            'test-kek',
        );
        $this->shredder = new CryptoShredder($this->keyStore, $cipher);
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
        $envelope = $this->shredder->encryptValue($value, ['kunde-1']);

        self::assertSame($value, $this->shredder->decryptValue($envelope));
    }

    public function testShreddingMakesValueUnrecoverable(): void
    {
        $envelope = $this->shredder->encryptValue('Müller GmbH', ['kunde-1']);

        $this->keyStore->shred('kunde-1');

        self::assertTrue($this->keyStore->isShredded('kunde-1'));
        self::assertSame(CryptoShredder::REDACTED, $this->shredder->decryptValue($envelope));
    }

    public function testShreddingPersistsAcrossFreshKeystore(): void
    {
        $envelope = $this->shredder->encryptValue('Müller GmbH', ['kunde-1']);
        $this->keyStore->shred('kunde-1');

        // A brand-new keystore (empty cache) must still find the value erased.
        $freshShredder = new CryptoShredder(
            new DoctrineKeyStore(self::$connection, new Cipher(), new MockClock(), str_repeat("\x01", Cipher::KEY_BYTES), 'test-kek'),
            new Cipher(),
        );

        self::assertSame(CryptoShredder::REDACTED, $freshShredder->decryptValue($envelope));
    }

    public function testMultiSubjectShreddingAnyOneErasesTheValue(): void
    {
        // Encrypted under both subjects' keys combined; destroying either erases it.
        $envelope = $this->shredder->encryptValue('shared note', ['kunde-1', 'kunde-2']);

        self::assertSame('shared note', $this->shredder->decryptValue($envelope));

        $this->keyStore->shred('kunde-2');

        self::assertSame(CryptoShredder::REDACTED, $this->shredder->decryptValue($envelope));
    }

    public function testSubjectOrderDoesNotAffectDecryptability(): void
    {
        $envelope = $this->shredder->encryptValue('shared', ['b-subject', 'a-subject']);

        // Same subjects, different declared order, must still decrypt.
        self::assertSame('shared', $this->shredder->decryptValue($envelope));
    }

    public function testLegalHoldBlocksShredding(): void
    {
        $this->shredder->encryptValue('held', ['kunde-1']);
        $this->keyStore->placeLegalHold('kunde-1');

        try {
            $this->keyStore->shred('kunde-1');
            self::fail('Expected a legal hold violation.');
        } catch (LegalHoldViolationException) {
            // expected
        }

        self::assertFalse($this->keyStore->isShredded('kunde-1'));
    }

    public function testLiftingLegalHoldAllowsShredding(): void
    {
        $this->shredder->encryptValue('held', ['kunde-1']);
        $this->keyStore->placeLegalHold('kunde-1');
        $this->keyStore->liftLegalHold('kunde-1');

        $this->keyStore->shred('kunde-1');

        self::assertTrue($this->keyStore->isShredded('kunde-1'));
    }

    public function testReProvisioningAfterShredDoesNotResurrectOldData(): void
    {
        $old = $this->shredder->encryptValue('old name', ['kunde-1']);
        $this->keyStore->shred('kunde-1');

        // New lawful processing mints a fresh DEK for the same subject.
        $new = $this->shredder->encryptValue('new name', ['kunde-1']);

        self::assertFalse($this->keyStore->isShredded('kunde-1'), 'Subject is live again after re-provisioning.');
        self::assertSame('new name', $this->shredder->decryptValue($new));
        self::assertSame(CryptoShredder::REDACTED, $this->shredder->decryptValue($old), 'Old ciphertext stays erased.');
    }

    public function testShredIsIdempotentAndSafeForUnknownSubject(): void
    {
        $this->keyStore->shred('never-seen');
        $this->keyStore->shred('never-seen');

        self::assertFalse($this->keyStore->hasSubject('never-seen'));
    }
}
