<?php

declare(strict_types=1);

namespace K5n\Git2Ics;

/**
 * Release metadata for the git2ics CLI.
 */
final class Version
{
    public const VERSION = '1.0.0';

    /**
     * PRODID value identifying this tool as the generator of a calendar.
     *
     * RFC 5545 requires PRODID on every VCALENDAR, and php-icalendar-core does
     * not add one automatically, so CalendarBuilder must set it explicitly.
     */
    public static function productId(): string
    {
        return '-//k5n.us//git2ics ' . self::VERSION . '//EN';
    }
}
