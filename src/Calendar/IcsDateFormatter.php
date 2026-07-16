<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use K5n\Git2Ics\Config\TimezoneMode;

/**
 * Renders DATE-TIME values in the two RFC 5545 forms git2ics emits.
 *
 * php-icalendar-core's setters take bare strings and validate nothing, so every
 * date is formatted correctly here or not at all.
 */
final class IcsDateFormatter
{
    /** RFC 5545 §3.3.5 form 1: floating, no zone marker. */
    private const FLOATING = 'Ymd\THis';

    /** Form 2: UTC, Z-suffixed. */
    private const UTC = 'Ymd\THis\Z';

    public function format(DateTimeInterface $dateTime, TimezoneMode $mode): string
    {
        return match ($mode) {
            TimezoneMode::Floating => $this->floating($dateTime),
            TimezoneMode::Utc => $this->utc($dateTime),
        };
    }

    /**
     * The wall clock the commit was made at, carried across verbatim.
     */
    public function floating(DateTimeInterface $dateTime): string
    {
        return $dateTime->format(self::FLOATING);
    }

    /**
     * The absolute instant. Always used for DTSTAMP, which RFC 5545 §3.8.7.2
     * requires to be UTC regardless of how DTSTART is rendered.
     */
    public function utc(DateTimeInterface $dateTime): string
    {
        return DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC);
    }
}
