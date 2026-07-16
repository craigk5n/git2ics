<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use Icalendar\Exception\ValidationException;
use Icalendar\Parser\Parser;
use Icalendar\Writer\Writer;
use K5n\Git2Ics\Calendar\CalendarBuilder;
use K5n\Git2Ics\Calendar\ComponentMapper;
use K5n\Git2Ics\Calendar\DescriptionFormatter;
use K5n\Git2Ics\Calendar\IcsDateFormatter;
use K5n\Git2Ics\Calendar\StreamingIcsWriter;
use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Calendar\VEventMapper;
use K5n\Git2Ics\Clock\FrozenClock;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Exception\OutputWriteException;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Git\CommitUrlBuilder;
use K5n\Git2Ics\Tests\CommitFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamingIcsWriter::class)]
final class StreamingIcsWriterTest extends TestCase
{
    private const NOW = '2026-07-16T15:11:22+00:00';

    private function mapper(): VEventMapper
    {
        $urlBuilder = new CommitUrlBuilder(null);

        return new VEventMapper(
            uidGenerator: new UidGenerator('git2ics.k5n.us'),
            descriptionFormatter: new DescriptionFormatter($urlBuilder),
            dateFormatter: new IcsDateFormatter(),
            urlBuilder: $urlBuilder,
            clock: FrozenClock::at(self::NOW),
            dateSource: DateSource::Author,
            timezoneMode: TimezoneMode::Floating,
        );
    }

    /**
     * @param iterable<Commit> $commits
     */
    private function stream(iterable $commits, ?string $name = 'test-repo', ?ComponentMapper $mapper = null): string
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);

        (new StreamingIcsWriter())->write($handle, $commits, $mapper ?? $this->mapper(), $name);

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * The important one. Two output paths exist — the streaming writer for the
     * CLI and CalendarBuilder+Writer for in-memory use — and they must not
     * drift. Byte equality is the only assertion that actually guarantees it.
     */
    public function testStreamedOutputIsByteIdenticalToTheNonStreamingPath(): void
    {
        $commits = [
            CommitFactory::create(sha: 'aaa11111111111111111111111111111111111a1', rawBody: "first\n\nbody one"),
            CommitFactory::create(sha: 'bbb22222222222222222222222222222222222b2', rawBody: 'second: with, comma; and \\ backslash'),
            CommitFactory::create(sha: 'ccc33333333333333333333333333333333333c3', rawBody: 'third: 日本語 🎉'),
        ];

        $streamed = $this->stream($commits);
        $materialised = (new Writer())->write((new CalendarBuilder($this->mapper(), 'test-repo'))->build($commits))
            . "\r\n"; // the streaming writer terminates the final line

        self::assertSame($materialised, $streamed);
    }

    public function testStreamedOutputParsesInStrictMode(): void
    {
        $calendar = (new Parser(Parser::STRICT))->parse($this->stream([
            CommitFactory::create(sha: 'aaa11111111111111111111111111111111111a1'),
            CommitFactory::create(sha: 'bbb22222222222222222222222222222222222b2'),
        ]));

        self::assertCount(2, $calendar->getComponents('VEVENT'));
    }

    public function testStructureIsWellFormed(): void
    {
        $ics = $this->stream([CommitFactory::create()]);

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString('PRODID:-//k5n.us//git2ics', $ics);
        self::assertStringContainsString("VERSION:2.0\r\n", $ics);
        self::assertStringContainsString("X-WR-CALNAME:test-repo\r\n", $ics);
    }

    public function testCalendarNameIsOmittedWhenNull(): void
    {
        self::assertStringNotContainsString('X-WR-CALNAME', $this->stream([CommitFactory::create()], null));
    }

    public function testNoCommitsStillProducesAValidCalendar(): void
    {
        $ics = $this->stream([]);

        self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
        self::assertStringContainsString('END:VCALENDAR', $ics);
        self::assertStringNotContainsString('BEGIN:VEVENT', $ics);

        $calendar = (new Parser(Parser::STRICT))->parse($ics);
        self::assertSame([], $calendar->getComponents('VEVENT'));
    }

    /**
     * The whole point of streaming: a generator is consumed lazily, so peak
     * memory is one commit rather than the entire history.
     */
    public function testConsumesAGeneratorWithoutMaterialisingIt(): void
    {
        $mapped = 0;
        $commits = (function () use (&$mapped): \Generator {
            foreach (range(1, 50) as $i) {
                $mapped++;
                yield CommitFactory::create(sha: str_pad((string) $i, 40, '0', STR_PAD_LEFT));
            }
        })();

        $ics = $this->stream($commits);

        self::assertSame(50, $mapped);
        self::assertSame(50, substr_count($ics, 'BEGIN:VEVENT'));
    }

    public function testEveryLineRespectsTheOctetLimit(): void
    {
        $ics = $this->stream([CommitFactory::create(rawBody: str_repeat('日', 60))]);

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
        }
    }

    /**
     * A failed write must surface as a distinct type, because the caller treats
     * the two causes oppositely: a closed stdout (`git2ics | head`) is ordinary
     * and silent, a failed write to a named file is an error. The writer cannot
     * tell them apart, so it reports the fact and lets the caller decide.
     *
     * A read-only stream fails every write, exactly as a closed pipe does.
     */
    public function testWriteFailureIsReportedAsAnOutputWriteException(): void
    {
        $unwritable = fopen('php://memory', 'r');
        self::assertIsResource($unwritable);

        $this->expectException(OutputWriteException::class);
        $this->expectExceptionMessageMatches('/could not write/i');

        (new StreamingIcsWriter())->write($unwritable, [CommitFactory::create()], $this->mapper(), 'test-repo');
    }

    /**
     * The library's writer validates nothing, so the streaming path must refuse
     * to emit an invalid component rather than write it out silently.
     */
    public function testInvalidComponentIsRejectedRatherThanWritten(): void
    {
        $brokenMapper = new class () implements ComponentMapper {
            #[\Override]
            public function map(Commit $commit): \Icalendar\Component\VEvent
            {
                return new \Icalendar\Component\VEvent(); // no UID, no DTSTAMP
            }
        };

        $this->expectException(ValidationException::class);

        $this->stream([CommitFactory::create()], 'test-repo', $brokenMapper);
    }
}
