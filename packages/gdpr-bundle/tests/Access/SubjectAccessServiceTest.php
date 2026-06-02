<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Access;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Access\SubjectDataReport;
use Opus125\GdprBundle\Exception\SubjectNotFoundException;
use Opus125\GdprBundle\Tests\Fixtures\Contact;
use Opus125\GdprBundle\Tests\Fixtures\Household;
use Opus125\GdprBundle\Tests\Fixtures\Order;
use Opus125\GdprBundle\Tests\Fixtures\OrderLine;
use Opus125\GdprBundle\Tests\Support\DatabaseTestCase;
use Opus125\GdprBundle\Tests\Support\GdprServices;
use Symfony\Component\Clock\MockClock;

final class SubjectAccessServiceTest extends DatabaseTestCase
{
    private GdprServices $services;

    protected function setUp(): void
    {
        parent::setUp();
        $this->services = GdprServices::create(self::$em, new MockClock());
    }

    public function testCollectsAcrossEveryLinkedEntity(): void
    {
        $maria = new Contact('Maria', 'maria@example.org', 'allergic to penicillin');
        $order = new Order($maria, 'Hauptstr 1');
        $line = new OrderLine($order, 'Happy birthday');
        $household = new Household($maria, null, 'Hauptstr 1');

        // An unrelated subject whose data must NOT leak into Maria's report.
        $josef = new Contact('Josef');
        $josefOrder = new Order($josef, 'Nebenstr 2');

        foreach ([$maria, $order, $line, $household, $josef, $josefOrder] as $e) {
            self::$em->persist($e);
        }
        self::$em->flush();
        self::$em->clear();

        $report = $this->services->access->collect(new SubjectReference(Contact::class, $maria->getId()));
        $byClass = $this->flatten($report);

        self::assertSame(
            [Contact::class, Household::class, Order::class, OrderLine::class],
            $this->sortedKeys($byClass),
        );

        self::assertSame('Maria', $byClass[Contact::class][0]['name']);
        self::assertSame('maria@example.org', $byClass[Contact::class][0]['email']);
        self::assertSame('allergic to penicillin', $byClass[Contact::class][0]['note']);
        self::assertSame('Hauptstr 1', $byClass[Order::class][0]['shippingAddress']);
        self::assertSame('Happy birthday', $byClass[OrderLine::class][0]['giftMessage']);

        // Josef's order belongs to him only.
        self::assertCount(1, $byClass[Order::class]);
    }

    public function testReportCarriesFieldMetadata(): void
    {
        $maria = new Contact('Maria', 'maria@example.org');
        self::$em->persist($maria);
        self::$em->flush();
        self::$em->clear();

        $report = $this->services->access->collect(new SubjectReference(Contact::class, $maria->getId()));
        $array = $report->toArray();
        $note = $array['records'][Contact::class][0]['fields']['note'];

        self::assertTrue($note['sensitive']);
        self::assertSame('support', $note['purpose']);
        self::assertSame('consent', $note['basis']);
    }

    public function testUnknownSubjectThrows(): void
    {
        // A well-formed identifier that was never persisted.
        $this->expectException(SubjectNotFoundException::class);
        $this->services->access->collect(new SubjectReference(Contact::class, '01890000-0000-7000-8000-000000000000'));
    }

    /**
     * @return array<class-string, list<array<string, mixed>>>
     */
    private function flatten(SubjectDataReport $report): array
    {
        $byClass = [];
        foreach ($report->records as $record) {
            $values = [];
            foreach ($record->fields as $entry) {
                $values[$entry['field']->property] = $entry['value'];
            }
            $byClass[$record->entityClass][] = $values;
        }

        return $byClass;
    }

    /**
     * @param array<class-string, list<array<string, mixed>>> $byClass
     *
     * @return list<class-string>
     */
    private function sortedKeys(array $byClass): array
    {
        $keys = array_keys($byClass);
        sort($keys);

        return $keys;
    }
}
