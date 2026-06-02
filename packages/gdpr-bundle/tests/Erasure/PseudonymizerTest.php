<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Erasure;

use Opus125\DataContracts\Subject\SubjectReference;
use Opus125\GdprBundle\Erasure\Pseudonymizer;
use PHPUnit\Framework\TestCase;

final class PseudonymizerTest extends TestCase
{
    public function testStableForSameSubjectAndField(): void
    {
        $p = new Pseudonymizer('secret');
        $subject = new SubjectReference('App\\Contact', '1');

        self::assertSame($p->pseudonym($subject, 'name'), $p->pseudonym($subject, 'name'));
        self::assertStringStartsWith('anon-', $p->pseudonym($subject, 'name'));
    }

    public function testDiffersBySubjectFieldAndSecret(): void
    {
        $a = new Pseudonymizer('secret-a');
        $b = new Pseudonymizer('secret-b');
        $one = new SubjectReference('App\\Contact', '1');
        $two = new SubjectReference('App\\Contact', '2');

        self::assertNotSame($a->pseudonym($one, 'name'), $a->pseudonym($two, 'name'));
        self::assertNotSame($a->pseudonym($one, 'name'), $a->pseudonym($one, 'email'));
        self::assertNotSame($a->pseudonym($one, 'name'), $b->pseudonym($one, 'name'));
    }

    public function testRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pseudonymizer('');
    }
}
