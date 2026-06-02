<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Crypto;

use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\SensitiveValueCipher;
use PHPUnit\Framework\TestCase;

final class SensitiveValueCipherTest extends TestCase
{
    private SensitiveValueCipher $cipher;
    private string $key;

    protected function setUp(): void
    {
        $this->cipher = new SensitiveValueCipher(new Cipher());
        $this->key = str_repeat("\x11", Cipher::KEY_BYTES);
    }

    public function testRoundTrip(): void
    {
        $envelope = $this->cipher->encrypt('Müller GmbH', $this->key);

        self::assertTrue(SensitiveValueCipher::isEnvelope($envelope));
        self::assertSame('Müller GmbH', $this->cipher->decrypt($envelope, $this->key));
    }

    public function testEnvelopeHasNoPlaintext(): void
    {
        $envelope = $this->cipher->encrypt('topsecret', $this->key);

        self::assertStringNotContainsString('topsecret', json_encode($envelope, \JSON_THROW_ON_ERROR));
    }

    public function testNonScalarRoundTrip(): void
    {
        $value = ['iban' => 'AT00', 'limit' => 5000, 'flags' => [true, null]];

        self::assertSame($value, $this->cipher->decrypt($this->cipher->encrypt($value, $this->key), $this->key));
    }

    public function testNullKeyMeansShredded(): void
    {
        $envelope = $this->cipher->encrypt('gone', $this->key);

        self::assertSame(SensitiveValueCipher::REDACTED, $this->cipher->decrypt($envelope, null));
    }

    public function testWrongKeyIsRedactedNotThrown(): void
    {
        $envelope = $this->cipher->encrypt('data', $this->key);

        self::assertSame(SensitiveValueCipher::REDACTED, $this->cipher->decrypt($envelope, str_repeat("\x22", Cipher::KEY_BYTES)));
    }

    public function testRedactedEnvelopeDecryptsToPlaceholder(): void
    {
        self::assertSame(SensitiveValueCipher::REDACTED, $this->cipher->decrypt($this->cipher->redactedEnvelope(), $this->key));
    }
}
