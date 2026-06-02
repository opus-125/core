<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Erasure;

/**
 * What happened to one personal-data field during erasure.
 */
enum ErasureOutcome: string
{
    case Nullified = 'nullified';
    case Pseudonymized = 'pseudonymized';

    /**
     * The field's history is protected by the subject's key; the key was
     * scheduled for destruction (the live value is left untouched — crypto-shred
     * applies to append-only stores, not live fields).
     */
    case CryptoShredded = 'crypto_shredded';

    /**
     * Left untouched because the record is shared with another subject
     * (conservative reference check).
     */
    case SkippedShared = 'skipped_shared';
}
