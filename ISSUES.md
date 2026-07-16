# Issues found in php-icalendar-core

Found while building git2ics against **php-icalendar-core 1.1.5** (MIT), on
PHP 8.2.32. Every claim below was verified against the library source or
reproduced empirically — none are inferred from documentation.

git2ics's output is RFC 5545 conformant and independently validated by Python's
`icalendar`. These are reported because they affect other consumers, and because
git2ics has to work around several of them — one of which shapes a default.

Ordered most severe first.

---

## 1. Floating DATE-TIME values are silently promoted to UTC on parse

**Severity: high — silent data corruption, environment-dependent**

Write a floating `DATE-TIME`, parse it back, and it returns as an absolute UTC
instant. A floating time means "09:00 wherever you are"; a UTC time means a
fixed instant that renders at a different hour for every reader outside UTC.
These are different events, and the conversion is silent.

### Reproduce

```php
$ics = "BEGIN:VCALENDAR\r\nPRODID:-//x//y//EN\r\nVERSION:2.0\r\n"
     . "BEGIN:VEVENT\r\nUID:a@b\r\nDTSTAMP:20260716T151122Z\r\n"
     . "DTSTART:20260101T100000\r\nSUMMARY:s\r\nEND:VEVENT\r\nEND:VCALENDAR";

$cal = (new Icalendar\Parser\Parser(Parser::STRICT))->parse($ics);
echo $cal->getComponents('VEVENT')[0]->getProperty('DTSTART')->getValue()->getRawValue();
```

| `date.timezone` | Input | Output |
|---|---|---|
| `UTC` | `20260101T100000` | `20260101T100000Z` ← **corrupted** |
| `America/New_York` | `20260101T100000` | `20260101T100000` ✓ |
| `Asia/Tokyo` | `20260101T100000` | `20260101T100000` ✓ |

### Cause

Two components interact:

1. `Parser/ValueParser/DateTimeParser::parseLocal()` builds
   `new DateTimeImmutable($dateTimeString)` with **no timezone argument**, so the
   value inherits PHP's `date.timezone`.
2. `Value/DateTimeValue::serialize()` appends `Z` whenever the value's timezone
   *name* is `UTC` or `Z`:

   ```php
   $tz = $this->value->getTimezone();
   if ($tz instanceof \DateTimeZone && ($tz->getName() === 'UTC' || $tz->getName() === 'Z')) {
       $formatted .= 'Z';
   }
   ```

   It **infers** UTC-ness from a timezone name that `parseLocal()` never intended
   to assign, rather than recording whether the source actually carried a `Z`.

### Why it hides

It only manifests when `date.timezone=UTC` — the default on most servers,
containers, and CI runners. A developer on a non-UTC machine cannot reproduce it,
so the *common production configuration* is the broken one.

### Suggested fix

`DateTimeValue` should record UTC-ness rather than infer it — a constructor flag,
or a distinct type alongside `DATE-TIME` and `DATE`:

```php
public function __construct(
    private readonly \DateTimeInterface $value,
    private readonly string $type = 'DATE-TIME',
    private readonly bool $isUtc = false,   // set by the parser from the source
) {}
```

`DateTimeParser::parseUtc()` would pass `true`; `parseLocal()` `false`. A
floating value would then round-trip regardless of the host's timezone.

### Consequence for git2ics

This is one reason git2ics defaults `DTSTART` to **UTC** rather than floating.
For a commit made at 14:46:02+09:00:

| Mode | git2ics writes | php-icalendar-core reads back |
|---|---|---|
| floating (`--tz=local`) | `20260715T144602` | `20260715T144602Z` — 14:46 **UTC**, nine hours adrift |
| UTC (default) | `20260715T054602Z` | `20260715T054602Z` — correct |

UTC is the better answer on its own merits: git records an explicit offset, so
the instant is unambiguous and converting preserves it exactly, whereas floating
discards that offset. Sidestepping this bug is a bonus, not the reason. But it
does mean `--tz=local` output is unreliable through this library until fixed.

### Note for WebCalendar

Worth checking whether WebCalendar shares this inference, since it consumes
git2ics output.

---

## 2. `VCalendar::validate()` does not recurse into child components

**Severity: high — invalid output ships silently**

