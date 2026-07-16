<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use Icalendar\Component\ComponentInterface;

/**
 * Validates a component tree from the root down.
 *
 * php-icalendar-core's VCalendar::validate() checks only PRODID and VERSION on
 * the calendar itself and does not recurse, so VEvent::validate() is never
 * reached. Combined with Writer::write() performing no validation at all, a
 * VEVENT missing its required UID or DTSTAMP would otherwise be written out
 * without complaint.
 *
 * @throws \Icalendar\Exception\ValidationException on the first invalid component
 */
final class CalendarValidator
{
    public function validate(ComponentInterface $component): void
    {
        // validate() is declared on the concrete component classes but on
        // neither ComponentInterface nor AbstractComponent, and GenericComponent
        // has no validation rules at all — so there is no type that guarantees
        // the method exists.
        if (method_exists($component, 'validate')) {
            $component->validate();
        }

        foreach ($component->getComponents() as $child) {
            $this->validate($child);
        }
    }
}
