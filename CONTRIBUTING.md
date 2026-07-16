# Contributing to git2ics

Thanks for your interest. Bug reports, feature requests and pull requests are
all welcome.

## Prerequisites

- PHP 8.2 or newer, with the `mbstring` extension
- Composer 2
- `git` on the PATH (git2ics shells out to it, and the test suite builds real
  repositories)
- A coverage driver — `pcov` (fast) or `xdebug` — for `composer coverage` and
  `composer infection`

## Setup

```bash
git clone https://github.com/craigk5n/git2ics.git
cd git2ics
composer install
composer check
```

## Development workflow

git2ics is developed test-first. Write a failing test, make it pass, then tidy.
The suite is fast (a few seconds) precisely so this is comfortable.

```bash
composer test                       # the whole suite
vendor/bin/phpunit --testsuite unit # unit tests only, ~0.1s
vendor/bin/phpunit --filter RoundTripTest
```

## Running checks

```bash
composer test         # PHPUnit
composer coverage     # coverage report; src/ is at 100%
composer phpstan      # PHPStan, level 9 — the maximum
composer psalm        # Psalm, errorLevel 1 — the strictest
composer cs           # PSR-12 check (no changes made)
composer cs-fix       # PSR-12 check, fixing what it can
composer infection    # mutation testing
composer check        # test + phpstan + psalm + cs
```

## Quality standards

### Tests

`src/` is at **100% line, method and class coverage**, and CI enforces it. New
code needs tests.

That said, coverage is a floor, not a goal — it only proves a line ran, not that
a bug would be caught. Several real bugs in this codebase were found by mutation
testing on code that was already "fully covered". If `composer infection` reports
an escaped mutant in your change, that usually means a test is asserting less
than it appears to.

Where a surviving mutant really is equivalent, say so in `infection.json5` next
to the existing notes rather than lowering the threshold.

### Static analysis

PHPStan level 9 and Psalm errorLevel 1 must both be clean. They disagree
usefully — Psalm caught a `string|false` return that PHPStan's stubs let through
— so please do not silence one to satisfy the other. Fix the underlying type
rather than adding a baseline entry or an ignore comment.

### Code style

PSR-12, enforced by PHP-CS-Fixer. Run `composer cs-fix` before pushing.

The ruleset is deliberately just `@PSR12` and nothing more opinionated: the
stricter presets want Yoda conditions and space-less concatenation, which is
churn rather than clarity.

### Architecture

git2ics is ports and adapters, and the boundary is worth preserving:

- **`Git/GitCommandRunner` is the only class that performs I/O.** Everything
  else — the log parser, the mappers, the calendar builder, the argument parser
  — is pure. That is what makes 100% coverage honest rather than a fight.
- If you find yourself wanting to run a command from somewhere else, that is a
  sign the logic should move rather than the I/O spread.

### Working with the .ics output

Two rules of thumb:

- **`RoundTripTest` is the compliance proof.** It generates real ICS and parses
  it back with php-icalendar-core's STRICT parser. If you change what is written,
  it must still round-trip.
- **Property semantics are a compatibility contract.** `UID` is the commit SHA so
  re-imports update rather than duplicate. `DTSTAMP` is generation time because
  RFC 5545 uses it as the update tiebreaker at equal `SEQUENCE`, and is always
  UTC per §3.8.7.2. `DTSTART` is UTC because git records an explicit offset, so
  the instant is unambiguous and converting preserves it exactly. Changing any of
  these changes what happens in users' calendars — please open an issue first.

### Commit messages

Conventional commits:

```
<type>: <description>

<optional body>
```

Types: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`.

## A note on the test suite

The integration tests build real git repositories with pinned author and
committer identities and timestamps, which makes commit SHAs byte-for-byte
reproducible and golden files viable.

That requires `GIT_CONFIG_GLOBAL` and `GIT_CONFIG_SYSTEM` to point at
`/dev/null`. Without them, your own `~/.gitconfig` leaks in — `commit.gpgsign`
or `core.autocrlf` change the resulting SHAs, and fixtures then pass locally
while failing in CI for reasons that look like magic. `GitRepoFixture` handles
this; if you add a test that runs git another way, pin the same variables.

`phpunit.xml` also pins `date.timezone=UTC` so results never depend on the host.

## CI pipeline

Every push and pull request runs: tests on PHP 8.2/8.3/8.4, tests against the
lowest allowed dependencies, coverage (100% enforced), PHPStan, Psalm, PSR-12,
`composer validate --strict`, `composer audit`, mutation testing, and a gitleaks
scan.

## Reporting issues

Use the issue templates. For bugs, the git version matters — git2ics shells out
to `git log`, so behaviour can vary with it.

Please report security vulnerabilities privately. See [SECURITY.md](SECURITY.md).

## License

By contributing you agree that your contributions are licensed under the MIT
License, the same as the project.
