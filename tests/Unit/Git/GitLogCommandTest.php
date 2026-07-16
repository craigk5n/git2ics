<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Git;

use K5n\Git2Ics\Exception\GitException;
use K5n\Git2Ics\Git\GitLogCommand;
use K5n\Git2Ics\Git\LogQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure: builds the argv for `git log`. Nothing here executes anything, which is
 * what lets the interesting cases — flag injection, ordering, path separation —
 * be tested exhaustively and fast.
 */
#[CoversClass(GitLogCommand::class)]
#[CoversClass(LogQuery::class)]
final class GitLogCommandTest extends TestCase
{
    private GitLogCommand $command;

    protected function setUp(): void
    {
        $this->command = new GitLogCommand();
    }

    /**
     * @return list<string>
     */
    private function build(LogQuery $query): array
    {
        return $this->command->build($query);
    }

    public function testBaseCommandRequestsMachineReadableOutput(): void
    {
        $args = $this->build(new LogQuery());

        self::assertContains('log', $args);
        self::assertContains('-z', $args);
        self::assertContains('--pretty=tformat:' . GitLogCommand::FORMAT, $args);
    }

    /**
     * tformat terminates records; format only separates them. The parser relies
     * on the terminator, so this is not cosmetic.
     */
    public function testFormatUsesTformatNotFormat(): void
    {
        $args = $this->build(new LogQuery());

        $pretty = array_values(array_filter($args, fn (string $a): bool => str_starts_with($a, '--pretty=')));

        self::assertCount(1, $pretty);
        self::assertStringStartsWith('--pretty=tformat:', $pretty[0]);
    }

    /**
     * %s must not appear: it sits before %B and a 0x1f in a subject would shift
     * every later field. The subject is derived from %B instead.
     */
    public function testFormatDoesNotUseSubjectPlaceholder(): void
    {
        self::assertStringNotContainsString('%s', GitLogCommand::FORMAT);
        self::assertStringEndsWith('%B', GitLogCommand::FORMAT, '%B must be last');
    }

    /**
     * Guarantees UTF-8 regardless of the user's i18n.logOutputEncoding.
     */
    public function testOutputEncodingIsForcedToUtf8(): void
    {
        $args = $this->build(new LogQuery());

        $index = array_search('-c', $args, true);
        self::assertIsInt($index);
        self::assertSame('i18n.logOutputEncoding=UTF-8', $args[$index + 1]);
        self::assertLessThan(
            array_search('log', $args, true),
            $index,
            '-c must precede the subcommand',
        );
    }

    public function testNoOptionsProducesNoFilters(): void
    {
        $args = $this->build(new LogQuery());

        self::assertNotContains('--no-merges', $args);
        self::assertNotContains('--all', $args);
        self::assertNotContains('--', $args);
    }

    public function testNoMerges(): void
    {
        self::assertContains('--no-merges', $this->build(new LogQuery(noMerges: true)));
    }

    public function testMaxCount(): void
    {
        self::assertContains('--max-count=10', $this->build(new LogQuery(maxCount: 10)));
    }

    public function testSinceAndUntil(): void
    {
        $args = $this->build(new LogQuery(since: '2026-01-01', until: '2026-06-30'));

        self::assertContains('--since=2026-01-01', $args);
        self::assertContains('--until=2026-06-30', $args);
    }

    public function testAuthor(): void
    {
        self::assertContains('--author=craig', $this->build(new LogQuery(author: 'craig')));
    }

    public function testAllRefs(): void
    {
        self::assertContains('--all', $this->build(new LogQuery(allRefs: true)));
    }

    public function testBranchIsPassedAsARevision(): void
    {
        self::assertContains('develop', $this->build(new LogQuery(branch: 'develop')));
    }

    /**
     * --all and an explicit branch are mutually exclusive; --all wins.
     */
    public function testAllRefsSupersedesAnExplicitBranch(): void
    {
        $args = $this->build(new LogQuery(branch: 'develop', allRefs: true));

        self::assertContains('--all', $args);
        self::assertNotContains('develop', $args);
    }

    public function testPathsFollowADoubleDashSeparator(): void
    {
        $args = $this->build(new LogQuery(paths: ['src/', 'README.md']));

        $separator = array_search('--', $args, true);
        self::assertIsInt($separator, 'paths must be separated by --');
        self::assertSame(['src/', 'README.md'], array_slice($args, $separator + 1));
    }

    public function testNoSeparatorWhenThereAreNoPaths(): void
    {
        self::assertNotContains('--', $this->build(new LogQuery()));
    }

    /**
     * A path that looks like a flag is harmless: everything after -- is a path
     * by definition.
     */
    public function testFlagLikePathIsSafeAfterTheSeparator(): void
    {
        $args = $this->build(new LogQuery(paths: ['--not-a-flag']));

        $separator = array_search('--', $args, true);
        self::assertIsInt($separator);
        self::assertSame(['--not-a-flag'], array_slice($args, $separator + 1));
    }

    /**
     * A branch is passed as a bare revision, so a value beginning with `-` would
     * be read by git as an option — `--upload-pack=...` being the classic. Args
     * never reach a shell, but this is argv injection, not shell injection.
     */
    public function testBranchBeginningWithADashIsRejected(): void
    {
        $this->expectException(GitException::class);
        $this->expectExceptionMessageMatches('/branch/i');

        $this->build(new LogQuery(branch: '--upload-pack=/bin/sh'));
    }

    public function testBranchThatIsJustADashIsRejected(): void
    {
        $this->expectException(GitException::class);

        $this->build(new LogQuery(branch: '-'));
    }

    public function testOrdinaryBranchNamesAreAccepted(): void
    {
        foreach (['main', 'feature/x', 'release-1.0', 'v2.0.0', 'HEAD~5', 'origin/main'] as $branch) {
            self::assertContains($branch, $this->build(new LogQuery(branch: $branch)));
        }
    }

    /**
     * Values are single argv entries, so shell metacharacters are inert — they
     * are passed to git verbatim and never reach a shell.
     */
    public function testValuesWithShellMetacharactersArePassedThroughVerbatim(): void
    {
        $args = $this->build(new LogQuery(author: '$(rm -rf /); echo pwned'));

        self::assertContains('--author=$(rm -rf /); echo pwned', $args);
    }

    public function testEverythingCombined(): void
    {
        $args = $this->build(new LogQuery(
            since: '2026-01-01',
            until: '2026-06-30',
            author: 'craig',
            branch: 'develop',
            maxCount: 5,
            noMerges: true,
            paths: ['src/'],
        ));

        foreach (['--since=2026-01-01', '--until=2026-06-30', '--author=craig', '--max-count=5', '--no-merges', 'develop'] as $expected) {
            self::assertContains($expected, $args);
        }

        // The revision must still precede the path separator.
        self::assertLessThan(array_search('--', $args, true), array_search('develop', $args, true));
    }
}
