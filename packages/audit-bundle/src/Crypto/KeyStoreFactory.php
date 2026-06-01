<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Builds the default {@see DoctrineKeyStore} from configuration.
 *
 * The configured KEK is base64 of 32 raw bytes; a derived, stable id records
 * which KEK wrapped each DEK (for rotation). Misconfiguration fails fast with a
 * message that shows how to mint a key.
 */
final class KeyStoreFactory
{
    private function __construct()
    {
    }

    public static function createDoctrine(
        Connection $connection,
        Cipher $cipher,
        ClockInterface $clock,
        #[\SensitiveParameter]
        string $base64Kek,
    ): DoctrineKeyStore {
        if ('' === $base64Kek) {
            throw new \InvalidArgumentException('opus_audit.kek is not configured. Crypto-shredding needs a master key. Generate one with: php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"');
        }

        $kek = base64_decode($base64Kek, true);
        if (false === $kek || Cipher::KEY_BYTES !== \strlen($kek)) {
            throw new \InvalidArgumentException(\sprintf('opus_audit.kek must be the base64 encoding of exactly %d random bytes.', Cipher::KEY_BYTES));
        }

        $kekId = substr(hash('sha256', $kek), 0, 16);

        return new DoctrineKeyStore($connection, $cipher, $clock, $kek, $kekId);
    }
}
