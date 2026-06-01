<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Recording;

use Opus\AuditBundle\Actor\Actor;
use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Tests\Support\AuditIntegrationTestCase;

/**
 * Non-mutating events land in the lighter, un-chained audit_event log.
 */
final class AuditEventRecorderTest extends AuditIntegrationTestCase
{
    public function testRecordsAnEventWithSystemActor(): void
    {
        $id = $this->services->eventRecorder->record('download', 'App\\Entity\\Bescheid', 'doc-7');

        $row = self::$connection->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE id = :id', Schema::EVENT_TABLE),
            ['id' => $id],
        );

        self::assertNotFalse($row);
        self::assertSame('download', $row['action']);
        self::assertSame('doc-7', $row['entity_id']);
        self::assertSame(ActorType::System->value, $row['actor_type']);
        self::assertTrue((bool) $row['succeeded']);
    }

    public function testRecordsFailedAccessAttemptWithActor(): void
    {
        $this->services->auditContext->runAs(Actor::user('mallory', 'Mallory'), function (): void {
            $this->services->eventRecorder->record('access_denied', 'App\\Entity\\Bescheid', 'doc-7', succeeded: false);
        });

        $row = self::$connection->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE action = :a', Schema::EVENT_TABLE),
            ['a' => 'access_denied'],
        );

        self::assertNotFalse($row);
        self::assertFalse((bool) $row['succeeded']);
        self::assertSame('mallory', $row['actor_id']);
        // Actor label is encrypted at rest, decryptable via the reader.
        self::assertStringNotContainsString('Mallory', (string) $row['actor_label']);
        self::assertSame('Mallory', $this->services->reader->decryptLabel((string) $row['actor_label']));
    }
}
