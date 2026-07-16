<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Git;

use K5n\Git2Ics\Exception\GitException;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Git\CommitLogParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The parser consumes the exact bytes produced by:
 *
 *   git log -z --pretty=tformat:%H%x1f%aI%x1f%cI%x1f%an%x1f%ae%x1f%cn%x1f%ce%x1f%P%x1f%B
 *
 * Fields are separated by US (0x1f); records are TERMINATED by NUL (tformat,
 * not format). Every fixture here mirrors bytes captured from real git 2.34.
 */
#[CoversClass(CommitLogParser::class)]
final class CommitLogParserTest extends TestCase
{
    private const US = "\x1f";
    private const NUL = "\0";

    private CommitLogParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CommitLogParser();
    }

    /**
     * @param list<string> $fields
     */
    private function record(array $fields): string
    {
        return implode(self::US, $fields) . self::NUL;
    }

    /**
     * A non-root commit with a body. `%B` always carries a trailing newline.
     */
    private function ordinaryRecord(): string
    {
        return $this->record([
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            '2026-01-03T09:15:00+00:00',
            '2026-01-03T09:15:00+00:00',
            'Craig',
            'c@k5n.us',
            'Craig',
            'c@k5n.us',
            'bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e',
            "second subject\n",
        ]);
    }

    /**
     * The root commit: `%P` is EMPTY, producing two consecutive 0x1f bytes.
     * Verified against real git output.
     */
    private function rootRecord(): string
    {
        return $this->record([
            'bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e',
            '2026-01-01T10:00:00+09:00',
            '2026-01-02T11:30:00-05:00',
            'Craig',
            'c@k5n.us',
            'Craig',
            'c@k5n.us',
            '',
            "first subject\n\nbody line one\nbody line two\n",
        ]);
    }

    /**
     * @return list<Commit>
     */
    private function parseToList(string $raw): array
    {
        return iterator_to_array($this->parser->parse($raw), false);
    }

    public function testParsesAllFieldsOfAnOrdinaryCommit(): void
    {
        $commits = $this->parseToList($this->ordinaryRecord());

        self::assertCount(1, $commits);
        $commit = $commits[0];

        self::assertSame('afb87de8347df30f6ab4c6fa17cc843026e52cb7', $commit->sha);
        self::assertSame('Craig', $commit->authorName);
        self::assertSame('c@k5n.us', $commit->authorEmail);
        self::assertSame('Craig', $commit->committerName);
        self::assertSame('c@k5n.us', $commit->committerEmail);
        self::assertSame(['bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e'], $commit->parents);
        self::assertSame('second subject', $commit->subject());
    }

    public function testParsesMultipleRecords(): void
    {
        $commits = $this->parseToList($this->ordinaryRecord() . $this->rootRecord());

        self::assertCount(2, $commits);
        self::assertSame('second subject', $commits[0]->subject());
        self::assertSame('first subject', $commits[1]->subject());
    }

    /**
     * Guard: explode("\0", "") returns [""] — one bogus record, not zero.
     */
    public function testEmptyInputYieldsNoCommits(): void
    {
        self::assertSame([], $this->parseToList(''));
    }

    /**
     * tformat TERMINATES each record, so the raw bytes always end with NUL.
     * That trailing NUL must not produce a phantom trailing record.
     */
    public function testTrailingNulDoesNotYieldAPhantomRecord(): void
    {
        $raw = $this->ordinaryRecord();
        self::assertStringEndsWith(self::NUL, $raw, 'fixture must model tformat terminator');

        self::assertCount(1, $this->parseToList($raw));
    }

    /**
     * An empty record must be skipped, not treated as end-of-stream: stopping
     * early would silently drop every commit after it.
     */
    public function testEmptyRecordMidStreamIsSkippedRatherThanEndingParsing(): void
    {
        $raw = $this->ordinaryRecord() . self::NUL . $this->rootRecord();

        $commits = $this->parseToList($raw);

        self::assertCount(2, $commits, 'parsing must continue past an empty record');
        self::assertSame('first subject', $commits[1]->subject());
    }

    /**
     * Regression: %P is empty for a root commit, and explode(' ', '') === [''].
     * A naive parse invents a phantom empty-string parent and reports isRoot() false.
     */
    public function testRootCommitHasNoParents(): void
    {
        $commits = $this->parseToList($this->rootRecord());

        self::assertSame([], $commits[0]->parents);
        self::assertTrue($commits[0]->isRoot());
        self::assertFalse($commits[0]->isMerge());
    }

    public function testMergeCommitHasMultipleParents(): void
    {
        $raw = $this->record([
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            '2026-01-03T09:15:00+00:00',
            '2026-01-03T09:15:00+00:00',
            'Craig',
            'c@k5n.us',
            'Craig',
            'c@k5n.us',
            'bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e afb87de8347df30f6ab4c6fa17cc843026e52cb8',
            "Merge branch 'feature'\n",
        ]);

        $commit = $this->parseToList($raw)[0];

        self::assertCount(2, $commit->parents);
        self::assertTrue($commit->isMerge());
    }

    public function testTrailingNewlineOfRawBodyIsStripped(): void
    {
        $commit = $this->parseToList($this->rootRecord())[0];

        self::assertSame("first subject\n\nbody line one\nbody line two", $commit->rawBody);
        self::assertSame("body line one\nbody line two", $commit->body());
    }

    /**
     * %B is the LAST field precisely so a stray 0x1f inside a commit message is
     * absorbed by the explode limit rather than shifting every later field.
     */
    public function testUnitSeparatorInsideCommitBodyDoesNotCorruptParsing(): void
    {
        $raw = $this->record([
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            '2026-01-03T09:15:00+00:00',
            '2026-01-03T09:15:00+00:00',
            'Craig',
            'c@k5n.us',
            'Craig',
            'c@k5n.us',
            'bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e',
            "subject\n\nbody with " . self::US . " a raw unit separator\n",
        ]);

        $commit = $this->parseToList($raw)[0];

        self::assertSame('subject', $commit->subject());
        self::assertSame('c@k5n.us', $commit->authorEmail, 'fields must not shift');
        self::assertStringContainsString('a raw unit separator', $commit->body());
    }

    public function testEmptyCommitMessageYieldsEmptySubject(): void
    {
        $raw = $this->record([
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            '2026-01-03T09:15:00+00:00',
            '2026-01-03T09:15:00+00:00',
            'Craig',
            'c@k5n.us',
            'Craig',
            'c@k5n.us',
            'bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e',
            "\n",
        ]);

        self::assertSame('', $this->parseToList($raw)[0]->subject());
    }

    public function testAuthorAndCommitterDatesPreserveTheirOriginalOffsets(): void
    {
        $commit = $this->parseToList($this->rootRecord())[0];

        self::assertSame('2026-01-01T10:00:00+09:00', $commit->authorDate->format('c'));
        self::assertSame('2026-01-02T11:30:00-05:00', $commit->committerDate->format('c'));
        self::assertSame('+09:00', $commit->authorDate->format('P'));
    }

    /**
     * git transcodes via the commit `encoding` header, but headers can lie.
     * Invalid UTF-8 must never reach the writer: it breaks octet-boundary folding.
     */
    public function testInvalidUtf8InCommitMessageIsSubstituted(): void
    {
        $raw = $this->record([
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            '2026-01-03T09:15:00+00:00',
            '2026-01-03T09:15:00+00:00',
            "Cr\xE9aig",
            'c@k5n.us',
            'Craig',
            'c@k5n.us',
            'bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e',
            "caf\xE9 subject\n",
        ]);

        $commit = $this->parseToList($raw)[0];

        self::assertTrue(mb_check_encoding($commit->rawBody, 'UTF-8'), 'body must be valid UTF-8');
        self::assertTrue(mb_check_encoding($commit->authorName, 'UTF-8'), 'name must be valid UTF-8');
    }

    public function testValidUtf8IsPreservedByteForByte(): void
    {
        $subject = 'fix: 日本語 and 🎉 emoji';
        $raw = $this->record([
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            '2026-01-03T09:15:00+00:00',
            '2026-01-03T09:15:00+00:00',
            'Craig',
            'c@k5n.us',
            'Craig',
            'c@k5n.us',
            'bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e',
            $subject . "\n",
        ]);

        self::assertSame($subject, $this->parseToList($raw)[0]->subject());
    }

    public function testMalformedRecordWithTooFewFieldsThrows(): void
    {
        $this->expectException(GitException::class);
        $this->expectExceptionMessageMatches('/malformed/i');

        $this->parseToList('deadbeef' . self::US . 'only-two-fields' . self::NUL);
    }

    public function testInvalidDateInRecordThrows(): void
    {
        $raw = $this->record([
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            'not-a-date',
            '2026-01-03T09:15:00+00:00',
            'Craig',
            'c@k5n.us',
            'Craig',
            'c@k5n.us',
            'bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e',
            "subject\n",
        ]);

        $this->expectException(GitException::class);

        $this->parseToList($raw);
    }

    public function testParseIsLazy(): void
    {
        $generator = $this->parser->parse($this->ordinaryRecord());

        self::assertInstanceOf(\Generator::class, $generator);
    }
}
