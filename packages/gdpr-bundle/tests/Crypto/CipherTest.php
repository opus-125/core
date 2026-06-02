<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Crypto;

use Opus125\GdprBundle\Crypto\Cipher;
use Opus125\GdprBundle\Exception\DecryptionFailedException;
use PHPUnit\Framework\TestCase;

final class CipherTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $cipher = new Cipher();
        $key = $cipher->generateKey();

        $blob = $cipher->encrypt($key, 'secret payload', 'aad');

        self::assertSame('secret payload', $cipher->decrypt($key, $blob, 'aad'));
    }

    public function testWrongKeyFails(): void
    {
        $cipher = new Cipher();
        $blob = $cipher->encrypt($cipher->generateKey(), 'x', 'aad');

        $this->expectException(DecryptionFailedException::class);
        $cipher->decrypt($cipher->generateKey(), $blob, 'aad');
    }

    public function testWrongAadFails(): void
    {
        $cipher = new Cipher();
        $key = $cipher->generateKey();
        $blob = $cipher->encrypt($key, 'x', 'aad');

        $this->expectException(DecryptionFailedException::class);
        $cipher->decrypt($key, $blob, 'other-aad');
    }

    public function testRejectsBadKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cipher()->encrypt('short', 'x');
    }
}
