<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Erasure;

use Opus125\DataContracts\Subject\SubjectReference;

/**
 * Produces a **stable** pseudonym for a field value, so that pseudonymising the
 * same subject's same field always yields the same token.
 *
 * Stability is what lets `pseudonymize` preserve referential integrity and
 * aggregate statistics (rows still join, counts still hold) while making the
 * person unidentifiable. The token is a keyed HMAC over the subject and field —
 * derived, not reversible — so it leaks neither the original value nor anything
 * linkable back to the person without the secret.
 */
final class Pseudonymizer
{
    private const int TOKEN_HEX_LENGTH = 24;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('The pseudonymisation secret must not be empty.');
        }
    }

    /**
     * A deterministic pseudonym for ($subject, $property), prefixed so it is
     * recognisable as anonymised data.
     */
    public function pseudonym(SubjectReference $subject, string $property): string
    {
        $digest = hash_hmac('sha256', $subject.'|'.$property, $this->secret);

        return 'anon-'.substr($digest, 0, self::TOKEN_HEX_LENGTH);
    }
}
