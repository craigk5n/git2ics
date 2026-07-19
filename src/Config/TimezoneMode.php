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
     * Default. Absolute UTC instants (RFC 5545 §3.3.5 form 2, `Z` suffix).
     *
     * git records an author date with an explicit offset — `14:46:02+09:00` —
     * so it hands us an unambiguous instant. Converting to UTC preserves that
     * instant exactly and loses nothing; a client that knows the reader's
     * timezone renders it back at the right wall clock.
     *
     * TZID would preserve the original *zone*, but git only gives an offset,
     * and a zone name cannot be derived from an offset (+09:00 could be Tokyo,
     * Seoul or Yakutsk). UTC is the most faithful form actually available.
     */
    case Utc = 'utc';

    /**
     * Floating local time (form 1): no `Z`, no TZID, rendered in whatever
     * timezone the viewer happens to be in.
     *
     * Keeps the wall clock the commit was made at, but *discards* the offset
     * git provided, so the event is no longer a fixed instant. Two commits made
     * at "09:00" in different timezones become indistinguishable.
     */
    case Floating = 'local';
}
