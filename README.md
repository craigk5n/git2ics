# git2ics

[![CI](https://github.com/craigk5n/git2ics/actions/workflows/ci.yml/badge.svg)](https://github.com/craigk5n/git2ics/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/craigk5n/git2ics.svg)](https://packagist.org/packages/craigk5n/git2ics)
[![Downloads](https://img.shields.io/packagist/dt/craigk5n/git2ics.svg)](https://packagist.org/packages/craigk5n/git2ics)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://www.php.net/)
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](https://phpstan.org/)
[![Psalm errorLevel 1](https://img.shields.io/badge/Psalm-errorLevel%201-brightgreen.svg)](https://psalm.dev/)
[![Coverage 100%](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)](#development)
[![Code Style PSR-12](https://img.shields.io/badge/code%20style-PSR--12-blue.svg)](https://www.php-fig.org/psr/psr-12/)

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
DTSTAMP:20260716T183234Z
DTSTART:20260629T133852Z
SUMMARY:feat: add wordcloud client tool
DESCRIPTION:Generate a word cloud PNG from a JSON config...
URL:https://github.com/craigk5n/ilibgo/commit/5b222d4809a213155a3e2dabca2e77e61c4f1b2a
END:VEVENT
END:VCALENDAR
```

## Install

**Composer:**

```bash
composer global require craigk5n/git2ics
```

**Standalone PHAR** — no Composer, no dependencies:

```bash
curl -L -o git2ics https://github.com/craigk5n/git2ics/releases/latest/download/git2ics.phar
chmod +x git2ics
./git2ics --version
```

Requires PHP 8.2+ with `mbstring`, and `git` on the PATH.

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

**UTC timestamps.** git records an author date with an explicit offset —
`14:46:02+09:00` — so it hands us an unambiguous instant. `DTSTART` is written
as UTC (§3.3.5 form 2), which carries that instant across exactly; a client that
knows the reader's timezone renders it back at the right wall clock.

`--tz=local` emits floating time instead, keeping the literal wall clock but
discarding the offset git gave us, so the event stops being a fixed instant.
TZID would be the third option, but git only provides an offset and a zone name
cannot be derived from one (+09:00 could be Tokyo, Seoul or Yakutsk).

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
composer cs           # PSR-12 check
composer cs-fix       # PSR-12, fixing in place
composer infection    # mutation testing
composer check        # test + phpstan + psalm + cs
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full development guide.

The test suite builds real git repositories with pinned identities and
timestamps so commit SHAs are byte-for-byte reproducible. That requires
`GIT_CONFIG_GLOBAL` and `GIT_CONFIG_SYSTEM` to point at `/dev/null` — otherwise
a developer's own `~/.gitconfig` (`commit.gpgsign`, `core.autocrlf`) changes the
SHAs and fixtures pass locally while failing in CI. See
`tests/Integration/GitRepoFixture.php`.

## Contributing

Bug reports, feature requests and pull requests are welcome — see
[CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately, per
[SECURITY.md](SECURITY.md).

Participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md).

## Credits

Built on [craigk5n/php-icalendar-core](https://github.com/craigk5n/php-icalendar-core),
whose line folding handles multi-byte characters correctly — the reason git2ics
is written in PHP.

## License

[MIT](LICENSE)
