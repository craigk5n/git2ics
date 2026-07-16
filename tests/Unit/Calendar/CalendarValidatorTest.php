<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use Icalendar\Component\VCalendar;
use Icalendar\Component\VEvent;
use Icalendar\Exception\ValidationException;
use K5n\Git2Ics\Calendar\CalendarValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * php-icalendar-core's VCalendar::validate() checks PRODID and VERSION on the
 * calendar itself and never recurses, so VEvent::validate() is never reached and
 * a malformed VEVENT ships silently. This validator closes that gap.
 */
#[CoversClass(CalendarValidator::class)]
final class CalendarValidatorTest extends TestCase
{
    private CalendarValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CalendarValidator();
    }

    private function validCalendar(): VCalendar
    {
        $calendar = new VCalendar();
        $calendar->setProductId('-//k5n.us//git2ics//EN')->setVersion('2.0');

        return $calendar;
    }

    private function validEvent(): VEvent
    {
        $event = new VEvent();
        $event->setUid('abc@git2ics.k5n.us')
            ->setDtStamp('20260716T151122Z')
            ->setDtStart('20260101T100000');

        return $event;
    }

    public function testAValidCalendarPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $calendar = $this->validCalendar();
        $calendar->addComponent($this->validEvent());

        $this->validator->validate($calendar);
    }

    public function testEmptyCalendarPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate($this->validCalendar());
    }

    public function testMissingProdIdIsRejected(): void
    {
        $calendar = new VCalendar();
        $calendar->setVersion('2.0');

        $this->expectException(ValidationException::class);

        $this->validator->validate($calendar);
    }

    /**
     * The case the library misses entirely: the calendar is well formed, but a
     * child component is not.
     */
    public function testInvalidChildComponentIsRejected(): void
    {
        $calendar = $this->validCalendar();

        $broken = new VEvent();
        $broken->setDtStart('20260101T100000'); // no UID, no DTSTAMP
        $calendar->addComponent($broken);

        $this->expectException(ValidationException::class);

        $this->validator->validate($calendar);
    }

    public function testValidationReachesEveryChildNotJustTheFirst(): void
    {
        $calendar = $this->validCalendar();
        $calendar->addComponent($this->validEvent());
        $calendar->addComponent($this->validEvent());

        $broken = new VEvent();
        $broken->setDtStart('20260101T100000');
        $calendar->addComponent($broken);

        $this->expectException(ValidationException::class);

        $this->validator->validate($calendar);
    }

    /**
     * Recursion must be arbitrarily deep — a VALARM inside a VEVENT, say.
     */
    public function testValidationRecursesIntoNestedComponents(): void
    {
        $calendar = $this->validCalendar();

        $event = $this->validEvent();
        $broken = new VEvent();
        $broken->setDtStart('20260101T100000');
        $event->addComponent($broken);
        $calendar->addComponent($event);

        $this->expectException(ValidationException::class);

        $this->validator->validate($calendar);
    }
}
