<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Clock;

use DateTimeImmutable;
use K5n\Git2Ics\Clock\FrozenClock;
use K5n\Git2Ics\Clock\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
#[CoversClass(FrozenClock::class)]
final class ClockTest extends TestCase
{
    public function testSystemClockReportsTheCurrentTime(): void
    {
        $before = new DateTimeImmutable('-2 seconds');
        $now = (new SystemClock())->now();
        $after = new DateTimeImmutable('+2 seconds');

        self::assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }

    /**
     * DTSTAMP must be UTC, so the clock that feeds it reports UTC.
     */
    public function testSystemClockIsInUtc(): void
    {
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }

    public function testFrozenClockAlwaysReportsTheSameInstant(): void
    {
        $clock = FrozenClock::at('2026-07-16T15:11:22+00:00');

        self::assertSame('2026-07-16T15:11:22+00:00', $clock->now()->format('c'));
        self::assertSame($clock->now()->format('c'), $clock->now()->format('c'));
    }

    public function testFrozenClockAcceptsAnInstanceDirectly(): void
    {
        $instant = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertSame($instant, (new FrozenClock($instant))->now());
    }
}
