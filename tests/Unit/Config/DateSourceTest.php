<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Config;

use K5n\Git2Ics\Config\ComponentType;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Tests\CommitFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateSource::class)]
#[CoversClass(TimezoneMode::class)]
#[CoversClass(ComponentType::class)]
final class DateSourceTest extends TestCase
{
    public function testAuthorSelectsTheAuthorDate(): void
    {
        $commit = CommitFactory::create(
            authorDate: '2026-01-01T10:00:00+00:00',
            committerDate: '2026-06-06T06:06:06+00:00',
        );

        self::assertSame('2026-01-01T10:00:00+00:00', DateSource::Author->of($commit)->format('c'));
    }

    public function testCommitterSelectsTheCommitterDate(): void
    {
        $commit = CommitFactory::create(
            authorDate: '2026-01-01T10:00:00+00:00',
            committerDate: '2026-06-06T06:06:06+00:00',
        );

        self::assertSame('2026-06-06T06:06:06+00:00', DateSource::Committer->of($commit)->format('c'));
    }

    public function testSelectedDateKeepsItsOriginalOffset(): void
    {
        $commit = CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00');

        self::assertSame('+09:00', DateSource::Author->of($commit)->format('P'));
    }

    /**
     * The backing values are the CLI flag arguments, so they are part of the
     * public interface: `--date=author`, `--tz=local`, `--component=vevent`.
     */
    public function testBackingValuesMatchTheDocumentedCliArguments(): void
    {
        self::assertSame('author', DateSource::Author->value);
        self::assertSame('committer', DateSource::Committer->value);

        self::assertSame('local', TimezoneMode::Floating->value);
        self::assertSame('utc', TimezoneMode::Utc->value);

        self::assertSame('vevent', ComponentType::Vevent->value);
        self::assertSame('vjournal', ComponentType::Vjournal->value);
    }

    public function testEnumsAreConstructableFromTheirCliArgument(): void
    {
        self::assertSame(DateSource::Author, DateSource::from('author'));
        self::assertSame(TimezoneMode::Floating, TimezoneMode::from('local'));
        self::assertSame(ComponentType::Vjournal, ComponentType::from('vjournal'));
    }
}
