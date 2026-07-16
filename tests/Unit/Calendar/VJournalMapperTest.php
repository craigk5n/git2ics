<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use K5n\Git2Ics\Calendar\DescriptionFormatter;
use K5n\Git2Ics\Calendar\IcsDateFormatter;
use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Calendar\VJournalMapper;
use K5n\Git2Ics\Clock\FrozenClock;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Git\CommitUrlBuilder;
use K5n\Git2Ics\Tests\CommitFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VJournalMapper::class)]
final class VJournalMapperTest extends TestCase
{
    private const NOW = '2026-07-16T15:11:22+00:00';

    private function mapper(
        TimezoneMode $tz = TimezoneMode::Floating,
        DateSource $dateSource = DateSource::Author,
        bool $reproducible = false,
        ?string $remote = null,
    ): VJournalMapper {
        $urlBuilder = new CommitUrlBuilder($remote);

        return new VJournalMapper(
            uidGenerator: new UidGenerator('git2ics.k5n.us'),
            descriptionFormatter: new DescriptionFormatter($urlBuilder),
            dateFormatter: new IcsDateFormatter(),
            urlBuilder: $urlBuilder,
            clock: FrozenClock::at(self::NOW),
            dateSource: $dateSource,
            timezoneMode: $tz,
            reproducible: $reproducible,
        );
    }

    private function propertyValue(\Icalendar\Component\VJournal $journal, string $name): ?string
    {
        return $journal->getProperty($name)?->getValue()->getRawValue();
    }

    public function testComponentIsAVjournal(): void
    {
        self::assertSame('VJOURNAL', $this->mapper()->map(CommitFactory::create())->getName());
    }

    public function testUidMatchesTheVeventUidForTheSameCommit(): void
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

    public function testEmptySubjectFallsBackToTheShortSha(): void
    {
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7', rawBody: '');

        self::assertSame('afb87de', $this->propertyValue($this->mapper()->map($commit), 'SUMMARY'));
    }

    public function testDtStartUsesFloatingLocalTimeByDefault(): void
    {
        $commit = CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00');

        self::assertSame('20260101T100000', $this->propertyValue($this->mapper()->map($commit), 'DTSTART'));
    }

    public function testDtStartCanUseTheCommitterDate(): void
    {
        $commit = CommitFactory::create(
            authorDate: '2026-01-01T10:00:00+00:00',
            committerDate: '2026-06-06T06:06:06+00:00',
        );

        self::assertSame(
            '20260606T060606',
            $this->propertyValue($this->mapper(dateSource: DateSource::Committer)->map($commit), 'DTSTART'),
        );
    }

    /**
     * A VJOURNAL has no duration in RFC 5545 — which is exactly why it models a
     * commit so well.
     */
    public function testJournalHasNoEndOrDuration(): void
    {
        $journal = $this->mapper()->map(CommitFactory::create());

        self::assertNull($journal->getProperty('DTEND'));
        self::assertNull($journal->getProperty('DURATION'));
    }

    public function testDtStampIsGenerationTimeInUtc(): void
    {
        self::assertSame(
            '20260716T151122Z',
            $this->propertyValue($this->mapper()->map(CommitFactory::create()), 'DTSTAMP'),
        );
    }

    public function testReproducibleModePinsDtStampToTheCommitDate(): void
    {
        $commit = CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00');

        self::assertSame(
            '20260101T010000Z',
            $this->propertyValue($this->mapper(reproducible: true)->map($commit), 'DTSTAMP'),
        );
    }

    public function testDescriptionCarriesTheCommitBody(): void
    {
        $commit = CommitFactory::create(rawBody: "subject\n\nexplanatory body");

        $description = $this->propertyValue($this->mapper()->map($commit), 'DESCRIPTION');

        self::assertNotNull($description);
        self::assertStringContainsString('explanatory body', $description);
    }

    /**
     * VJournal exposes no setUrl(), unlike VEvent, so URL is added as a generic
     * property. RFC 5545 permits URL on a VJOURNAL.
     */
    public function testUrlIsSetDespiteTheLibraryHavingNoVjournalUrlSetter(): void
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
     * Pins the constructor default for $reproducible, which every other test
     * passes explicitly.
     */
    public function testDtStampDefaultsToGenerationTime(): void
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

        $journal = $mapper->map(CommitFactory::create(authorDate: '2026-01-01T10:00:00+09:00'));

        self::assertSame('20260716T151122Z', $this->propertyValue($journal, 'DTSTAMP'));
    }

    public function testMappedJournalPassesTheLibrarysOwnValidation(): void
    {
        $this->expectNotToPerformAssertions();

        $this->mapper()->map(CommitFactory::create())->validate();
    }
}
