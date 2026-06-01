<?php

declare(strict_types=1);

/*
 * Worker for ConcurrencyTest: appends $count entries to stream $stream, each in
 * its own transaction, using its own database connection. Run in parallel by
 * several processes to prove the per-stream advisory lock keeps sequence_no
 * gapless under contention.
 *
 * Usage: php concurrent_append_worker.php <stream> <count>
 */

use Opus\AuditBundle\Enum\ActorType;
use Opus\AuditBundle\Enum\AuditAction;
use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\PostgresAdvisoryLock;
use Opus\AuditBundle\Integrity\PostgresHashChain;
use Opus\AuditBundle\Model\PendingAuditEntry;
use Opus\AuditBundle\Support\CanonicalTimestamp;
use Opus\AuditBundle\Tests\Support\OrmFactory;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$stream = (string) ($argv[1] ?? 'concurrent');
$count = (int) ($argv[2] ?? 10);
$worker = (string) ($argv[3] ?? 'w');

$connection = OrmFactory::createConnection();
$encoder = new CanonicalJsonEncoder();
$chain = new PostgresHashChain($connection, new PostgresAdvisoryLock($connection), new HashCalculator($encoder), $encoder);

for ($i = 0; $i < $count; ++$i) {
    $connection->transactional(static function () use ($chain, $stream, $worker, $i): void {
        $chain->append(new PendingAuditEntry(
            id: Uuid::v7()->toRfc4122(),
            streamId: $stream,
            occurredAt: CanonicalTimestamp::format(new DateTimeImmutable()),
            action: AuditAction::Create,
            entityClass: 'Worker',
            entityId: $worker.'-'.$i,
            actorType: ActorType::System,
            actorId: null,
            actorLabel: null,
            changes: ['n' => [null, $i]],
            context: ['worker' => $worker],
        ));
    });
}

exit(0);
