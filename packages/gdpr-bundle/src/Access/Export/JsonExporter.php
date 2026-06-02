<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Access\Export;

use Opus125\GdprBundle\Access\SubjectDataReport;

/**
 * Renders a {@see SubjectDataReport} as structured, machine-readable JSON for
 * Art. 20 portability — grouped by entity class, each field carrying its
 * purpose/basis/category metadata.
 */
final class JsonExporter
{
    public const string FORMAT = 'json';

    public function export(SubjectDataReport $report, ?\DateTimeImmutable $generatedAt = null): string
    {
        return json_encode(
            $report->toArray($generatedAt),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
