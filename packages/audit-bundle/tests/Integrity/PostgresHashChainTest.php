<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Integrity;

use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\Exception\MissingAuditTransactionException;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\PostgresAdvisoryLock;
use Opus\AuditBundle\Integrity\PostgresHashChain;
use Opus\AuditBundle\Model\PendingAuditEntry;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgresHashChainTest extends DatabaseTestCase
{
    private PostgresHashChain $chain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chain = new PostgresHashChain(
            self::$connection,
            new PostgresAdvisoryLock(self::$connection),
            new HashCalculator(new CanonicalJsonEncoder()),
            new CanonicalJsonEncoder(),
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function entry(string $stream, AuditAction $action, array $changes): PendingAuditEntry
    {
        return new PendingAuditEntry(
            id: Uuid::v7()->toRfc4122(),
            streamId: $stream,
            occurredAt: '2026-06-01T10:00:00.000000Z',
            action: $action,
            entityClass: 'App\\Entity\\Rechnung',
            entityId: '42',
            actorType: ActorType::User,
            actorId: 'user-1',
            actorLabel: 'Alice',
            changes: $changes,
            context: ['route' => 'app_invoice_edit'],
        );
    }

    public function testAppendAssignsGaplessChainedSequences(): void
    {
        self::$connection->transactional(function (): void {
            $a = $this->chain->append($this->entry('rechnung', AuditAction::Create, ['status' => [null, 'draft']]));
            $b = $this->chain->append($this->entry('rechnung', AuditAction::Update, ['status' => ['draft', 'open']]));
            $c = $this->chain->append($this->entry('rechnung', AuditAction::Update, ['betrag' => [100, 200]]));

            self::assertSame([1, 2, 3], [$a->sequenceNo, $b->sequenceNo, $c->sequenceNo]);
            self::assertSame(HashCalculator::GENESIS_HASH, $a->previousHash);
            self::assertSame($a->hash, $b->previousHash);
            self::assertSame($b->hash, $c->previousHash);
        });
    }

    public function testSeparateStreamsAreIndependent(): void
    {
        self::$connection->transactional(function (): void {
            $a = $this->chain->append($this->entry('stream-a', AuditAction::Create, ['x' => [1, 2]]));
            $b = $this->chain->append($this->entry('stream-b', AuditAction::Create, ['y' => [3, 4]]));

            self::assertSame(1, $a->sequenceNo);
            self::assertSame(1, $b->sequenceNo);
            self::assertSame(HashCalculator::GENESIS_HASH, $b->previousHash);
        });
    }

    public function testVerifyReportsIntactChain(): void
    {
        $this->appendSampleChain('rechnung', 5);

        $result = $this->chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);

        self::assertTrue($result->valid);
        self::assertSame(5, $result->checkedCount);
        self::assertNull($result->brokenAtSequence);
    }

    public function testVerifyDetectsTamperedContent(): void
    {
        $this->appendSampleChain('rechnung', 3);

        // An attacker with DB write access rewrites the changes of entry #2.
        self::$connection->executeStatement(
            \sprintf('UPDATE %s SET changes = :c WHERE stream_id = :s AND sequence_no = 2', Schema::ENTRY_TABLE),
            ['c' => '{"status":["open","cancelled"]}', 's' => 'rechnung'],
        );

        $result = $this->chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);

        self::assertFalse($result->valid);
        self::assertSame(2, $result->brokenAtSequence);
    }

    public function testVerifyDetectsTamperedHash(): void
    {
        $this->appendSampleChain('rechnung', 3);

        self::$connection->executeStatement(
            \sprintf('UPDATE %s SET hash = :h WHERE stream_id = :s AND sequence_no = 2', Schema::ENTRY_TABLE),
            ['h' => str_repeat('a', 64), 's' => 'rechnung'],
        );

        $result = $this->chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);

        self::assertFalse($result->valid);
        self::assertSame(2, $result->brokenAtSequence);
    }

    public function testVerifyDetectsBrokenLink(): void
    {
        $this->appendSampleChain('rechnung', 3);

        self::$connection->executeStatement(
            \sprintf('UPDATE %s SET previous_hash = :h WHERE stream_id = :s AND sequence_no = 3', Schema::ENTRY_TABLE),
            ['h' => str_repeat('b', 64), 's' => 'rechnung'],
        );

        $result = $this->chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);

        self::assertFalse($result->valid);
        self::assertSame(3, $result->brokenAtSequence);
    }

    public function testVerifyDetectsDeletedEntryAsGap(): void
    {
        $this->appendSampleChain('rechnung', 4);

        self::$connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE stream_id = :s AND sequence_no = 3', Schema::ENTRY_TABLE),
            ['s' => 'rechnung'],
        );

        $result = $this->chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);

        self::assertFalse($result->valid);
        self::assertSame(4, $result->brokenAtSequence);
    }

    public function testSegmentVerificationAnchoredOnHeadHash(): void
    {
        $links = $this->appendSampleChain('rechnung', 5);

        // Verify only [3,5], anchored on entry #2's hash (as a seal would record).
        $result = $this->chain->verify('rechnung', 3, 5, $links[2]->hash);

        self::assertTrue($result->valid);
        self::assertSame(3, $result->checkedCount);
    }

    public function testFloatAndIntFidelitySurvivesVerification(): void
    {
        // 1.0 (float) must not be silently stored as 1 (int): the hash depends
        // on the distinction, so a naive json column would break verification.
        self::$connection->transactional(function (): void {
            $this->chain->append($this->entry('rechnung', AuditAction::Update, [
                'rate' => [1.0, 1.5],
                'count' => [1, 2],
                'label' => ['1.0', '2'],
            ]));
        });

        $result = $this->chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);

        self::assertTrue($result->valid, $result->message);
    }

    public function testAppendOutsideTransactionIsRejected(): void
    {
        $this->expectException(MissingAuditTransactionException::class);
        $this->chain->append($this->entry('rechnung', AuditAction::Create, ['x' => [1, 2]]));
    }

    /**
     * @return array<int, \Opus\AuditBundle\Integrity\AppendedLink> indexed by sequence number
     */
    private function appendSampleChain(string $stream, int $count): array
    {
        return self::$connection->transactional(function () use ($stream, $count): array {
            $links = [];
            for ($i = 1; $i <= $count; ++$i) {
                $links[$i] = $this->chain->append($this->entry($stream, AuditAction::Update, ['n' => [$i - 1, $i]]));
            }

            return $links;
        });
    }
}
