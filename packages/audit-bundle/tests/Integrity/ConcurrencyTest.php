<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Integrity;

use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\ChainVerifier;
use Opus\AuditBundle\Integrity\HashCalculator;
use Opus\AuditBundle\Integrity\PostgresAdvisoryLock;
use Opus\AuditBundle\Integrity\PostgresHashChain;
use Opus\AuditBundle\Model\Schema;
use Opus\AuditBundle\Tests\Support\DatabaseTestCase;

/**
 * The §8 "Nebenläufigkeit" gate: several OS processes append to the *same*
 * stream concurrently; the per-stream advisory lock must keep `sequence_no`
 * gapless and the chain intact.
 */
final class ConcurrencyTest extends DatabaseTestCase
{
    public function testConcurrentAppendsToOneStreamStayGaplessAndValid(): void
    {
        $workers = 4;
        $perWorker = 20;
        $total = $workers * $perWorker;
        $stream = 'concurrent';

        $script = \dirname(__DIR__).'/Support/concurrent_append_worker.php';
        $processes = [];

        for ($w = 0; $w < $workers; ++$w) {
            $process = proc_open(
                [\PHP_BINARY, $script, $stream, (string) $perWorker, 'w'.$w],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $failures = [];
        foreach ($processes as [$process, $pipes]) {
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if (0 !== $exit) {
                $failures[] = $stderr;
            }
        }

        self::assertSame([], $failures, 'all worker processes must succeed: '.implode("\n", $failures));

        // Sequence numbers are exactly 1..total with no gaps or duplicates.
        /** @var list<int> $sequences */
        $sequences = array_map(intval(...), self::$connection->fetchFirstColumn(
            \sprintf('SELECT sequence_no FROM %s WHERE stream_id = :s ORDER BY sequence_no ASC', Schema::ENTRY_TABLE),
            ['s' => $stream],
        ));

        self::assertSame(range(1, $total), $sequences, 'sequence numbers must be gapless and unique');

        // And the resulting chain verifies end to end.
        $encoder = new CanonicalJsonEncoder();
        $verifier = new ChainVerifier(
            new PostgresHashChain(self::$connection, new PostgresAdvisoryLock(self::$connection), new HashCalculator($encoder), $encoder),
            self::$connection,
        );

        self::assertTrue($verifier->verifyFull($stream)->valid);
    }
}
