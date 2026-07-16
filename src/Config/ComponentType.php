<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Config;

/**
 * Which iCalendar component each commit becomes.
 */
enum ComponentType: string
{
    /** Default. A zero-duration event at the commit timestamp. */
    case Vevent = 'vevent';

    /**
     * Semantically the closest match to a commit — dated text with no duration.
     * Usable because git2ics targets WebCalendar, which supports VJOURNAL;
     * Google and Apple Calendar ignore it entirely.
     */
    case Vjournal = 'vjournal';
}
