<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Git;

use DateTimeImmutable;
use K5n\Git2Ics\Git\Commit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Commit::class)]
final class CommitTest extends TestCase
{
    /**
     * @param list<string> $parents
     */
    private function makeCommit(string $rawBody = 'subject', array $parents = ['p1']): Commit
    {
        return new Commit(
            sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            authorDate: new DateTimeImmutable('2026-01-01T10:00:00+09:00'),
            committerDate: new DateTimeImmutable('2026-01-02T11:30:00-05:00'),
            authorName: 'Craig',
            authorEmail: 'c@k5n.us',
            committerName: 'Craig',
            committerEmail: 'c@k5n.us',
            parents: $parents,
            rawBody: $rawBody,
        );
    }

    public function testSubjectIsFirstLineOfRawBody(): void
    {
        $commit = $this->makeCommit("first subject\n\nbody line one\nbody line two");

        self::assertSame('first subject', $commit->subject());
    }

    public function testSubjectWhenRawBodyIsSingleLine(): void
    {
        self::assertSame('only line', $this->makeCommit('only line')->subject());
    }

    /**
     * An empty subject is legal (`git commit --allow-empty-message`). The value
     * object reports the truth; presentation fallback belongs to the mapper.
     */
    public function testSubjectIsEmptyStringForEmptyMessage(): void
    {
        self::assertSame('', $this->makeCommit('')->subject());
    }

    public function testBodyIsEverythingAfterTheSubjectLine(): void
    {
        $commit = $this->makeCommit("first subject\n\nbody line one\nbody line two");

        self::assertSame("body line one\nbody line two", $commit->body());
    }

    public function testBodyIsEmptyWhenMessageHasSubjectOnly(): void
    {
        self::assertSame('', $this->makeCommit('just a subject')->body());
    }

    /**
     * git convention puts a blank line after the subject, but does not require
     * one. Without this case an off-by-one in the body offset is invisible,
     * because trimming newlines masks it when the blank line is present.
     */
    public function testBodyWhenNoBlankLineSeparatesTheSubject(): void
    {
        $commit = $this->makeCommit("subject line\nbody starts immediately");

        self::assertSame('subject line', $commit->subject());
        self::assertSame('body starts immediately', $commit->body());
    }

    public function testBodyPreservesInternalBlankLines(): void
    {
        $commit = $this->makeCommit("subject\n\nfirst para\n\nsecond para");

        self::assertSame("first para\n\nsecond para", $commit->body());
    }

    public function testBodyIsEmptyForEmptyMessage(): void
    {
        self::assertSame('', $this->makeCommit('')->body());
    }

    public function testShortShaIsFirstSevenCharacters(): void
    {
        self::assertSame('afb87de', $this->makeCommit()->shortSha());
    }

    /**
     * @param list<string> $parents
     */
    #[DataProvider('mergeProvider')]
    public function testIsMerge(array $parents, bool $expected): void
    {
        self::assertSame($expected, $this->makeCommit('s', $parents)->isMerge());
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function mergeProvider(): iterable
    {
        yield 'root commit has no parents' => [[], false];
        yield 'ordinary commit has one parent' => [['p1'], false];
        yield 'merge commit has two parents' => [['p1', 'p2'], true];
        yield 'octopus merge has three parents' => [['p1', 'p2', 'p3'], true];
    }

    public function testIsRoot(): void
    {
        self::assertTrue($this->makeCommit('s', [])->isRoot());
        self::assertFalse($this->makeCommit('s', ['p1'])->isRoot());
    }
}
