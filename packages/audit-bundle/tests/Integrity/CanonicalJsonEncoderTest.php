<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests\Integrity;

use Opus125\AuditBundle\Integrity\CanonicalJsonEncoder;
use Opus125\AuditBundle\Integrity\Exception\NonCanonicalizableValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonEncoderTest extends TestCase
{
    private CanonicalJsonEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new CanonicalJsonEncoder();
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    #[DataProvider('keyOrderPairs')]
    public function testObjectKeyOrderDoesNotAffectOutput(array $a, array $b): void
    {
        self::assertSame($this->encoder->encode($a), $this->encoder->encode($b));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function keyOrderPairs(): iterable
    {
        yield 'flat' => [['b' => 1, 'a' => 2], ['a' => 2, 'b' => 1]];
        yield 'nested' => [
            ['z' => ['y' => 1, 'x' => 2], 'a' => 3],
            ['a' => 3, 'z' => ['x' => 2, 'y' => 1]],
        ];
        yield 'deeply nested' => [
            ['outer' => ['inner' => ['c' => 1, 'b' => 2, 'a' => 3]]],
            ['outer' => ['inner' => ['a' => 3, 'c' => 1, 'b' => 2]]],
        ];
    }

    public function testObjectKeysAreSortedByByteValue(): void
    {
        self::assertSame(
            '{"A":1,"a":2,"b":3,"é":4}',
            $this->encoder->encode(['b' => 3, 'é' => 4, 'a' => 2, 'A' => 1]),
        );
    }

    public function testListsPreserveOrder(): void
    {
        self::assertSame('[3,1,2]', $this->encoder->encode([3, 1, 2]));
    }

    public function testListOrderIsSignificant(): void
    {
        self::assertNotSame(
            $this->encoder->encode([1, 2, 3]),
            $this->encoder->encode([3, 2, 1]),
        );
    }

    public function testEmptyArrayEncodesAsList(): void
    {
        self::assertSame('[]', $this->encoder->encode([]));
    }

    public function testEmptyStdClassEncodesAsObject(): void
    {
        self::assertSame('{}', $this->encoder->encode(new \stdClass()));
    }

    public function testIntegerKeyedNonListIsForcedToObjectAndSorted(): void
    {
        // A non-list array whose keys would re-sort into a list must still
        // encode as an object, not silently become a JSON array.
        self::assertSame(
            '{"0":"b","1":"a"}',
            $this->encoder->encode([1 => 'a', 0 => 'b']),
        );
    }

    public function testScalarTypesArePreserved(): void
    {
        self::assertSame('null', $this->encoder->encode(null));
        self::assertSame('true', $this->encoder->encode(true));
        self::assertSame('false', $this->encoder->encode(false));
        self::assertSame('42', $this->encoder->encode(42));
        self::assertSame('-7', $this->encoder->encode(-7));
        self::assertSame('"hello"', $this->encoder->encode('hello'));
    }

    public function testFloatKeepsZeroFractionAndIsDistinctFromInt(): void
    {
        self::assertSame('1.0', $this->encoder->encode(1.0));
        self::assertSame('1', $this->encoder->encode(1));
        self::assertNotSame($this->encoder->encode(1.0), $this->encoder->encode(1));
    }

    public function testStringEscapingIsStableAndUnicodeStaysRaw(): void
    {
        self::assertSame('"a/b"', $this->encoder->encode('a/b'));
        self::assertSame('"a\\"b"', $this->encoder->encode('a"b'));
        self::assertSame('"a\\\\b"', $this->encoder->encode('a\\b'));
        self::assertSame('"a\nb"', $this->encoder->encode("a\nb"));
        self::assertSame('"Grüße"', $this->encoder->encode('Grüße'));
        self::assertSame('"😀"', $this->encoder->encode('😀'));
    }

    public function testDateTimeIsNormalisedToUtc(): void
    {
        $utc = new \DateTimeImmutable('2026-06-01 10:30:00.123456', new \DateTimeZone('UTC'));
        $vienna = new \DateTimeImmutable('2026-06-01 12:30:00.123456', new \DateTimeZone('Europe/Vienna'));

        self::assertSame('"2026-06-01T10:30:00.123+00:00"', $this->encoder->encode($utc));
        // Same instant, different zone → identical canonical bytes.
        self::assertSame($this->encoder->encode($utc), $this->encoder->encode($vienna));
    }

    public function testMutableDateTimeIsAlsoSupported(): void
    {
        $dt = new \DateTime('2026-06-01 10:30:00.000000', new \DateTimeZone('UTC'));

        self::assertSame('"2026-06-01T10:30:00.000+00:00"', $this->encoder->encode($dt));
    }

    public function testBackedEnumCollapsesToValue(): void
    {
        self::assertSame('"active"', $this->encoder->encode(CanonicalFixtureStatus::Active));
        self::assertSame('7', $this->encoder->encode(CanonicalFixturePriority::High));
    }

    public function testUnitEnumCollapsesToName(): void
    {
        self::assertSame('"Red"', $this->encoder->encode(CanonicalFixtureColor::Red));
    }

    public function testJsonSerializableIsExpanded(): void
    {
        $value = new readonly class implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['b' => 1, 'a' => 2];
            }
        };

        self::assertSame('{"a":2,"b":1}', $this->encoder->encode($value));
    }

    public function testStdClassIsTreatedAsSortedObject(): void
    {
        $value = new \stdClass();
        $value->b = 1;
        $value->a = 2;

        self::assertSame('{"a":2,"b":1}', $this->encoder->encode($value));
    }

    public function testStringableIsRendered(): void
    {
        $value = new readonly class implements \Stringable {
            public function __toString(): string
            {
                return 'rendered';
            }
        };

        self::assertSame('"rendered"', $this->encoder->encode($value));
    }

    public function testNonFiniteFloatsAreRejected(): void
    {
        $this->expectException(NonCanonicalizableValueException::class);
        $this->encoder->encode(['x' => \INF]);
    }

    public function testNanIsRejected(): void
    {
        $this->expectException(NonCanonicalizableValueException::class);
        $this->encoder->encode(\NAN);
    }

    public function testResourcesAreRejected(): void
    {
        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);

        try {
            $this->expectException(NonCanonicalizableValueException::class);
            $this->encoder->encode($handle);
        } finally {
            fclose($handle);
        }
    }

    public function testClosuresAreRejected(): void
    {
        $this->expectException(NonCanonicalizableValueException::class);
        $this->encoder->encode(static fn (): int => 1);
    }

    public function testArbitraryObjectsAreRejected(): void
    {
        $this->expectException(NonCanonicalizableValueException::class);
        $this->encoder->encode(new \ArrayObject([1, 2, 3]));
    }

    public function testExceptionMessageIncludesPath(): void
    {
        try {
            $this->encoder->encode(['a' => ['b' => [\INF]]]);
            self::fail('Expected exception was not thrown.');
        } catch (NonCanonicalizableValueException $e) {
            self::assertStringContainsString('$.a.b[0]', $e->getMessage());
        }
    }

    public function testEncodingIsIdempotent(): void
    {
        $payload = [
            'changes' => ['betrag' => ['old' => 100, 'new' => 200], 'status' => ['old' => 'draft', 'new' => 'open']],
            'actor' => ['id' => 'u-1', 'roles' => ['ROLE_USER', 'ROLE_ADMIN']],
            'when' => new \DateTimeImmutable('2026-01-01T00:00:00.000000Z'),
        ];

        self::assertSame($this->encoder->encode($payload), $this->encoder->encode($payload));
    }

    public function testGoldenVector(): void
    {
        // Locks the wire format. Changing this output is a BC break that
        // invalidates every previously computed audit hash.
        $payload = [
            'stream_id' => 'rechnung',
            'sequence_no' => 1,
            'occurred_at' => new \DateTimeImmutable('2026-06-01T10:30:00.123+00:00'),
            'action' => 'update',
            'changes' => [
                'status' => ['old' => 'draft', 'new' => 'open'],
                'betrag' => ['old' => 100, 'new' => 200],
            ],
            'rate' => 1.5,
            'flagged' => false,
            'note' => null,
        ];

        $expected = '{"action":"update","changes":{"betrag":{"new":200,"old":100},'
            .'"status":{"new":"open","old":"draft"}},"flagged":false,"note":null,'
            .'"occurred_at":"2026-06-01T10:30:00.123+00:00","rate":1.5,'
            .'"sequence_no":1,"stream_id":"rechnung"}';

        self::assertSame($expected, $this->encoder->encode($payload));
    }
}

enum CanonicalFixtureStatus: string
{
    case Active = 'active';
}

enum CanonicalFixturePriority: int
{
    case High = 7;
}

enum CanonicalFixtureColor
{
    case Red;
}
