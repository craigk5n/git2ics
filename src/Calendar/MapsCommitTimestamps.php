<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use K5n\Git2Ics\Git\Commit;

/**
 * The timestamp and summary rules shared by every component type, kept in one
 * place so VEVENT and VJOURNAL cannot drift apart.
 */
trait MapsCommitTimestamps
{
    /**
     * DTSTART, rendered per --tz from whichever date --date selects.
     */
    private function dtStart(Commit $commit): string
    {
        return $this->dateFormatter->format(
            $this->dateSource->of($commit),
            $this->timezoneMode,
        );
    }

    /**
     * DTSTAMP. Generation time by default so it functions as RFC 5545's update
     * tiebreaker at equal SEQUENCE; --reproducible pins it to the commit date to
     * make output byte-identical across runs.
     *
     * Always UTC: §3.8.7.2 requires it, whatever --tz says about DTSTART.
     */
    private function dtStamp(Commit $commit): string
    {
        $instant = $this->reproducible
            ? $this->dateSource->of($commit)
            : $this->clock->now();

        return $this->dateFormatter->utc($instant);
    }

    /**
     * An empty commit message is legal (`--allow-empty-message`) but a blank
     * SUMMARY is useless, so fall back to the short SHA.
     */
    private function summary(Commit $commit): string
    {
        $subject = $commit->subject();

        return $subject === '' ? $commit->shortSha() : $subject;
    }
}
