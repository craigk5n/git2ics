<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

use DateTimeImmutable;
use Exception;
use Generator;
use K5n\Git2Ics\Exception\GitException;

/**
 * Parses the raw bytes emitted by `git log -z --pretty=tformat:...`.
 *
 * Pure: no I/O. Given the same bytes it yields the same commits, which is what
 * makes the awkward cases below cheap to test.
 *
 * The expected format is:
 *
 *   %H %aI %cI %an %ae %cn %ce %P %B      (separated by 0x1f, terminated by NUL)
 *
 * Two deliberate choices in that format string:
 *
 *  - `%B` is LAST so that a 0x1f byte inside a commit message is absorbed by the
 *    explode limit instead of shifting every subsequent field. `%s` is not used
 *    at all — it sits before `%B` and would reintroduce exactly that hazard, and
 *    it is redundant since `%B` already begins with the subject.
 *  - `tformat:` (terminator) rather than `format:` (separator), so every record
 *    self-delimits and the trailing NUL is unambiguous.
 */
final class CommitLogParser
{
    private const FIELD_SEPARATOR = "\x1f";
    private const RECORD_TERMINATOR = "\0";

    /** %H %aI %cI %an %ae %cn %ce %P %B */
    private const FIELD_COUNT = 9;

    /**
     * @return Generator<int, Commit> Lazy: a large repository is never materialised.
     *
     * @throws GitException on a malformed record or an unparsable date
     */
    public function parse(string $raw): Generator
    {
        // explode("\0", "") returns [""] — one bogus record, not zero.
        if ($raw === '') {
            return;
        }

        foreach (explode(self::RECORD_TERMINATOR, $raw) as $record) {
            // tformat terminates every record, so the final split is always empty.
            if ($record === '') {
                continue;
            }

            yield $this->parseRecord($record);
        }
    }

    private function parseRecord(string $record): Commit
    {
        $fields = explode(self::FIELD_SEPARATOR, $record, self::FIELD_COUNT);

        if (count($fields) !== self::FIELD_COUNT) {
            throw new GitException(sprintf(
                'malformed git log record: expected %d fields, got %d',
                self::FIELD_COUNT,
                count($fields),
            ));
        }

        [
            $sha,
            $authorDate,
            $committerDate,
            $authorName,
            $authorEmail,
            $committerName,
            $committerEmail,
            $parents,
            $rawBody,
        ] = $fields;

        return new Commit(
            sha: $sha,
            authorDate: $this->parseDate($authorDate),
            committerDate: $this->parseDate($committerDate),
            authorName: $this->toValidUtf8($authorName),
            authorEmail: $this->toValidUtf8($authorEmail),
            committerName: $this->toValidUtf8($committerName),
            committerEmail: $this->toValidUtf8($committerEmail),
            parents: $this->parseParents($parents),
            rawBody: rtrim($this->toValidUtf8($rawBody), "\n"),
        );
    }

    /**
     * @return list<string>
     */
    private function parseParents(string $field): array
    {
        // A root commit has an empty %P, and explode(' ', '') === [''] — which
        // would invent a phantom parent and make isRoot() report false.
        if ($field === '') {
            return [];
        }

        return explode(' ', $field);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw new GitException(sprintf('unparsable git date "%s"', $value), previous: $e);
        }
    }

    /**
     * git transcodes messages using the commit's `encoding` header, but that
     * header can lie. Invalid UTF-8 must not reach the ICS writer: it folds on
     * octet boundaries and would split a multi-byte character.
     */
    private function toValidUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Re-encoding UTF-8 to itself substitutes the invalid sequences.
        $scrubbed = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // mb_convert_encoding() is typed string|false, but false is only
        // returned for an unknown encoding name and both are literals here.
        assert(is_string($scrubbed));

        return $scrubbed;
    }
}
