<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Exception;

/**
 * Marker for every exception thrown by the GDPR bundle, so applications can
 * catch the whole family with one `catch`.
 */
interface GdprException extends \Throwable
{
}
