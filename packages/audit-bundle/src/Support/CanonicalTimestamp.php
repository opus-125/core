<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Support;

/**
 * The one canonical textual form of an instant used across the bundle.
 *
 * RFC 3339 in UTC with fixed six-digit microseconds, e.g.
 * `2026-06-01T10:30:00.123456Z`. This exact format is used for the
 * `occurred_at`/`sealed_at`/`created_at` columns *and* by the canonical JSON
 * encoder, so a stored timestamp round-trips losslessly and hashes identically
 * regardless of the originating time zone.
 */
final class CanonicalTimestamp
{
    public const string FORMAT = 'Y-m-d\TH:i:s.u\Z';

    private function __construct()
    {
    }

    public static function format(\DateTimeInterface $when): string
    {
        return \DateTimeImmutable::createFromInterface($when)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::FORMAT);
    }
}
