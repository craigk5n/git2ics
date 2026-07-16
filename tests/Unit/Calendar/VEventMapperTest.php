<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use K5n\Git2Ics\Calendar\DescriptionFormatter;
use K5n\Git2Ics\Calendar\IcsDateFormatter;
use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Calendar\VEventMapper;
use K5n\Git2Ics\Clock\FrozenClock;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Exception\Git2IcsException;
use K5n\Git2Ics\Git\CommitUrlBuilder;
use K5n\Git2Ics\Tests\CommitFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VEventMapper::class)]
final class VEventMapperTest extends TestCase
{
    private const NOW = '2026-07-16T15:11:22+00:00';

    private function mapper(
        TimezoneMode $tz = TimezoneMode::Floating,
        DateSource $dateSource = DateSource::Author,
        ?string $duration = null,
        bool $reproducible = false,
        ?string $remote = null,
    ): VEventMapper {
        $urlBuilder = new CommitUrlBuilder($remote);

        return new VEventMapper(
            uidGenerator: new UidGenerator('git2ics.k5n.us'),
            descriptionFormatter: new DescriptionFormatter($urlBuilder),
            dateFormatter: new IcsDateFormatter(),
            urlBuilder: $urlBuilder,
            clock: FrozenClock::at(self::NOW),
            dateSource: $dateSource,
            timezoneMode: $tz,
            duration: $duration,
            reproducible: $reproducible,
        );
    }

    private function propertyValue(\Icalendar\Component\VEvent $event, string $name): ?string
    {
        return $event->getProperty($name)?->getValue()->getRawValue();
    }

    public function testComponentIsAVevent(): void
    {
        $event = $this->mapper()->map(CommitFactory::create());

        self::assertSame('VEVENT', $event->getName());
    }

    public function testUidIsTheStableCommitUid(): void
    {
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7');

        self::assertSame(
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7@git2ics.k5n.us',
            $this->propertyValue($this->mapper()->map($commit), 'UID'),
        );
    }

    public function testSummaryIsTheCommitSubject(): void
    {
        $commit = CommitFactory::create(rawBody: "fix: the subject\n\nbody");

        self::assertSame('fix: the subject', $this->propertyValue($this->mapper()->map($commit), 'SUMMARY'));
    }

    /**
     * `git commit --allow-empty-message` is legal and would otherwise produce a
     * blank calendar entry. Presentation fallback lives here, not in the VO.
     */
    public function testEmptySubjectFallsBackToTheShortSha(): void
    {
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7', rawBody: '');

        self::assertSame('afb87de', $this->propertyValue($this->mapper()->map($commit), 'SUMMARY'));
    }

    public function testDtStartUsesFloatingLocalTimeByDefault(): void
    {
        $commit = CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00');

        self::assertSame(
            '20260101T100000',
            $this->propertyValue($this->mapper()->map($commit), 'DTSTART'),
            'a 10:00 commit must read 10:00',
        );
    }

    public function testDtStartInUtcMode(): void
    {
        $commit = CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00');
        $mapper = $this->mapper(tz: TimezoneMode::Utc);

        self::assertSame('20260101T010000Z', $this->propertyValue($mapper->map($commit), 'DTSTART'));
    }

    public function testDtStartUsesTheAuthorDateByDefault(): void
    {
        $commit = CommitFactory::create(
            authorDate: '2026-01-01T10:00:00+00:00',
            committerDate: '2026-06-06T06:06:06+00:00',
        );

        self::assertSame('20260101T100000', $this->propertyValue($this->mapper()->map($commit), 'DTSTART'));
    }

    public function testDtStartCanUseTheCommitterDate(): void
    {
        $commit = CommitFactory::create(
            authorDate: '2026-01-01T10:00:00+00:00',
            committerDate: '2026-06-06T06:06:06+00:00',
        );
        $mapper = $this->mapper(dateSource: DateSource::Committer);

        self::assertSame('20260606T060606', $this->propertyValue($mapper->map($commit), 'DTSTART'));
    }

