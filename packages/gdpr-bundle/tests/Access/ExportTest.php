<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Access;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Access\CollectedRecord;
use Opus125\GdprBundle\Access\Export\CsvExporter;
use Opus125\GdprBundle\Access\Export\JsonExporter;
use Opus125\GdprBundle\Access\SubjectDataReport;
use Opus125\GdprBundle\Registry\FieldMetadata;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase
{
    private function report(): SubjectDataReport
    {
        $field = new FieldMetadata('name', 'name', 'crm', 'contract', false, null);
        $sensitive = new FieldMetadata('note', 'health', 'support', 'consent', true, null);

        return new SubjectDataReport(
            new SubjectReference('App\\Contact', '42'),
            [new CollectedRecord('App\\Contact', '42', [
                ['field' => $field, 'value' => 'Maria'],
                ['field' => $sensitive, 'value' => null],
            ])],
        );
    }

    public function testJsonExportIsGroupedWithMetadata(): void
    {
        $json = new JsonExporter()->export($this->report(), new \DateTimeImmutable('2026-06-02T00:00:00Z'));
        /** @var array{subject: array{class: string, id: string}, generated_at: string, records: array<string, list<array{id: string, fields: array<string, array{value: mixed, category: string, purpose: ?string, basis: ?string, sensitive: bool}>}>>} $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['class' => 'App\\Contact', 'id' => '42'], $decoded['subject']);
        self::assertSame('2026-06-02T00:00:00.000+00:00', $decoded['generated_at']);

        $fields = $decoded['records']['App\\Contact'][0]['fields'];
        self::assertSame('Maria', $fields['name']['value']);
        self::assertSame('crm', $fields['name']['purpose']);
        self::assertTrue($fields['note']['sensitive']);
    }

    public function testCsvExportHasRowPerField(): void
    {
        $csv = new CsvExporter()->export($this->report());
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        self::assertSame('entity_class,entity_id,field,category,purpose,basis,sensitive,value', $lines[0]);
        self::assertStringContainsString('App\\Contact,42,name,name,crm,contract,false,Maria', $csv);
        self::assertStringContainsString('App\\Contact,42,note,health,support,consent,true,', $csv);
    }
}
