<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use Icalendar\Component\VCalendar;
use K5n\Git2Ics\Calendar\CalendarBuilder;
use K5n\Git2Ics\Calendar\DescriptionFormatter;
use K5n\Git2Ics\Calendar\IcsDateFormatter;
use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Calendar\VEventMapper;
use K5n\Git2Ics\Calendar\VJournalMapper;
use K5n\Git2Ics\Clock\FrozenClock;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Git\CommitUrlBuilder;
use K5n\Git2Ics\Tests\CommitFactory;
use K5n\Git2Ics\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CalendarBuilder::class)]
final class CalendarBuilderTest extends TestCase
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

    private function journalMapper(): VJournalMapper
    {
        $urlBuilder = new CommitUrlBuilder(null);

        return new VJournalMapper(
            uidGenerator: new UidGenerator('git2ics.k5n.us'),
            descriptionFormatter: new DescriptionFormatter($urlBuilder),
            dateFormatter: new IcsDateFormatter(),
            urlBuilder: $urlBuilder,
            clock: FrozenClock::at(self::NOW),
            dateSource: DateSource::Author,
            timezoneMode: TimezoneMode::Floating,
        );
    }

    private function builder(?string $calendarName = null): CalendarBuilder
    {
        return new CalendarBuilder($this->mapper(), $calendarName);
    }

    private function propertyValue(VCalendar $calendar, string $name): ?string
    {
        return $calendar->getProperty($name)?->getValue()->getRawValue();
    }

    /**
     * Nothing in the library adds these, and Writer::write() does not validate,
     * so an unset PRODID would ship silently.
     */
    public function testProdIdIsSet(): void
    {
        $calendar = $this->builder()->build([CommitFactory::create()]);

        self::assertSame(Version::productId(), $this->propertyValue($calendar, 'PRODID'));
    }

    public function testVersionIs20(): void
    {
        $calendar = $this->builder()->build([CommitFactory::create()]);

        self::assertSame('2.0', $this->propertyValue($calendar, 'VERSION'));
    }

    public function testCalendarNameIsSetWhenProvided(): void
    {
        $calendar = $this->builder('ilibgo')->build([CommitFactory::create()]);

        self::assertSame('ilibgo', $calendar->getCalendarName());
    }

    public function testCalendarNameIsOmittedWhenNotProvided(): void
    {
        self::assertNull($this->builder()->build([CommitFactory::create()])->getCalendarName());
    }

    public function testEachCommitBecomesOneComponent(): void
    {
        $commits = [
            CommitFactory::create(sha: 'aaa11111111111111111111111111111111111a1'),
            CommitFactory::create(sha: 'bbb22222222222222222222222222222222222b2'),
            CommitFactory::create(sha: 'ccc33333333333333333333333333333333333c3'),
        ];

        $calendar = $this->builder()->build($commits);

        self::assertCount(3, $calendar->getComponents('VEVENT'));
    }

    public function testComponentsCarryTheirCommitUids(): void
    {
        $commits = [
            CommitFactory::create(sha: 'aaa11111111111111111111111111111111111a1'),
            CommitFactory::create(sha: 'bbb22222222222222222222222222222222222b2'),
        ];

        $uids = array_map(
            fn ($component): ?string => $component->getProperty('UID')?->getValue()->getRawValue(),
            $this->builder()->build($commits)->getComponents('VEVENT'),
        );

        self::assertSame([
            'aaa11111111111111111111111111111111111a1@git2ics.k5n.us',
            'bbb22222222222222222222222222222222222b2@git2ics.k5n.us',
        ], $uids);
    }

    /**
     * A range that matches nothing (--since=2099) is not an error: an empty but
     * structurally valid calendar is the right answer.
     */
    public function testNoCommitsStillProducesAValidCalendar(): void
    {
        $calendar = $this->builder()->build([]);

        self::assertSame([], $calendar->getComponents('VEVENT'));
        self::assertSame(Version::productId(), $this->propertyValue($calendar, 'PRODID'));
        self::assertSame('2.0', $this->propertyValue($calendar, 'VERSION'));
    }

    public function testBuildAcceptsAGeneratorSoLargeRepositoriesAreNotMaterialised(): void
    {
        $commits = (function (): \Generator {
            yield CommitFactory::create(sha: 'aaa11111111111111111111111111111111111a1');
            yield CommitFactory::create(sha: 'bbb22222222222222222222222222222222222b2');
        })();

        self::assertCount(2, $this->builder()->build($commits)->getComponents('VEVENT'));
    }

    public function testJournalMapperProducesVjournalComponents(): void
    {
        $builder = new CalendarBuilder($this->journalMapper(), null);

        $calendar = $builder->build([CommitFactory::create()]);

        self::assertCount(1, $calendar->getComponents('VJOURNAL'));
        self::assertSame([], $calendar->getComponents('VEVENT'));
    }

    public function testBuiltCalendarPassesTheLibrarysOwnValidation(): void
    {
        $this->expectNotToPerformAssertions();

        $this->builder()->build([CommitFactory::create()])->validate();
    }
}