    /**
     * The headline decision: a commit is an instant, so the event has no duration.
     * RFC 5545 §3.6.1 — DTSTART with no DTEND is zero-duration.
     */
    public function testZeroDurationEventHasNeitherDtEndNorDuration(): void
    {
        $event = $this->mapper()->map(CommitFactory::create());

        self::assertNull($event->getProperty('DTEND'));
        self::assertNull($event->getProperty('DURATION'));
        self::assertNotNull($event->getProperty('DTSTART'));
    }

    public function testDurationFlagEmitsDtEnd(): void
    {
        $commit = CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00');
        $mapper = $this->mapper(duration: 'PT15M');

        self::assertSame('20260101T101500', $this->propertyValue($mapper->map($commit), 'DTEND'));
    }

    public function testDurationRespectsUtcMode(): void
    {
        $commit = CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00');
        $mapper = $this->mapper(tz: TimezoneMode::Utc, duration: 'PT1H');

        self::assertSame('20260101T020000Z', $this->propertyValue($mapper->map($commit), 'DTEND'));
    }

    /**
     * DTSTAMP is generation time so it works as RFC 5545's update tiebreaker at
     * equal SEQUENCE. It is always UTC (§3.8.7.2), regardless of --tz.
     */
    public function testDtStampIsGenerationTimeInUtc(): void
    {
        $event = $this->mapper()->map(CommitFactory::create());

        self::assertSame('20260716T151122Z', $this->propertyValue($event, 'DTSTAMP'));
    }

    public function testDtStampIsUtcEvenInFloatingMode(): void
    {
        $stamp = $this->propertyValue($this->mapper(tz: TimezoneMode::Floating)->map(CommitFactory::create()), 'DTSTAMP');

        self::assertNotNull($stamp);
        self::assertStringEndsWith('Z', $stamp, 'DTSTAMP must always be UTC');
    }

    public function testReproducibleModePinsDtStampToTheCommitDate(): void
    {
        $commit = CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00');
        $mapper = $this->mapper(reproducible: true);

        self::assertSame('20260101T010000Z', $this->propertyValue($mapper->map($commit), 'DTSTAMP'));
    }

    public function testDescriptionIsPresentWhenTheCommitHasABody(): void
    {
        $commit = CommitFactory::create(rawBody: "subject\n\nexplanatory body");

        $description = $this->propertyValue($this->mapper()->map($commit), 'DESCRIPTION');

        self::assertNotNull($description);
        self::assertStringContainsString('explanatory body', $description);
    }

    public function testUrlIsSetWhenTheRemoteIsRecognised(): void
    {
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7');
        $mapper = $this->mapper(remote: 'git@github.com:craigk5n/ilibgo.git');

        self::assertSame(
            'https://github.com/craigk5n/ilibgo/commit/afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            $this->propertyValue($mapper->map($commit), 'URL'),
        );
    }

    public function testUrlIsAbsentWithoutARecognisedRemote(): void
    {
        self::assertNull($this->mapper()->map(CommitFactory::create())->getProperty('URL'));
    }

    /**
     * The library validates nothing on write, so the mapper's output must
     * independently satisfy VEvent::validate() (DTSTAMP + UID required).
     */
    public function testMappedEventPassesTheLibrarysOwnValidation(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mapper()->map(CommitFactory::create())->validate();
    }

    /**
     * Pins the constructor defaults themselves: every other test passes these
     * explicitly, which would leave a flipped default undetected.
     */
    public function testDefaultsAreZeroDurationAndGenerationTimeDtStamp(): void
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

        $event = $mapper->map(CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00'));

        self::assertNull($event->getProperty('DTEND'), 'duration defaults to none');
        self::assertSame(
            '20260716T151122Z',
            $this->propertyValue($event, 'DTSTAMP'),
            'reproducible defaults to false, so DTSTAMP is generation time',
        );
    }

    public function testInvalidDurationIsReportedClearly(): void
    {
        $mapper = $this->mapper(duration: 'fifteen minutes');

        $this->expectException(Git2IcsException::class);
        $this->expectExceptionMessageMatches('/invalid duration/i');

        $mapper->map(CommitFactory::create());
    }
}
