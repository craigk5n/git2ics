<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use Icalendar\Component\VJournal;
use Icalendar\Property\GenericProperty;
use K5n\Git2Ics\Clock\ClockInterface;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Git\CommitUrlBuilder;

/**
 * Maps a commit to a VJOURNAL.
 *
 * Semantically the best fit: a journal entry is dated text with no duration,
 * which is precisely what a commit is. Viable because git2ics targets
 * WebCalendar, which supports VJOURNAL — Google and Apple Calendar ignore it.
 */
final readonly class VJournalMapper implements ComponentMapper
{
    use MapsCommitTimestamps;

    public function __construct(
        private UidGenerator $uidGenerator,
        private DescriptionFormatter $descriptionFormatter,
        private IcsDateFormatter $dateFormatter,
        private CommitUrlBuilder $urlBuilder,
        private ClockInterface $clock,
        private DateSource $dateSource,
        private TimezoneMode $timezoneMode,
        private bool $reproducible = false,
    ) {
    }

    #[\Override]
    public function map(Commit $commit): VJournal
    {
        $journal = new VJournal();

        $journal->setUid($this->uidGenerator->forCommit($commit))
            ->setDtStamp($this->dtStamp($commit))
            ->setDtStart($this->dtStart($commit))
            ->setSummary($this->summary($commit));

        $description = $this->descriptionFormatter->format($commit);
        if ($description !== '') {
            $journal->setDescription($description);
        }

        $url = $this->urlBuilder->forSha($commit->sha);
        if ($url !== null) {
            // RFC 5545 permits URL on a VJOURNAL, but unlike VEvent the library
            // exposes no setUrl() for it, so add the property directly.
            $journal->addProperty(GenericProperty::create('URL', $url));
        }

        return $journal;
    }
}