`VCalendar.php:238-253` checks `PRODID` and `VERSION` on the calendar itself and
returns. It never calls `validate()` on its children, so `VEvent::validate()`
(`VEvent.php:558`) is unreachable through the public entry point.

A calendar containing a `VEVENT` with no `UID` and no `DTSTAMP` — both required
by RFC 5545 — passes `$calendar->validate()` without complaint.

Combined with issue #3, there is no path through the library that catches it.

**Workaround in git2ics:** `Calendar/CalendarValidator.php` walks the tree and
validates each component.

**Suggested fix:** have `AbstractComponent::validate()` recurse into
`getComponents()` by default, with concrete classes calling `parent::validate()`.

---

## 3. `Writer::write()` performs no validation

**Severity: high**

`Writer.php:31-36` serialises whatever it is handed:

```php
public function write(VCalendar $calendar): string
{
    $output = $this->writeComponent($calendar);
    return $this->contentLineWriter->write($output);
}
```

A `VCALENDAR` with no `PRODID` writes happily, as does a `VEVENT` with no `UID`.
Nothing auto-adds `VERSION` or `PRODID` either, so forgetting `setProductId()`
produces non-conformant output with no signal.

This is defensible as a design choice (write is not validate), but combined with
#2 it means the library will never tell you that your output is invalid.

**Suggested fix:** either call `validate()` from `write()`, or add
`Writer::writeValidated()`. At minimum, document that `write()` does not validate.

---

## 4. `validate()` is not declared on any interface or base class

**Severity: medium — no type can express "a validatable component"**

`validate()` is implemented on every concrete component:

```
Available, Daylight, Participant, Standard, VAlarm, VAvailability,
VCalendar, VEvent, VFreeBusy, VJournal, VTimezone, VTodo
```

but is declared on **neither** `ComponentInterface` nor `AbstractComponent`, and
`GenericComponent` does not implement it at all.

So generic code over `ComponentInterface` cannot call `validate()` — both PHPStan
(level 9) and Psalm reject it:

```
UndefinedInterfaceMethod - Method Icalendar\Component\ComponentInterface::validate does not exist
```

**Workaround in git2ics:** `method_exists($component, 'validate')` before calling.

**Suggested fix:** declare `validate(): void` on `ComponentInterface`, with a
no-op default on `AbstractComponent` so `GenericComponent` stays valid.

---

## 5. `SecurityValidator::sanitizeText()` is dead code

**Severity: medium — control characters reach the output**

`Validation/SecurityValidator.php:314` implements exactly the right thing:
strips NUL, escapes C0 control characters except tab/LF/CR. But it is **never
called anywhere in `src/`** — verified:

```
$ grep -rn "sanitizeText" src/
src/Validation/SecurityValidator.php:314:    public function sanitizeText(string $text): string
```

`Writer/ValueWriter/TextWriter.php:31-40` escapes backslash, `;`, `,` and CRLF
per RFC 5545 §3.3.11 — but not control characters. Since git permits arbitrary
bytes in a commit message, a raw `0x01` lands unescaped in a `DESCRIPTION`, which
is illegal in an ICS `TEXT` value.

**Workaround in git2ics:** `DescriptionFormatter` calls `sanitizeText()` itself.

**Suggested fix:** call it from `TextWriter`, so every consumer benefits without
knowing the class exists.

---

## 6. `Writer::write()` strips the trailing CRLF, producing non-conformant output

**Severity: medium**

`Writer/ContentLineWriter.php:39` does `rtrim($content, "\r\n")`, so output ends:

```
END:VCALENDAR
```

RFC 5545 §3.4's ABNF requires the final line to be terminated:

```
icalobject = "BEGIN" ":" "VCALENDAR" CRLF icalbody "END" ":" "VCALENDAR" CRLF
```

### Reproduce

```php
$c = new VCalendar();
$c->setProductId('-//x//y//EN')->setVersion('2.0');
var_dump(str_ends_with((new Writer())->write($c), "\r\n"));  // false
```

Strict parsers are entitled to reject this. `writeToFile()` inherits it.

**Suggested fix:** keep the terminating CRLF. Folding should not remove the final
line break.

---

## 7. `VJournal` has no `setUrl()`, unlike `VEvent`

**Severity: low — API asymmetry**

