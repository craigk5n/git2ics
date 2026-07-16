# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Because git2ics writes files that other software consumes, changes to the
generated `.ics` — property values, defaults, UID or DTSTAMP semantics — are
treated as interface changes and called out explicitly.

## [Unreleased]

### Added

- Initial implementation. Converts git history into an RFC 5545 iCalendar file.
- Each commit becomes a zero-duration `VEVENT` at its author timestamp
  (RFC 5545 §3.6.1: a `DATE-TIME` `DTSTART` with no `DTEND`).
- `--component=vjournal` to emit `VJOURNAL` components instead, which model a
  commit more precisely — dated text with no duration.
- `--duration=SPEC` (`15m`, `1h30m`, or ISO 8601) for clients that render
  zero-duration events poorly.
- `--tz=utc|local`. Default `utc`: git records an author date with an explicit
  offset, so it hands us an unambiguous instant, and UTC carries it across
  exactly. `local` emits floating time instead, keeping the literal wall clock
  but discarding the offset.
- `--date=author|committer`. Default `author`, which survives rebase,
  cherry-pick and amend.
- `--reproducible` pins `DTSTAMP` to the commit time, making output
  byte-identical across runs for committing to a repository or generating in CI.
- Commit selection: `--since`, `--until`, `--author`, `--branch`, `--all`,
  `--max-count`, `--no-merges`, and path filters after `--`.
- `--output`, `--calendar-name`, `--uid-domain`, `--help`, `--version`.
- `URL` property linking to the commit, derived from a GitHub or GitLab remote.
- A warning on stderr when reading a shallow clone, whose history is silently
  truncated.

### Notes

- `UID` is the full commit SHA at a domain, so regenerating produces identical
  UIDs and re-importing updates entries rather than duplicating them.
- `DTSTAMP` is generation time and always UTC (RFC 5545 §3.8.7.2), regardless of
  `--tz`.
- Commits are streamed from `git log` to the output one at a time, so peak
  memory does not grow with history size.
- Output is independently validated against Python's `icalendar` library, not
  only against php-icalendar-core's own parser.

[Unreleased]: https://github.com/craigk5n/git2ics/commits/main
