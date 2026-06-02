<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Crypto;

use Opus125\AuditBundle\Crypto\Cipher;
use Opus125\AuditBundle\Crypto\Exception\DecryptionFailedException;
use PHPUnit\Framework\TestCase;

final class CipherTest extends TestCase
{
    private Cipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new Cipher();
    }

    public function testGeneratedKeyHasCorrectLength(): void
    {
        self::assertSame(Cipher::KEY_BYTES, \strlen($this->cipher->generateKey()));
    }

    public function testRoundTrip(): void
    {
        $key = $this->cipher->generateKey();
        $blob = $this->cipher->encrypt($key, 'Müller GmbH', 'aad');

        self::assertSame('Müller GmbH', $this->cipher->decrypt($key, $blob, 'aad'));
    }

    public function testCiphertextIsNotPlaintext(): void
    {
        $key = $this->cipher->generateKey();
        $blob = $this->cipher->encrypt($key, 'secret-value');

        self::assertStringNotContainsString('secret-value', $blob);
    }

    public function testEncryptionIsNonDeterministic(): void
    {
        $key = $this->cipher->generateKey();

        self::assertNotSame(
            $this->cipher->encrypt($key, 'same'),
            $this->cipher->encrypt($key, 'same'),
            'A random nonce must make repeated encryptions differ.',
        );
    }

    public function testWrongKeyFails(): void
    {
        $blob = $this->cipher->encrypt($this->cipher->generateKey(), 'data');

        $this->expectException(DecryptionFailedException::class);
        $this->cipher->decrypt($this->cipher->generateKey(), $blob);
    }

    public function testWrongAadFails(): void
    {
        $key = $this->cipher->generateKey();
        $blob = $this->cipher->encrypt($key, 'data', 'context-a');

        $this->expectException(DecryptionFailedException::class);
        $this->cipher->decrypt($key, $blob, 'context-b');
    }

    public function testTamperedCiphertextFails(): void
    {
        $key = $this->cipher->generateKey();
        $blob = $this->cipher->encrypt($key, 'data');
        $blob[\strlen($blob) - 1] = ('A' === $blob[\strlen($blob) - 1]) ? 'B' : 'A';

        $this->expectException(DecryptionFailedException::class);
        $this->cipher->decrypt($key, $blob);
    }

    public function testTooShortBlobFails(): void
    {
        $this->expectException(DecryptionFailedException::class);
        $this->cipher->decrypt($this->cipher->generateKey(), 'short');
    }

    public function testInvalidKeyLengthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cipher->encrypt('too-short', 'data');
    }
}