`VEvent::setUrl()` exists (`VEvent.php:362`). `VJournal` has no equivalent
(`grep -c setUrl src/Component/VJournal.php` → `0`), though RFC 5545 §3.8.4.6
permits `URL` on a `VJOURNAL`.

**Workaround in git2ics:**

```php
$journal->addProperty(GenericProperty::create('URL', $url));
```

which works, but the asymmetry is a trap for anyone assuming the component
classes share a property surface.

**Suggested fix:** add `setUrl()`/`getUrl()` to `VJournal`, or lift the common
optional properties into a shared trait.

---

## 8. Setters accept any string with no format validation

**Severity: low — by design, but worth documenting**

Every date setter takes a `string` and validates nothing:

```php
public function setDtStart(string $dtStart): self
{
    $this->removeProperty('DTSTART');
    $this->addProperty(GenericProperty::create('DTSTART', $dtStart));
    return $this;
}
```

So `$event->setDtStart('banana')` yields `DTSTART:banana`, and combined with #2
and #3 it is written out without complaint.

There is also **no way to attach a `TZID` or `VALUE` parameter** through these
setters — they write bare values only. Reaching TZID at all means dropping to
`GenericProperty` and `setParameter()`.

**Suggested fix:** accept `DateTimeInterface` alongside `string`, and/or add an
optional parameters argument. At minimum, document that setters are unvalidated.

---

## 9. `composer.json` allows PHP 8.1 while the docs say 8.2+

**Severity: low — metadata inconsistency**

| Source | Requirement |
|---|---|
| `composer.json` | `"php": ">=8.1"` |
| `README.md` badge and line 60 | `>=8.2`, "Requires PHP 8.2+" |
| `STATUS.md` line 40 | `PHP 8.2+` |

**I could not confirm this is install-breaking, and it probably is not.** I found
no 8.2-only syntax in `src/` — no `readonly class`, no standalone `true`/`false`/
`null` types. `readonly` appears only as promoted constructor properties, which is
8.1. The 173 uses of `#[\Override]` are a PHP 8.3 attribute, but attributes are
inert unless reflected, so they do not fatal on older versions.

So the library may genuinely work on 8.1 and `composer.json` may be correct while
the docs are wrong — the opposite of my first assumption. Only PHP 8.2 was
available to test, so I could not verify 8.1 directly.

**Suggested fix:** decide which is true and align the three. If 8.1 is supported,
add it to the CI matrix; if not, raise the `composer.json` constraint so Composer
refuses to install on 8.1 rather than failing at runtime.

---

## Credit where due

Two things worth saying, since this document is otherwise a list of complaints.

**Line folding is genuinely excellent.** git2ics round-trips CJK, emoji,
accented Latin and mixed-width subjects through `ContentLineWriter`, sized so
folds land mid-character (the 75-octet limit minus `SUMMARY:` leaves 67 octets,
which is not a multiple of 3 or 4). Every case passed on the first run: output
stays valid UTF-8 and every line is within 75 octets. The equivalent bug is
[open against golang-ical](https://github.com/arran4/golang-ical/issues/7), the
leading Go implementation. `Parser/LineFolder.php` and
`Writer/ContentLineWriter.php` treating folding as a first-class concern is the
main reason git2ics is written in PHP at all.

**Escaping is correct.** `TextWriter` handles backslash, semicolon, comma and
CRLF properly. Commit subjects are full of these and every case round-tripped.

**`ComponentWriter::write()` being public** made streaming possible — git2ics
writes one component at a time so peak memory does not grow with history size,
and the output is byte-identical to `Writer::write()`.

---

## Summary

| # | Issue | Severity | Affects git2ics? |
|---|---|---|---|
| 1 | Floating DATE-TIME promoted to UTC on parse | High | Shapes the `--tz` default |
| 2 | `VCalendar::validate()` does not recurse | High | Worked around |
| 3 | `Writer::write()` does not validate | High | Worked around |
| 4 | `validate()` on no interface or base class | Medium | Worked around |
| 5 | `sanitizeText()` never called | Medium | Worked around |
| 6 | Trailing CRLF stripped | Medium | Worked around |
| 7 | `VJournal` has no `setUrl()` | Low | Worked around |
| 8 | Setters unvalidated; no TZID access | Low | Constrains design |
| 9 | `composer.json` 8.1 vs docs 8.2+ | Low | No |
