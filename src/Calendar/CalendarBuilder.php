<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use Icalendar\Component\VCalendar;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Version;

/**
 * Assembles commits into a VCALENDAR.
 *
 * Pure: no I/O, no clock of its own. The mapper decides what each commit becomes,
 * so VEVENT and VJOURNAL output differ only by which mapper is injected.
 */
final readonly class CalendarBuilder
{
    private const ICALENDAR_VERSION = '2.0';

    public function __construct(
        private ComponentMapper $mapper,
        private ?string $calendarName = null,
    ) {
    }

    /**
     * @param iterable<Commit> $commits Accepts a Generator: a large repository is
     *                                  never held in memory all at once.
     */
    public function build(iterable $commits): VCalendar
    {
        $calendar = new VCalendar();

        // Required by RFC 5545 §3.6. The library adds neither automatically and
        // its writer validates nothing, so omitting these fails silently.
        $calendar->setProductId(Version::productId())
            ->setVersion(self::ICALENDAR_VERSION);

        if ($this->calendarName !== null) {
            $calendar->setCalendarName($this->calendarName);
        }

        foreach ($commits as $commit) {
            $calendar->addComponent($this->mapper->map($commit));
        }

        return $calendar;
    }
}
