<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Config;

use DateInterval;
use K5n\Git2Ics\Config\DurationSpec;
use K5n\Git2Ics\Exception\UsageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tested directly rather than only through ArgumentParser: the parser rejects
 * empty values before they reach here, so going through it would leave this
 * class's own guards unexercised while appearing covered.
 */
#[CoversClass(DurationSpec::class)]
final class DurationSpecTest extends TestCase
{
    #[DataProvider('validProvider')]
    public function testNormalisation(string $spec, string $expected): void
    {
        self::assertSame($expected, DurationSpec::toIso8601($spec));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validProvider(): iterable
    {
        yield 'minutes' => ['15m', 'PT15M'];
        yield 'hours' => ['1h', 'PT1H'];
        yield 'seconds' => ['30s', 'PT30S'];
        yield 'hours and minutes' => ['1h30m', 'PT1H30M'];
        yield 'all units' => ['2h15m30s', 'PT2H15M30S'];
        yield 'minutes beyond an hour' => ['90m', 'PT90M'];
        yield 'uppercase shorthand' => ['15M', 'PT15M'];
        yield 'surrounding whitespace' => ['  15m  ', 'PT15M'];
        yield 'iso passthrough' => ['PT15M', 'PT15M'];
        yield 'iso lowercase' => ['pt1h30m', 'PT1H30M'];
        yield 'iso with days' => ['P1D', 'P1D'];
    }

    /**
     * The output must be something DateInterval actually accepts — the mapper
     * feeds it straight in.
     */
    #[DataProvider('validProvider')]
    public function testOutputIsAcceptedByDateInterval(string $spec, string $expected): void
    {
        $interval = new DateInterval(DurationSpec::toIso8601($spec));

        self::assertSame($expected, DurationSpec::toIso8601($spec));
        self::assertInstanceOf(DateInterval::class, $interval);
    }

    public function testShorthandDurationsProduceTheExpectedElapsedTime(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');

        $end = $start->add(new DateInterval(DurationSpec::toIso8601('1h30m')));

        self::assertSame('2026-01-01T11:30:00+00:00', $end->format('c'));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidSpecIsRejected(string $spec): void
    {
        $this->expectException(UsageException::class);

        DurationSpec::toIso8601($spec);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'words' => ['fifteen minutes'];
        yield 'bare number' => ['15'];
        yield 'unknown unit' => ['15d'];
        yield 'units out of order' => ['30m1h'];
        yield 'negative' => ['-15m'];
        yield 'decimal' => ['1.5h'];
    }

    /**
     * The regex makes every component optional, so a zero duration parses
     * cleanly and has to be rejected explicitly. A zero-length event is also
     * exactly what omitting --duration already gives you.
     */
    #[DataProvider('zeroProvider')]
    public function testZeroDurationIsRejected(string $spec): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/zero/i');

        DurationSpec::toIso8601($spec);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zeroProvider(): iterable
    {
        yield 'zero minutes' => ['0m'];
        yield 'zero hours' => ['0h'];
        yield 'zero seconds' => ['0s'];
        yield 'all zero' => ['0h0m0s'];
    }

    public function testEmptySpecReportsItselfClearly(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/empty/i');

        DurationSpec::toIso8601('');
    }
}
