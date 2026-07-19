<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use Icalendar\Component\VCalendar;
use Icalendar\Parser\Parser;
use Icalendar\Writer\Writer;
use K5n\Git2Ics\Calendar\CalendarBuilder;
use K5n\Git2Ics\Calendar\DescriptionFormatter;
use K5n\Git2Ics\Calendar\IcsDateFormatter;
use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Calendar\VEventMapper;
use K5n\Git2Ics\Calendar\VJournalMapper;
use K5n\Git2Ics\Clock\FrozenClock;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Git\CommitUrlBuilder;
use K5n\Git2Ics\Tests\CommitFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The compliance proof.
 *
 * Every other test asserts our own assumptions back at us. These generate real
 * ICS bytes and read them back with php-icalendar-core's STRICT parser — an
 * independent implementation of the same RFC — so anything the writer mangles
 * surfaces here rather than in a calendar client.
 */
final class RoundTripTest extends TestCase
{
    private const NOW = '2026-07-16T15:11:22+00:00';

    private function buildIcs(Commit ...$commits): string
    {
        $urlBuilder = new CommitUrlBuilder(null);
        $mapper = new VEventMapper(
            uidGenerator: new UidGenerator('git2ics.k5n.us'),
            descriptionFormatter: new DescriptionFormatter($urlBuilder),
            dateFormatter: new IcsDateFormatter(),
            urlBuilder: $urlBuilder,
            clock: FrozenClock::at(self::NOW),
            dateSource: DateSource::Author,
            timezoneMode: TimezoneMode::Floating,
        );

        $calendar = (new CalendarBuilder($mapper, 'test-repo'))->build($commits);
        $calendar->validate();

        return (new Writer())->write($calendar);
    }

    private function roundTrip(Commit ...$commits): VCalendar
    {
        return (new Parser(Parser::STRICT))->parse($this->buildIcs(...$commits));
    }

    private function firstEventProperty(VCalendar $calendar, string $name): ?string
    {
        $events = $calendar->getComponents('VEVENT');
        self::assertNotEmpty($events, 'expected at least one VEVENT');

        return $events[0]->getProperty($name)?->getValue()->getRawValue();
    }

    public function testGeneratedCalendarParsesInStrictMode(): void
    {
        $calendar = $this->roundTrip(CommitFactory::create());

        self::assertCount(1, $calendar->getComponents('VEVENT'));
    }

    public function testAllCommitsSurviveTheRoundTrip(): void
    {
        $calendar = $this->roundTrip(
            CommitFactory::create(sha: 'aaa11111111111111111111111111111111111a1'),
            CommitFactory::create(sha: 'bbb22222222222222222222222222222222222b2'),
            CommitFactory::create(sha: 'ccc33333333333333333333333333333333333c3'),
        );

        self::assertCount(3, $calendar->getComponents('VEVENT'));
    }

    public function testCoreFieldsSurviveTheRoundTrip(): void
    {
        $commit = CommitFactory::create(
            sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            authorDate: '2026-01-01T10:00:00+09:00',
            rawBody: 'fix: a representative subject',
        );

        $calendar = $this->roundTrip($commit);

        self::assertSame(
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7@git2ics.k5n.us',
            $this->firstEventProperty($calendar, 'UID'),
        );
        self::assertSame('fix: a representative subject', $this->firstEventProperty($calendar, 'SUMMARY'));
        self::assertSame('20260716T151122Z', $this->firstEventProperty($calendar, 'DTSTAMP'));
        self::assertSame('20260101T100000', $this->firstEventProperty($calendar, 'DTSTART'));
    }

