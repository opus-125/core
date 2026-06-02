<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Access;

use Opus125\DataContracts\Subject\SubjectReference;

/**
 * The complete personal-data picture for one subject: every collected record,
 * with each field's value and its declared purpose/basis/category metadata.
 *
 * Registry-driven, so it is always complete — there is no per-entity export
 * script to fall out of date. Exporters ({@see Export\JsonExporter},
 * {@see Export\CsvExporter}) render it for Art. 15 (access) and Art. 20
 * (portability).
 */
final readonly class SubjectDataReport
{
    /**
     * @param list<CollectedRecord> $records
     */
    public function __construct(
        public SubjectReference $subject,
        public array $records,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->records;
    }

    /**
     * A plain, grouped array — records by entity class, fields with metadata —
     * suitable for direct JSON encoding or row flattening.
     *
     * @return array{
     *     subject: array{class: class-string, id: string},
     *     generated_at: string|null,
     *     records: array<class-string, list<array{id: string, fields: array<string, array{value: mixed, category: string, purpose: string|null, basis: string|null, sensitive: bool}>}>>
     * }
     */
    public function toArray(?\DateTimeImmutable $generatedAt = null): array
    {
        $grouped = [];
        foreach ($this->records as $record) {
            $fields = [];
            foreach ($record->fields as $entry) {
                $meta = $entry['field'];
                $fields[$meta->property] = [
                    'value' => $entry['value'],
                    'category' => $meta->category,
                    'purpose' => $meta->purpose,
                    'basis' => $meta->basis,
                    'sensitive' => $meta->sensitive,
                ];
            }

            $grouped[$record->entityClass][] = ['id' => $record->entityId, 'fields' => $fields];
        }

        return [
            'subject' => ['class' => $this->subject->entityClass, 'id' => $this->subject->entityId],
            'generated_at' => $generatedAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339_EXTENDED),
            'records' => $grouped,
        ];
    }
}
