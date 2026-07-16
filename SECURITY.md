# Security Policy

## Supported versions

Security fixes land on the latest release.

| Version | Supported |
| ------- | --------- |
| 1.x     | ✅        |

## Reporting a vulnerability

Please report security issues **privately**, not as a public issue.

Use GitHub's private reporting:
[Report a vulnerability](https://github.com/craigk5n/git2ics/security/advisories/new)

Please include what you can — the affected version, how to reproduce it, and
what an attacker could achieve. You will get an acknowledgement, and I will let
you know when a fix ships and credit you unless you would rather I didn't.

## Threat model

git2ics is a local CLI. It reads a git repository and writes an `.ics` file. It
opens no network connections, runs no server, and stores no credentials.

The interesting surface is small but real:

**It executes `git`.** Every invocation uses `proc_open` with an **array**
command, so nothing is passed through a shell and shell metacharacters in
arguments are inert. There is no string interpolation into a command line
anywhere in the codebase. `GitCommandRunner` is the only class that runs
anything.

**Arguments can still be argv injection.** A revision is passed to `git log` as
a bare argument, so a value beginning with `-` would be read by git as an option
(`--upload-pack=…` being the classic). `GitLogCommand` rejects those; path
filters are safe because they follow `--`.

**Repository content is untrusted input.** Commit messages are attacker-supplied
if you generate a calendar from a repository you do not control. They are handled
as data: control characters are stripped or escaped, invalid UTF-8 is
substituted, and ICS special characters are escaped per RFC 5545 §3.3.11. A
commit message cannot inject a property or a component into the output.

**The output contains what the repository contains.** Commit subjects, bodies,
author names and author email addresses all end up in the `.ics`. Publishing a
generated calendar publishes those. This is intended behaviour, not a
vulnerability — but it is worth knowing before you host one, and worth
remembering when attaching output to a bug report.

### Out of scope

- Anything requiring the attacker to already have local code execution as your
  user
- The contents of a repository you chose to run git2ics against being reflected
  in the output — see above
