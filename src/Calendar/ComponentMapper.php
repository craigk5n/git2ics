<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use Icalendar\Component\ComponentInterface;
use K5n\Git2Ics\Git\Commit;

/**
 * Turns one commit into one iCalendar component.
 */
interface ComponentMapper
{
    public function map(Commit $commit): ComponentInterface;
}
