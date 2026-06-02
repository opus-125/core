<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Exception;

/**
 * Authenticated decryption failed — wrong key (e.g. a shredded subject), wrong
 * associated data, or tampered ciphertext.
 */
final class DecryptionFailedException extends \RuntimeException implements GdprException
{
}
