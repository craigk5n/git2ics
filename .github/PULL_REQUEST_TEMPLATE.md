## Summary

<!-- What does this PR do? -->

## Related issue

<!-- Link to the issue this addresses, e.g. Closes #123 -->

## Checklist

- [ ] Tests added/updated
- [ ] `composer test` passes
- [ ] `composer phpstan` passes (level 9)
- [ ] `composer psalm` passes (errorLevel 1)
- [ ] `composer cs` passes (PSR-12)
- [ ] Coverage of `src/` is still 100% (`composer coverage`)

## If this changes the generated .ics

<!-- Delete this section if it doesn't. -->

- [ ] Output still parses in STRICT mode (covered by `RoundTripTest`)
- [ ] Checked against a real client (WebCalendar, agenticdaisy-calendar, …)
- [ ] `CHANGELOG.md` updated

Anything that alters property values is a compatibility question as much as a
code one: `UID` is stable by design so re-imports update rather than duplicate,
and `DTSTAMP` is generation time because RFC 5545 uses it as the update
tiebreaker. Please say which clients you checked.
