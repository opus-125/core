<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Export;

use Opus\AuditBundle\Crypto\Cipher;
use Opus\AuditBundle\Crypto\CryptoShredder;
use Opus\AuditBundle\Crypto\KeyStoreInterface;
use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Export\AuditExporter;
use Opus\AuditBundle\Model\AuditEntry;
use Opus\AuditBundle\Reading\AuditEntryReader;
use PHPUnit\Framework\TestCase;

final class AuditExporterTest extends TestCase
{
    private AuditExporter $exporter;

    protected function setUp(): void
    {
        // No sensitive envelopes in these fixtures, so the keystore is never hit.
        $shredder = new CryptoShredder($this->nullKeyStore(), new Cipher());
        $this->exporter = new AuditExporter(new AuditEntryReader($shredder));
    }

    public function testJsonExportIsCompleteAndStructured(): void
    {
        $json = $this->exporter->toJson([$this->entry(1, AuditAction::Create), $this->entry(2, AuditAction::Update)]);

        /** @var list<array<string, mixed>> $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $decoded);
        self::assertSame(1, $decoded[0]['sequence_no']);
        self::assertSame('create', $decoded[0]['action']);
        self::assertArrayHasKey('changes', $decoded[0]);
        self::assertSame(['old' => 'draft', 'new' => 'open'], $decoded[0]['changes']['status']);
    }

    public function testCsvExportIsFlatMetadataOnly(): void
    {
        $csv = $this->exporter->toCsv([$this->entry(1, AuditAction::Create)]);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        self::assertStringStartsWith('id,stream_id,sequence_no,occurred_at,action', $lines[0]);
        self::assertStringNotContainsString('changes', $lines[0], 'CSV is flat metadata, never the nested detail');
        self::assertStringContainsString(',rechnung,1,', $lines[1]);
    }

    private function entry(int $seq, AuditAction $action): AuditEntry
    {
        return new AuditEntry(
            id: 'id-'.$seq,
            streamId: 'rechnung',
            sequenceNo: $seq,
            occurredAt: '2026-06-01T10:00:00.000000Z',
            action: $action,
            entityClass: 'App\\Entity\\Rechnung',
            entityId: '42',
            actorType: ActorType::User,
            actorId: 'user-1',
            actorLabel: 'Alice',
            changes: ['status' => ['old' => 'draft', 'new' => 'open']],
            context: ['route' => 'edit'],
            previousHash: str_repeat('0', 64),
            hash: str_repeat('a', 64),
        );
    }

    private function nullKeyStore(): KeyStoreInterface
    {
        return new class implements KeyStoreInterface {
            public function deriveEncryptionKey(array $subjectIds): string
            {
                throw new \LogicException('not expected');
            }

            public function deriveDecryptionKey(array $subjectIds): ?string
            {
                return null;
            }

            public function shred(string $subjectId): void
            {
            }

            public function isShredded(string $subjectId): bool
            {
                return false;
            }

            public function hasSubject(string $subjectId): bool
            {
                return false;
            }

            public function placeLegalHold(string $subjectId): void
            {
            }

            public function liftLegalHold(string $subjectId): void
            {
            }

            public function isUnderLegalHold(string $subjectId): bool
            {
                return false;
            }
        };
    }
}
