<?php

declare(strict_types=1);

namespace Opus125\AuditBundle\Tests;

use Opus125\AuditBundle\Opus125AuditBundle;
use PHPUnit\Framework\TestCase;

final class Opus125AuditBundleTest extends TestCase
{
    public function testBundleCanBeInstantiated(): void
    {
        self::assertInstanceOf(Opus125AuditBundle::class, new Opus125AuditBundle());
    }

    public function testBundlePathIsAnExistingDirectory(): void
    {
        self::assertDirectoryExists(new Opus125AuditBundle()->getPath());
    }
}
