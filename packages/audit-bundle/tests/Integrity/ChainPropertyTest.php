<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Integrity;

use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\PostgresAdvisoryLock;
use Opus\AuditBundle\Integrity\PostgresHashChain;
use Opus\AuditBundle\Model\PendingAuditEntry;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * §8 integrity property test: after N random operations the chain verifies, and
 * a targeted tampering of any single entry is detected.
 */
final class ChainPropertyTest extends DatabaseTestCase
{
    public function testRandomOperationsProduceAVerifiableChain(): void
    {
        $chain = $this->chain();
        $n = 60;

        self::$connection->transactional(function () use ($chain, $n): void {
            for ($i = 1; $i <= $n; ++$i) {
                $chain->append($this->randomEntry($i));
            }
        });

        $result = $chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);

        self::assertTrue($result->valid, $result->message);
        self::assertSame($n, $result->checkedCount);
    }

    public function testTamperingAnyEntryIsDetected(): void
    {
        $chain = $this->chain();
        $n = 40;

        self::$connection->transactional(function () use ($chain, $n): void {
            for ($i = 1; $i <= $n; ++$i) {
                $chain->append($this->randomEntry($i));
            }
        });

        $victim = random_int(1, $n);
        self::$connection->executeStatement(
            \sprintf('UPDATE %s SET changes = :c WHERE stream_id = :s AND sequence_no = :seq', Schema::ENTRY_TABLE),
            ['c' => '{"tampered":true}', 's' => 'rechnung', 'seq' => $victim],
        );

        $result = $chain->verify('rechnung', 1, null, HashCalculator::GENESIS_HASH);

        self::assertFalse($result->valid);
        self::assertSame($victim, $result->brokenAtSequence, 'verification must point at the tampered entry');
    }

    private function chain(): PostgresHashChain
    {
        $encoder = new CanonicalJsonEncoder();

        return new PostgresHashChain(
            self::$connection,
            new PostgresAdvisoryLock(self::$connection),
            new HashCalculator($encoder),
            $encoder,
        );
    }

    private function randomEntry(int $i): PendingAuditEntry
    {
        $actions = [AuditAction::Create, AuditAction::Update, AuditAction::Delete];

        return new PendingAuditEntry(
            id: Uuid::v7()->toRfc4122(),
            streamId: 'rechnung',
            occurredAt: '2026-06-01T10:00:00.000000Z',
            action: $actions[array_rand($actions)],
            entityClass: 'App\\Entity\\Rechnung',
            entityId: (string) random_int(1, 1000),
            actorType: ActorType::User,
            actorId: 'user-'.random_int(1, 9),
            actorLabel: null,
            changes: $this->randomChanges(),
            context: ['seq' => $i, 'r' => bin2hex(random_bytes(3))],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function randomChanges(): array
    {
        $pool = [
            'status' => ['draft', 'open', 'paid'][array_rand(['draft', 'open', 'paid'])],
            'betrag' => random_int(0, 100000),
            'rate' => random_int(0, 1000) / 100.0, // mix in floats
            'flag' => 0 === random_int(0, 1),
            'note' => 0 === random_int(0, 3) ? null : bin2hex(random_bytes(4)),
        ];

        $changes = [];
        foreach ($pool as $field => $value) {
            if (0 === random_int(0, 1)) {
                $changes[$field] = ['old' => null, 'new' => $value];
            }
        }

        return [] === $changes ? ['status' => ['old' => null, 'new' => 'draft']] : $changes;
    }
}
