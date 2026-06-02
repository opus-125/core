<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto\Exception;

/**
 * Raised when authenticated decryption fails — a wrong/destroyed key, mismatched
 * associated data, or tampered ciphertext.
 *
 * In the crypto-shredding read path this is expected and benign for a *shredded*
 * subject (the key is gone on purpose); callers translate it into a redaction
 * placeholder rather than surfacing it.
 */
final class DecryptionFailedException extends \RuntimeException
{
}
