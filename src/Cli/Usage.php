<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Cli;

use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Version;

/**
 * The --help text.
 */
final class Usage
{
    public static function text(): string
    {
        $domain = UidGenerator::DEFAULT_DOMAIN;
        $version = Version::VERSION;

        // Delimiter is deliberately not "USAGE": the text contains a USAGE
        // section header, which would close the heredoc early.
        return <<<HELP_TEXT
        git2ics {$version} — turn git history into an iCalendar file

        USAGE
          git2ics [options] [<repo-path>] [-- <path>...]

        Each commit becomes a zero-duration event at the moment it was authored.
        With no <repo-path>, the current directory is used. Output goes to stdout
        unless -o is given.

        OUTPUT
          -o, --output=FILE       write to FILE instead of stdout
          -c, --component=TYPE    vevent | vjournal            (default: vevent)
              --duration=SPEC     give events a length, e.g. 15m, 1h, 1h30m
                                  (default: zero-duration, per RFC 5545)
              --calendar-name=STR calendar display name        (default: repo name)
              --category=LIST     CATEGORIES for every event, comma-separated,
                                  e.g. git or git,release       (default: none)

        TIME
              --date=SOURCE       author | committer           (default: author)
                                  author survives rebases; committer does not
              --tz=MODE           utc | local                  (default: utc)
                                  utc preserves the exact instant git recorded;
                                  local emits floating time, keeping the wall
                                  clock but discarding the offset
              --reproducible      pin DTSTAMP to the commit time, so repeated runs
                                  produce byte-identical output

        SELECTING COMMITS
              --since=DATE        only commits after DATE
              --until=DATE        only commits before DATE
              --author=PATTERN    only commits by an author matching PATTERN
              --branch=REF        read REF instead of the current HEAD
              --all               read all refs
              --max-count=N       at most N commits
              --no-merges         skip merge commits
              -- <path>...        only commits touching these paths

        OTHER
              --uid-domain=STR    UID domain                   (default: {$domain})
          -h, --help              show this help
          -V, --version           show the version

        EXAMPLES
          git2ics > activity.ics
          git2ics ~/programs/ilibgo --since=2026-01-01 -o ilibgo.ics
          git2ics --component=vjournal --no-merges
          git2ics --category=git,release -o activity.ics
          git2ics -- src/

        HELP_TEXT;
    }
}
