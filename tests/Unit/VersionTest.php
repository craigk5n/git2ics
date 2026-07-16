<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit;

use K5n\Git2Ics\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version::class)]
final class VersionTest extends TestCase
{
    /**
     * RFC 5545 §3.7.3 gives PRODID the FPI shape `-//owner//product//language`.
     */
    public function testProductIdFollowsTheFormalPublicIdentifierShape(): void
    {
        self::assertMatchesRegularExpression('#^-//.+//.+//EN$#', Version::productId());
    }

    public function testProductIdCarriesTheVersion(): void
    {
        self::assertStringContainsString(Version::VERSION, Version::productId());
    }

    public function testProductIdNamesTheTool(): void
    {
        self::assertStringContainsString('git2ics', Version::productId());
    }
}
