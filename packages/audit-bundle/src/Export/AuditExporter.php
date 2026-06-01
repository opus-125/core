<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Export;

use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Reading\AuditEntryReader;

/**
 * Serialises audit entries for export.
 *
 * JSON is the complete, decrypted record (also serving the GDPR access /
 * portability request); CSV is the flat, non-personal metadata only — its
 * columns deliberately exclude the nested `changes`/`context` so a spreadsheet
 * export never leaks the detail a JSON export carries.
 *
 * Shredded values appear as the redaction placeholder, never as cleartext.
 */
final class AuditExporter
{
    /**
     * @var list<string>
     */
    private const array CSV_COLUMNS = [
        'id', 'stream_id', 'sequence_no', 'occurred_at', 'action',
        'entity_class', 'entity_id', 'actor_type', 'actor_id', 'legal_hold', 'hash',
    ];

    public function __construct(
        private readonly AuditEntryReader $reader,
    ) {
    }

    /**
     * @param iterable<AuditEntry> $entries
     */
    public function toJson(iterable $entries): string
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->reader->toArray($entry);
        }

        return (string) json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param iterable<AuditEntry> $entries
     */
    public function toCsv(iterable $entries): string
    {
        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            throw new \RuntimeException('Unable to open a temporary stream for CSV export.');
        }

        fputcsv($handle, self::CSV_COLUMNS, escape: '');

        foreach ($entries as $entry) {
            $view = $this->reader->toArray($entry);
            fputcsv($handle, array_map(
                static fn (string $column): string => self::stringify($view[$column] ?? null),
                self::CSV_COLUMNS,
            ), escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return false === $csv ? '' : $csv;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => (string) json_encode($value, \JSON_THROW_ON_ERROR),
        };
    }
}
