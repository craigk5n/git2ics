<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Config;

/**
 * How DTSTART renders a commit timestamp.
 *
 * Note this affects DTSTART only. DTSTAMP is always UTC — RFC 5545 §3.8.7.2
 * requires it.
 */
enum TimezoneMode: string
{
    /**
     * Default. Floating local time (RFC 5545 §3.3.5 form 1): no `Z`, no TZID,
     * rendered in the viewer's own timezone.
     *
     * A commit made at 09:00 reads as 09:00 — which is the question this tool
     * exists to answer. UTC would re-render that same commit at whatever hour
     * the viewer's offset implies, defeating the purpose.
     *
     * TZID + VTIMEZONE would be the strictly-correct third option, but
     * php-icalendar-core's setters write bare values and cannot attach the
     * TZID parameter.
     */
    case Floating = 'local';

    /** Absolute UTC instants (form 2, `Z` suffix). Correct across timezones. */
    case Utc = 'utc';
}
