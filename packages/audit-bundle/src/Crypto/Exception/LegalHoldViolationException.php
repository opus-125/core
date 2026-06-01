<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Crypto\Exception;

/**
 * Raised when a crypto-shred is attempted on a subject under a legal hold.
 *
 * A legal hold expresses an overriding statutory duty to preserve (GDPR
 * Art. 17(3)(b)); erasure must be refused, loudly, until the hold is lifted.
 */
final class LegalHoldViolationException extends \RuntimeException
{
    public static function forSubject(string $subjectId): self
    {
        return new self(\sprintf('Cannot shred subject "%s": a legal hold is in force.', $subjectId));
    }
}
