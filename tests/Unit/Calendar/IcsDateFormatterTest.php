<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use DateTimeImmutable;
use K5n\Git2Ics\Calendar\IcsDateFormatter;
use K5n\Git2Ics\Config\TimezoneMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IcsDateFormatter::class)]
final class IcsDateFormatterTest extends TestCase
{
    private IcsDateFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new IcsDateFormatter();
    }

    /**
     * Floating (RFC 5545 §3.3.5 form 1): the wall clock the commit was made at,
     * with no Z and no offset. A 10:00+09:00 commit must read 10:00.
     */
    public function testFloatingPreservesTheOriginalWallClock(): void
    {
        $dt = new DateTimeImmutable('2026-01-01T10:00:00+09:00');

        self::assertSame('20260101T100000', $this->formatter->floating($dt));
    }

    public function testFloatingIsUnaffectedByTheOffset(): void
    {
        $tokyo = new DateTimeImmutable('2026-01-01T10:00:00+09:00');
        $newYork = new DateTimeImmutable('2026-01-01T10:00:00-05:00');

        self::assertSame(
            $this->formatter->floating($tokyo),
            $this->formatter->floating($newYork),
            'floating time is the wall clock, regardless of zone',
        );
    }

    /** UTC (form 2): the absolute instant, Z-suffixed. */
    public function testUtcConvertsToTheAbsoluteInstant(): void
    {
        $dt = new DateTimeImmutable('2026-01-01T10:00:00+09:00');

        self::assertSame('20260101T010000Z', $this->formatter->utc($dt));
    }

    public function testUtcOfAnAlreadyUtcValue(): void
    {
        $dt = new DateTimeImmutable('2026-01-03T09:15:00+00:00');

        self::assertSame('20260103T091500Z', $this->formatter->utc($dt));
    }

    public function testUtcCrossingADayBoundary(): void
    {
        // 00:30 in Tokyo is the previous day in UTC.
        $dt = new DateTimeImmutable('2026-01-02T00:30:00+09:00');

        self::assertSame('20260101T153000Z', $this->formatter->utc($dt));
    }

    #[DataProvider('modeProvider')]
    public function testFormatDispatchesOnMode(TimezoneMode $mode, string $expected): void
    {
        $dt = new DateTimeImmutable('2026-01-01T10:00:00+09:00');

        self::assertSame($expected, $this->formatter->format($dt, $mode));
    }

    /**
     * @return iterable<string, array{TimezoneMode, string}>
     */
    public static function modeProvider(): iterable
    {
        yield 'floating keeps the wall clock' => [TimezoneMode::Floating, '20260101T100000'];
        yield 'utc converts the instant' => [TimezoneMode::Utc, '20260101T010000Z'];
    }

    public function testFloatingNeverEmitsAZoneMarker(): void
    {
        $dt = new DateTimeImmutable('2026-01-01T10:00:00+09:00');
        $formatted = $this->formatter->floating($dt);

        self::assertStringEndsNotWith('Z', $formatted);
        self::assertStringNotContainsString('+', $formatted);
    }

    public function testFormattedValuesMatchTheRfc5545Grammar(): void
    {
        $dt = new DateTimeImmutable('2026-01-01T10:00:00+09:00');

        self::assertMatchesRegularExpression('/^\d{8}T\d{6}$/', $this->formatter->floating($dt));
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $this->formatter->utc($dt));
    }
}
