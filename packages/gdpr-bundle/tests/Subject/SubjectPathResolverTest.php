<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Tests\Subject;

use Opus125\GdprBundle\Registry\PersonalDataRegistry;
use Opus125\GdprBundle\Subject\SubjectPath;
use Opus125\GdprBundle\Subject\SubjectPathResolver;
use Opus125\GdprBundle\Tests\Fixtures\Contact;
use Opus125\GdprBundle\Tests\Fixtures\CyclicNode;
use Opus125\GdprBundle\Tests\Fixtures\Household;
use Opus125\GdprBundle\Tests\Fixtures\Order;
use Opus125\GdprBundle\Tests\Fixtures\OrderLine;
use PHPUnit\Framework\TestCase;

final class SubjectPathResolverTest extends TestCase
{
    private SubjectPathResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SubjectPathResolver(new PersonalDataRegistry());
    }

    public function testSubjectClassResolvesToItselfWithEmptyPath(): void
    {
        $paths = $this->resolver->pathsFrom(Contact::class);

        self::assertCount(1, $paths);
        self::assertSame(Contact::class, $paths[0]->subjectClass);
        self::assertSame([], $paths[0]->path);
    }

    public function testDirectLink(): void
    {
        $paths = $this->resolver->pathsTo(Order::class, Contact::class);

        self::assertCount(1, $paths);
        self::assertSame(['contact'], $paths[0]->path);
    }

    public function testMultiLevelLink(): void
    {
        $paths = $this->resolver->pathsTo(OrderLine::class, Contact::class);

        self::assertCount(1, $paths);
        self::assertSame(['order', 'contact'], $paths[0]->path);
    }

    public function testSharedDataHasMultiplePaths(): void
    {
        $paths = $this->resolver->pathsTo(Household::class, Contact::class);

        $properties = array_map(static fn (SubjectPath $p): string => $p->path[0], $paths);
        sort($properties);
        self::assertSame(['primary', 'secondary'], $properties);
    }

    public function testCyclicDeclarationTerminatesWithNoSubject(): void
    {
        self::assertSame([], $this->resolver->pathsFrom(CyclicNode::class));
    }
}
