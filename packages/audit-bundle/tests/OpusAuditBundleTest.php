<?php

declare(strict_types=1);

namespace Opus\AuditBundle\Tests;

use Opus\AuditBundle\OpusAuditBundle;
use PHPUnit\Framework\TestCase;

final class OpusAuditBundleTest extends TestCase
{
    public function testBundleCanBeInstantiated(): void
    {
        self::assertInstanceOf(OpusAuditBundle::class, new OpusAuditBundle());
    }

    public function testBundlePathIsAnExistingDirectory(): void
    {
        self::assertDirectoryExists(new OpusAuditBundle()->getPath());
    }
}
