<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Access\Export;

use Opus125\GdprBundle\Access\SubjectDataReport;

/**
 * Renders a {@see SubjectDataReport} as a flat CSV — one row per personal-data
 * field, with the entity, the value and its metadata — for a human-readable
 * Art. 15 copy. Non-scalar values are JSON-encoded into the cell.
 */
final class CsvExporter
{
    public const string FORMAT = 'csv';

    private const array HEADER = ['entity_class', 'entity_id', 'field', 'category', 'purpose', 'basis', 'sensitive', 'value'];

    public function export(SubjectDataReport $report, ?\DateTimeImmutable $generatedAt = null): string
    {
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('Unable to open an in-memory stream for CSV export.');
        }

        fputcsv($stream, self::HEADER, escape: '');

        foreach ($report->records as $record) {
            foreach ($record->fields as $entry) {
                $field = $entry['field'];
                fputcsv($stream, [
                    $record->entityClass,
                    $record->entityId,
                    $field->property,
                    $field->category,
                    $field->purpose ?? '',
                    $field->basis ?? '',
                    $field->sensitive ? 'true' : 'false',
                    $this->cell($entry['value']),
                ], escape: '');
            }
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return false === $csv ? '' : $csv;
    }

    private function cell(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
