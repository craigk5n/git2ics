<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Icalendar\Component\VEvent;
use K5n\Git2Ics\Clock\ClockInterface;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Exception\Git2IcsException;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Git\CommitUrlBuilder;

/**
 * Maps a commit to a VEVENT.
 *
 * By default the event has no duration: a commit is an instant, and RFC 5545
 * §3.6.1 makes a DATE-TIME DTSTART with no DTEND exactly that. `--duration`
 * exists for clients that render zero-duration events poorly; the target
 * clients (WebCalendar, agenticdaisy-calendar) do not.
 */
final readonly class VEventMapper implements ComponentMapper
{
    use MapsCommitTimestamps;

    /**
     * @param string|null  $duration   An ISO 8601 duration (e.g. PT15M), or null
     *                                 for a zero-duration event.
     * @param list<string> $categories CATEGORIES to apply; empty emits none.
     */
    public function __construct(
        private UidGenerator $uidGenerator,
        private DescriptionFormatter $descriptionFormatter,
        private IcsDateFormatter $dateFormatter,
        private CommitUrlBuilder $urlBuilder,
        private ClockInterface $clock,
        private DateSource $dateSource,
        private TimezoneMode $timezoneMode,
        private ?string $duration = null,
        private bool $reproducible = false,
        private array $categories = [],
    ) {
    }

    #[\Override]
    public function map(Commit $commit): VEvent
    {
        $event = new VEvent();

        $event->setUid($this->uidGenerator->forCommit($commit))
            ->setDtStamp($this->dtStamp($commit))
            ->setDtStart($this->dtStart($commit))
            ->setSummary($this->summary($commit));

        $description = $this->descriptionFormatter->format($commit);
        if ($description !== '') {
            $event->setDescription($description);
        }

        $dtEnd = $this->dtEnd($commit);
        if ($dtEnd !== null) {
            $event->setDtEnd($dtEnd);
        }

        $url = $this->urlBuilder->forSha($commit->sha);
        if ($url !== null) {
            $event->setUrl($url);
        }

        if ($this->categories !== []) {
            $event->setCategories(...$this->categories);
        }

        return $event;
    }

    /**
     * Null — the default — leaves the event zero-duration.
     */
    private function dtEnd(Commit $commit): ?string
    {
        if ($this->duration === null) {
            return null;
        }

        $start = DateTimeImmutable::createFromInterface($this->dateSource->of($commit));

        return $this->dateFormatter->format($start->add($this->interval($this->duration)), $this->timezoneMode);
    }

    private function interval(string $duration): DateInterval
    {
        try {
            return new DateInterval($duration);
        } catch (Exception $e) {
            throw new Git2IcsException(sprintf('invalid duration "%s"', $duration), previous: $e);
        }
    }
}
