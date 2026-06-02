<?php

declare(strict_types=1);

namespace Opus125\DataContracts\Tests\Subject;

use Opus125\DataContracts\Subject\SubjectReference;
use PHPUnit\Framework\TestCase;

final class SubjectReferenceTest extends TestCase
{
    public function testStringableIsCanonicalToken(): void
    {
        self::assertSame('App\\Contact#42', (string) new SubjectReference('App\\Contact', '42'));
    }

    public function testEquality(): void
    {
        $a = new SubjectReference('App\\Contact', '42');
        $b = new SubjectReference('App\\Contact', '42');
        $c = new SubjectReference('App\\Contact', '7');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    public function testRejectsEmptyParts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SubjectReference('App\\Contact', '');
    }
}
