# git2ics

Turn git history into an RFC 5545 iCalendar file.

```bash
git2ics > activity.ics
```

Each commit becomes a zero-duration event at the moment it was authored, so a
repository's history can be subscribed to or imported like any other calendar.

```
BEGIN:VCALENDAR
PRODID:-//k5n.us//git2ics 0.1.0-dev//EN
VERSION:2.0
X-WR-CALNAME:ilibgo
BEGIN:VEVENT
UID:5b222d4809a213155a3e2dabca2e77e61c4f1b2a@git2ics.k5n.us
DTSTAMP:20260716T171747Z
DTSTART:20260629T093852
SUMMARY:feat: add wordcloud client tool
DESCRIPTION:Generate a word cloud PNG from a JSON config...
URL:https://github.com/craigk5n/ilibgo/commit/5b222d4809a213155a3e2dabca2e77e61c4f1b2a
END:VEVENT
END:VCALENDAR
```

## Install

```bash
composer global require craigk5n/git2ics
```

Requires PHP 8.2+ and `git` on the PATH.

## Usage

```
git2ics [options] [<repo-path>] [-- <path>...]
```

With no `<repo-path>`, the current directory is used. Output goes to stdout
unless `-o` is given.

```bash
git2ics > activity.ics                              # current repo
git2ics ~/programs/ilibgo --since=2026-01-01        # a date range
git2ics --component=vjournal --no-merges            # journal entries, no merges
git2ics --author=craig -o mine.ics                  # one author, to a file
git2ics -- src/                                     # only commits touching src/
```

Run `git2ics --help` for the full option list.

## Design notes

**Zero-duration events.** A commit is an instant, not a span. RFC 5545 §3.6.1
makes a `DATE-TIME` `DTSTART` with no `DTEND` exactly that, and it is the
default. Some clients render zero-duration events poorly; `--duration=15m`
gives them a visible length if yours is one of them.

**Floating local time.** `DTSTART` is written as floating local time
(§3.3.5 form 1), so a commit made at 09:00 reads as 09:00 — which is the
question this tool exists to answer. `--tz=utc` emits absolute UTC instants
instead, which is correct across timezones but re-renders every commit at
whatever hour the reader's offset implies.

**Author dates by default.** The author date is when the work was written and
survives rebase, cherry-pick and amend; the committer date collapses onto the
instant of a rebase. `--date=committer` if you want when it landed.

**Stable UIDs.** `UID` is the full commit SHA at a domain, so regenerating
produces identical UIDs and re-importing updates entries rather than duplicating
them. The same SHA in a fork or submodule deliberately collides: it is the same
commit.

**`DTSTAMP` is generation time.** It is RFC 5545's update tiebreaker at equal
`SEQUENCE`, so pinning it would let clients keep a stale copy after you
regenerate with different options. `--reproducible` pins it to the commit time
anyway, making output byte-identical across runs — useful for committing the
`.ics` to a repository or generating it in CI.

**Memory.** Commits are streamed from `git log` to the output one at a time, so
peak memory does not grow with history size.

## Development

```bash
composer install
composer test         # PHPUnit
composer coverage     # 100% of src/
composer phpstan      # level 9
composer psalm        # errorLevel 1
composer infection    # mutation testing
composer check        # test + phpstan + psalm
```

The test suite builds real git repositories with pinned identities and
timestamps so commit SHAs are byte-for-byte reproducible. That requires
`GIT_CONFIG_GLOBAL` and `GIT_CONFIG_SYSTEM` to point at `/dev/null` — otherwise
a developer's own `~/.gitconfig` (`commit.gpgsign`, `core.autocrlf`) changes the
SHAs and fixtures pass locally while failing in CI. See
`tests/Integration/GitRepoFixture.php`.

### Known upstream issue

`php-icalendar-core` 1.1.5 promotes floating `DATE-TIME` values to UTC when
parsing them back, if the host's `date.timezone` is `UTC` (the default on most
servers and CI). git2ics's *output* is correct and unaffected — the bug is
parse-side only. `RoundTripTest::testFloatingDtStartIsPromotedToUtcByTheParser`
pins the current behaviour and will fail when the library is fixed.

## Credits

Built on [craigk5n/php-icalendar-core](https://github.com/craigk5n/php-icalendar-core).

## License

MIT
