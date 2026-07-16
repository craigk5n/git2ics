<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use Icalendar\Component\VCalendar;
use Icalendar\Writer\ComponentWriter;
use Icalendar\Writer\ContentLineWriter;
use K5n\Git2Ics\Exception\OutputWriteException;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Version;

/**
 * Writes a calendar to a stream one component at a time.
 *
 * The library's Writer serialises a whole VCalendar to a single string, which
 * means every commit in a repository is held in memory as a component object and
 * again as text. This walks the commit generator instead, so peak memory is one
 * commit regardless of history size.
 *
 * Output is byte-identical to Writer::write(CalendarBuilder::build(...)) — see
 * StreamingIcsWriterTest, which pins the two paths together.
 */
final readonly class StreamingIcsWriter
{
    private const ICALENDAR_VERSION = '2.0';

    public function __construct(
        private ComponentWriter $componentWriter = new ComponentWriter(),
        private ContentLineWriter $contentLineWriter = new ContentLineWriter(75, true),
    ) {
    }

    /**
     * @param resource         $stream
     * @param iterable<Commit> $commits
     */
    public function write($stream, iterable $commits, ComponentMapper $mapper, ?string $calendarName = null): void
    {
        $validator = new CalendarValidator();

        $this->emit($stream, 'BEGIN:VCALENDAR');

        foreach ($this->headerProperties($calendarName) as $line) {
            $this->emit($stream, $line);
        }

        foreach ($commits as $commit) {
            $component = $mapper->map($commit);

            // The library's writer validates nothing, so a component missing its
            // required UID or DTSTAMP would stream out silently.
            $validator->validate($component);

            $this->emit($stream, $this->componentWriter->write($component));
        }

        $this->emit($stream, 'END:VCALENDAR');
    }

    /**
     * Reuses VCalendar + PropertyWriter rather than hand-formatting, so the
     * header cannot drift from what the non-streaming path produces.
     *
     * @return list<string>
     */
    private function headerProperties(?string $calendarName): array
    {
        $calendar = new VCalendar();
        $calendar->setProductId(Version::productId())->setVersion(self::ICALENDAR_VERSION);

        if ($calendarName !== null) {
            $calendar->setCalendarName($calendarName);
        }

        $propertyWriter = $this->componentWriter->getPropertyWriter();

        return array_map(
            fn ($property): string => $propertyWriter->write($property),
            array_values($calendar->getProperties()),
        );
    }

    /**
     * @param resource $stream
     *
     * @throws OutputWriteException if the stream rejects the write, most often
     *                              because a downstream reader closed the pipe
     */
    private function emit($stream, string $content): void
    {
        $written = @fwrite($stream, $this->contentLineWriter->write($content) . "\r\n");

        if ($written === false) {
            throw new OutputWriteException('could not write to the output stream');
        }
    }
}
