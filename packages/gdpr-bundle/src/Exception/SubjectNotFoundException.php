<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Exception;

use Opus125\DataContracts\Subject\SubjectReference;

/**
 * The requested data subject does not exist (cannot be loaded by class + id).
 */
final class SubjectNotFoundException extends \RuntimeException implements GdprException
{
    public static function for(SubjectReference $subject): self
    {
        return new self(\sprintf('No data subject found for %s.', $subject));
    }
}