    /**
     * php-icalendar-core 1.1.5 promoted floating times to UTC on the way back
     * in when the host's date.timezone was UTC; 1.2.0 fixed it. This guards
     * against a regression: a floating DTSTART must come back without a `Z`.
     */
    public function testFloatingDtStartSurvivesTheRoundTrip(): void
    {
        $ics = $this->buildIcs(CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00'));
        self::assertStringContainsString("DTSTART:20260101T100000\r\n", $ics, 'our output is floating');

        $parsed = (new Parser(Parser::STRICT))->parse($ics);

        self::assertSame('20260101T100000', $this->firstEventProperty($parsed, 'DTSTART'));
    }

    public function testZeroDurationSurvivesTheRoundTrip(): void
    {
        $calendar = $this->roundTrip(CommitFactory::create());
        $event = $calendar->getComponents('VEVENT')[0];

        self::assertNull($event->getProperty('DTEND'));
        self::assertNull($event->getProperty('DURATION'));
    }

    public function testFloatingDtStartHasNoZoneMarkerInTheRawOutput(): void
    {
        $ics = $this->buildIcs(CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00'));

        self::assertStringContainsString('DTSTART:20260101T100000' . "\r\n", $ics);
    }

    /**
     * Content lines fold on octet boundaries. A naive folder splits a multi-byte
     * character in half and emits invalid UTF-8 — this is exactly the bug open
     * against golang-ical (issue #7), so it gets an explicit guard here.
     *
     * Each case is sized so that a fold lands mid-character: the 75-octet limit
     * minus "SUMMARY:" leaves 67 octets, which is not a multiple of 3 or 4.
     */
    #[DataProvider('multiByteSubjectProvider')]
    public function testMultiByteSubjectsSurviveLineFolding(string $subject): void
    {
        $ics = $this->buildIcs(CommitFactory::create(rawBody: $subject));

        self::assertTrue(
            mb_check_encoding($ics, 'UTF-8'),
            'folding must not split a multi-byte character',
        );

        $calendar = (new Parser(Parser::STRICT))->parse($ics);

        self::assertSame($subject, $this->firstEventProperty($calendar, 'SUMMARY'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multiByteSubjectProvider(): iterable
    {
        yield 'CJK, 3 bytes each' => [str_repeat('日', 40)];
        yield 'emoji, 4 bytes each' => [str_repeat('🎉', 30)];
        yield 'accented latin, 2 bytes each' => [str_repeat('é', 60)];
        yield 'mixed widths' => [str_repeat('a日é🎉', 20)];
        yield 'realistic mixed subject' => ['fix: 日本語のバグ修正 and 🎉 celebration — done'];
    }

    public function testLongAsciiSubjectSurvivesFolding(): void
    {
        $subject = 'fix: ' . str_repeat('a very long commit subject line ', 10);
        $calendar = $this->roundTrip(CommitFactory::create(rawBody: $subject));

        self::assertSame($subject, $this->firstEventProperty($calendar, 'SUMMARY'));
    }

    public function testFoldedLinesRespectTheOctetLimit(): void
    {
        $ics = $this->buildIcs(CommitFactory::create(rawBody: str_repeat('日', 40)));

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'content line exceeds 75 octets: ' . $line);
        }
    }

    /**
     * Commit subjects are full of characters that are special in an ICS TEXT
     * value. RFC 5545 §3.3.11 requires backslash, semicolon, comma and newline
     * to be escaped.
     */
    #[DataProvider('specialCharacterProvider')]
    public function testSpecialCharactersSurviveTheRoundTrip(string $subject): void
    {
        $calendar = $this->roundTrip(CommitFactory::create(rawBody: $subject));

        self::assertSame($subject, $this->firstEventProperty($calendar, 'SUMMARY'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function specialCharacterProvider(): iterable
    {
        yield 'comma' => ['fix: handle a, b and c'];
        yield 'semicolon' => ['fix: handle a; then b'];
        yield 'backslash' => ['fix: handle C:\\path\\to\\file'];
        yield 'all three at once' => ['fix: a,b; c\\d'];
        yield 'colon' => ['fix: ratio 16:9'];
        yield 'quotes' => ['fix: the "quoted" thing'];
        yield 'equals and params' => ['fix: set X=1;Y=2'];
    }

    public function testMultiLineBodySurvivesInTheDescription(): void
    {
        $commit = CommitFactory::create(rawBody: "subject\n\nfirst body line\nsecond body line");

        $description = $this->firstEventProperty($this->roundTrip($commit), 'DESCRIPTION');

        self::assertNotNull($description);
        self::assertStringContainsString("first body line\nsecond body line", $description);
    }

    public function testCalendarLevelPropertiesSurvive(): void
    {
        $calendar = $this->roundTrip(CommitFactory::create());

        self::assertSame('2.0', $calendar->getProperty('VERSION')?->getValue()->getRawValue());
        self::assertStringContainsString(
            'git2ics',
            (string) $calendar->getProperty('PRODID')?->getValue()->getRawValue(),
        );
        self::assertSame('test-repo', $calendar->getCalendarName());
    }

    public function testEmptyCalendarRoundTrips(): void
    {
        $calendar = $this->roundTrip();

        self::assertSame([], $calendar->getComponents('VEVENT'));
        self::assertSame('2.0', $calendar->getProperty('VERSION')?->getValue()->getRawValue());
    }

    public function testVjournalRoundTripsInStrictMode(): void
    {
        $urlBuilder = new CommitUrlBuilder(null);
        $mapper = new VJournalMapper(
            uidGenerator: new UidGenerator('git2ics.k5n.us'),
            descriptionFormatter: new DescriptionFormatter($urlBuilder),
            dateFormatter: new IcsDateFormatter(),
            urlBuilder: $urlBuilder,
            clock: FrozenClock::at(self::NOW),
            dateSource: DateSource::Author,
            timezoneMode: TimezoneMode::Floating,
        );

        $calendar = (new CalendarBuilder($mapper, 'test-repo'))->build([
            CommitFactory::create(rawBody: 'fix: 日本語 and 🎉'),
        ]);
        $calendar->validate();

        $ics = (new Writer())->write($calendar);
        $parsed = (new Parser(Parser::STRICT))->parse($ics);

        $journals = $parsed->getComponents('VJOURNAL');
        self::assertCount(1, $journals);
        self::assertSame(
            'fix: 日本語 and 🎉',
            $journals[0]->getProperty('SUMMARY')?->getValue()->getRawValue(),
        );
    }

    public function testOutputUsesCrlfLineEndings(): void
    {
        $ics = $this->buildIcs(CommitFactory::create());

        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $ics, 'every LF must be preceded by CR');
    }
}
