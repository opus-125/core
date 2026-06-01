<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests\Integrity;

use Opus\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus\AuditBundle\Integrity\HashCalculator;
use PHPUnit\Framework\TestCase;

final class HashCalculatorTest extends TestCase
{
    private HashCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new HashCalculator(new CanonicalJsonEncoder());
    }

    public function testGenesisHashIs64Zeros(): void
    {
        self::assertSame(str_repeat('0', 64), $this->calculator->genesisHash());
        self::assertSame(HashCalculator::GENESIS_HASH, $this->calculator->genesisHash());
    }

    public function testHashIs64LowercaseHexCharacters(): void
    {
        $hash = $this->calculator->hash(['a' => 1], HashCalculator::GENESIS_HASH);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testHashIsDeterministic(): void
    {
        $entry = ['stream_id' => 's', 'sequence_no' => 1, 'changes' => ['a' => ['old' => 1, 'new' => 2]]];

        self::assertSame(
            $this->calculator->hash($entry, HashCalculator::GENESIS_HASH),
            $this->calculator->hash($entry, HashCalculator::GENESIS_HASH),
        );
    }

    public function testHashIsIndependentOfKeyOrder(): void
    {
        $prev = HashCalculator::GENESIS_HASH;

        self::assertSame(
            $this->calculator->hash(['a' => 1, 'b' => 2], $prev),
            $this->calculator->hash(['b' => 2, 'a' => 1], $prev),
        );
    }

    public function testDifferentPayloadProducesDifferentHash(): void
    {
        $prev = HashCalculator::GENESIS_HASH;

        self::assertNotSame(
            $this->calculator->hash(['a' => 1], $prev),
            $this->calculator->hash(['a' => 2], $prev),
        );
    }

    public function testDifferentPreviousHashProducesDifferentHash(): void
    {
        $entry = ['a' => 1];
        $other = str_repeat('a', 64);

        self::assertNotSame(
            $this->calculator->hash($entry, HashCalculator::GENESIS_HASH),
            $this->calculator->hash($entry, $other),
        );
    }

    public function testChainLinksDependOnPredecessor(): void
    {
        // A change to the first entry changes the second entry's input,
        // which is what makes the chain tamper-evident.
        $first = $this->calculator->hash(['n' => 1], HashCalculator::GENESIS_HASH);
        $tampered = $this->calculator->hash(['n' => 99], HashCalculator::GENESIS_HASH);

        $second = $this->calculator->hash(['n' => 2], $first);
        $secondAfterTamper = $this->calculator->hash(['n' => 2], $tampered);

        self::assertNotSame($second, $secondAfterTamper);
    }

    public function testInvalidPreviousHashIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->hash(['a' => 1], 'not-a-valid-hash');
    }

    public function testUppercasePreviousHashIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->hash(['a' => 1], strtoupper(str_repeat('a', 64)));
    }

    public function testVerifyAcceptsMatchingHash(): void
    {
        $entry = ['a' => 1, 'b' => 2];
        $hash = $this->calculator->hash($entry, HashCalculator::GENESIS_HASH);

        self::assertTrue($this->calculator->verify($entry, HashCalculator::GENESIS_HASH, $hash));
    }

    public function testVerifyRejectsTamperedEntry(): void
    {
        $entry = ['a' => 1];
        $hash = $this->calculator->hash($entry, HashCalculator::GENESIS_HASH);

        self::assertFalse($this->calculator->verify(['a' => 2], HashCalculator::GENESIS_HASH, $hash));
    }

    public function testVerifyRejectsMalformedHash(): void
    {
        self::assertFalse($this->calculator->verify(['a' => 1], HashCalculator::GENESIS_HASH, 'nope'));
    }

    public function testGoldenVector(): void
    {
        // Locks the full hash recipe (canonicalisation + concatenation order).
        // Recomputed independently: sha256( canonical_json(entry) || genesis ).
        $entry = ['action' => 'create', 'sequence_no' => 1, 'stream_id' => 'rechnung'];
        $canonical = '{"action":"create","sequence_no":1,"stream_id":"rechnung"}';
        $expected = hash('sha256', $canonical.HashCalculator::GENESIS_HASH);

        self::assertSame($expected, $this->calculator->hash($entry, HashCalculator::GENESIS_HASH));
        // Pin the literal value too, so a change in either layer is caught.
        self::assertSame(
            '6f27fcb5c37368e9076cd87ae465f83d8e0f74d0c0890de89236d7bcc88d3694',
            $this->calculator->hash($entry, HashCalculator::GENESIS_HASH),
        );
    }
}
