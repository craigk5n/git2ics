<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Integration;

use K5n\Git2Ics\Exception\GitException;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Git\CommitLogParser;
use K5n\Git2Ics\Git\GitCliCommitReader;
use K5n\Git2Ics\Git\GitCommandRunner;
use K5n\Git2Ics\Git\GitLogCommand;
use K5n\Git2Ics\Git\LogQuery;
use K5n\Git2Ics\Git\RepositoryInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Runs real git against real repositories. Everything the unit tests fake.
 */
#[CoversClass(GitCliCommitReader::class)]
#[CoversClass(GitCommandRunner::class)]
#[CoversClass(RepositoryInfo::class)]
final class GitCliCommitReaderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/git2ics-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        GitRepoFixture::remove($this->workspace);
    }

    private function repoPath(string $name = 'repo'): string
    {
        return $this->workspace . '/' . $name;
    }

    private function reader(string $path, LogQuery $query = new LogQuery()): GitCliCommitReader
    {
        return new GitCliCommitReader(
            runner: new GitCommandRunner($path),
            command: new GitLogCommand(),
            parser: new CommitLogParser(),
            query: $query,
            path: $path,
        );
    }

    /**
     * @return list<Commit>
     */
    private function readAll(GitCliCommitReader $reader): array
    {
        return iterator_to_array($reader->read(), false);
    }

    public function testReadsCommitsFromARealRepository(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('first subject', 'a.txt');
        $repo->commit('second subject', 'b.txt');

        $commits = $this->readAll($this->reader($repo->path));

        self::assertCount(2, $commits);
        self::assertSame('second subject', $commits[0]->subject(), 'git log is newest first');
        self::assertSame('first subject', $commits[1]->subject());
    }

    public function testShasAreReproducibleAcrossIdenticalRepositories(): void
    {
        $one = GitRepoFixture::create($this->repoPath('one'));
        $one->commit('identical subject', 'a.txt', 'identical contents');

        $two = GitRepoFixture::create($this->repoPath('two'));
        $two->commit('identical subject', 'a.txt', 'identical contents');

        self::assertSame(
            $this->readAll($this->reader($one->path))[0]->sha,
            $this->readAll($this->reader($two->path))[0]->sha,
            'pinned identities and timestamps must produce identical SHAs',
        );
    }

    public function testCommitBodyAndMetadataAreRead(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit(
            "subject line\n\nbody first\nbody second",
            'a.txt',
            'x',
            authorDate: '2026-03-04T09:15:00+09:00',
            committerDate: '2026-03-05T11:00:00-05:00',
        );

        $commit = $this->readAll($this->reader($repo->path))[0];

        self::assertSame('subject line', $commit->subject());
        self::assertSame("body first\nbody second", $commit->body());
        self::assertSame('Craig', $commit->authorName);
        self::assertSame('c@k5n.us', $commit->authorEmail);
        self::assertSame('2026-03-04T09:15:00+09:00', $commit->authorDate->format('c'));
        self::assertSame('2026-03-05T11:00:00-05:00', $commit->committerDate->format('c'));
    }

    /**
     * The empty-%P case, end to end: verified against real git rather than a
     * hand-built fixture.
     */
    public function testRootCommitHasNoParents(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('the only commit');

        $commit = $this->readAll($this->reader($repo->path))[0];

        self::assertSame([], $commit->parents);
        self::assertTrue($commit->isRoot());
    }

    public function testMergeCommitIsDetected(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('base', 'a.txt');
        $repo->git(['checkout', '-q', '-b', 'feature']);
        $repo->commit('on feature', 'feature.txt');
        $repo->git(['checkout', '-q', 'main']);
        $repo->commit('on main', 'main.txt');
        $repo->git(['merge', '--no-ff', '-q', '-m', 'Merge feature', 'feature']);

        $merges = array_values(array_filter(
            $this->readAll($this->reader($repo->path)),
            fn (Commit $c): bool => $c->isMerge(),
        ));

        self::assertCount(1, $merges);
        self::assertCount(2, $merges[0]->parents);
    }

    public function testNoMergesExcludesMergeCommits(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('base', 'a.txt');
        $repo->git(['checkout', '-q', '-b', 'feature']);
        $repo->commit('on feature', 'feature.txt');
        $repo->git(['checkout', '-q', 'main']);
        $repo->git(['merge', '--no-ff', '-q', '-m', 'Merge feature', 'feature']);

        $commits = $this->readAll($this->reader($repo->path, new LogQuery(noMerges: true)));

        foreach ($commits as $commit) {
            self::assertFalse($commit->isMerge());
        }
    }

    /**
     * An empty repository must read as zero commits, not as a failure: `git log`
     * exits 128 there, which is indistinguishable from "not a repository" by
     * exit code alone.
     */
    public function testEmptyRepositoryYieldsNoCommitsRatherThanFailing(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());

        self::assertSame([], $this->readAll($this->reader($repo->path)));
    }

    public function testEmptyRepositoryIsReportedAsHavingNoCommits(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());

        $info = $this->reader($repo->path)->inspect();

        self::assertFalse($info->hasCommits);
        self::assertFalse($info->isShallow);
    }

    public function testNotARepositoryIsAnError(): void
    {
        $plain = $this->workspace . '/not-a-repo';
        mkdir($plain, 0o777, true);

        $this->expectException(GitException::class);
        $this->expectExceptionMessageMatches('/not a git repository/i');

        $this->readAll($this->reader($plain));
    }

    public function testMaxCountLimitsResults(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        foreach (range(1, 5) as $i) {
            $repo->commit("commit {$i}", "file{$i}.txt");
        }

        self::assertCount(2, $this->readAll($this->reader($repo->path, new LogQuery(maxCount: 2))));
    }

    public function testSinceAndUntilFilterByDate(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('old', 'old.txt', 'x', authorDate: '2020-01-01T10:00:00+00:00', committerDate: '2020-01-01T10:00:00+00:00');
        $repo->commit('new', 'new.txt', 'x', authorDate: '2026-01-01T10:00:00+00:00', committerDate: '2026-01-01T10:00:00+00:00');

        $commits = $this->readAll($this->reader($repo->path, new LogQuery(since: '2025-01-01')));

        self::assertCount(1, $commits);
        self::assertSame('new', $commits[0]->subject());
    }

    /**
     * A range matching nothing is a legitimate empty result, not an error.
     */
    public function testRangeMatchingNothingYieldsNoCommits(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('only commit');

        self::assertSame([], $this->readAll($this->reader($repo->path, new LogQuery(since: '2099-01-01'))));
    }

    public function testPathFilterRestrictsToMatchingCommits(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('touches src', 'src.txt');
        $repo->commit('touches docs', 'docs.txt');

        $commits = $this->readAll($this->reader($repo->path, new LogQuery(paths: ['src.txt'])));

        self::assertCount(1, $commits);
        self::assertSame('touches src', $commits[0]->subject());
    }

    public function testBranchSelectsThatBranchesHistory(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('base', 'a.txt');
        $repo->git(['checkout', '-q', '-b', 'feature']);
        $repo->commit('only on feature', 'feature.txt');
        $repo->git(['checkout', '-q', 'main']);

        $mainSubjects = array_map(
            fn (Commit $c): string => $c->subject(),
            $this->readAll($this->reader($repo->path)),
        );
        $featureSubjects = array_map(
            fn (Commit $c): string => $c->subject(),
            $this->readAll($this->reader($repo->path, new LogQuery(branch: 'feature'))),
        );

        self::assertNotContains('only on feature', $mainSubjects);
        self::assertContains('only on feature', $featureSubjects);
    }

    public function testUnicodeCommitMessageSurvivesTheRealPipeline(): void
    {
        $subject = 'fix: 日本語のバグ 🎉';
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit($subject);

        $commit = $this->readAll($this->reader($repo->path))[0];

        self::assertSame($subject, $commit->subject());
        self::assertTrue(mb_check_encoding($commit->rawBody, 'UTF-8'));
    }

    /**
     * These are ordinary in commit subjects and all special in ICS TEXT.
     */
    public function testSubjectWithIcsSpecialCharactersSurvives(): void
    {
        $subject = 'fix: a,b; c\\d and "quotes"';
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit($subject);

        self::assertSame($subject, $this->readAll($this->reader($repo->path))[0]->subject());
    }

    public function testEmptyCommitMessageYieldsEmptySubject(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('real commit', 'a.txt');
        $repo->commitAllowingEmptyMessage();

        self::assertSame('', $this->readAll($this->reader($repo->path))[0]->subject());
    }

    public function testInspectReportsTheRemote(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('c');
        $repo->setRemote('git@github.com:craigk5n/ilibgo.git');

        self::assertSame('git@github.com:craigk5n/ilibgo.git', $this->reader($repo->path)->inspect()->remote);
    }

    public function testInspectReportsNullRemoteWhenNoOriginIsConfigured(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('c');

        self::assertNull($this->reader($repo->path)->inspect()->remote);
    }

    public function testInspectReportsTheRepositoryName(): void
    {
        $repo = GitRepoFixture::create($this->repoPath('my-project'));
        $repo->commit('c');

        self::assertSame('my-project', $this->reader($repo->path)->inspect()->name);
    }

    public function testInspectReportsANormalCloneAsNotShallow(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('c');

        self::assertFalse($this->reader($repo->path)->inspect()->isShallow);
    }

    /**
     * A shallow clone silently truncates history. Detecting it lets the CLI say
     * so, rather than letting the calendar imply the project began later than it did.
     */
    public function testShallowCloneIsDetected(): void
    {
        $origin = GitRepoFixture::create($this->repoPath('origin'));
        $origin->commit('first', 'a.txt');
        $origin->commit('second', 'b.txt');
        $origin->commit('third', 'c.txt');

        $shallowPath = $this->repoPath('shallow');
        $clone = GitRepoFixture::create($this->repoPath('cloner'));
        $clone->git(['clone', '-q', '--depth', '1', 'file://' . $origin->path, $shallowPath]);

        $info = $this->reader($shallowPath)->inspect();

        self::assertTrue($info->isShallow);
        self::assertCount(1, $this->readAll($this->reader($shallowPath)), 'history is truncated');
    }

    /**
     * git exiting non-zero for a reason we did not anticipate must surface as a
     * clear error carrying git's own stderr, not an empty result.
     */
    public function testUnknownRevisionIsReportedWithGitsOwnMessage(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('c');

        $this->expectException(GitException::class);
        $this->expectExceptionMessageMatches('/no-such-branch/');

        $this->readAll($this->reader($repo->path, new LogQuery(branch: 'no-such-branch')));
    }

    public function testFailureMessageIncludesTheExitCode(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('c');

        try {
            $this->readAll($this->reader($repo->path, new LogQuery(branch: 'no-such-branch')));
            self::fail('expected a GitException');
        } catch (GitException $e) {
            self::assertMatchesRegularExpression('/exit \d+/', $e->getMessage());
        }
    }

    /**
     * A bare repository has no working tree, so --show-toplevel fails and the
     * name must fall back to the directory.
     */
    public function testBareRepositoryIsReadable(): void
    {
        $origin = GitRepoFixture::create($this->repoPath('origin'));
        $origin->commit('first', 'a.txt');
        $origin->commit('second', 'b.txt');

        $barePath = $this->repoPath('bare.git');
        $cloner = GitRepoFixture::create($this->repoPath('cloner'));
        $cloner->git(['clone', '-q', '--bare', 'file://' . $origin->path, $barePath]);

        $reader = $this->reader($barePath);

        self::assertCount(2, $this->readAll($reader));
        self::assertSame('bare.git', $reader->inspect()->name);
    }

    public function testReadIsLazy(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $repo->commit('c');

        self::assertInstanceOf(\Generator::class, $this->reader($repo->path)->read());
    }

    /**
     * Larger than the ~64KB pipe buffer that would deadlock a naive
     * read-stdout-then-stderr implementation.
     */
    public function testLargeOutputDoesNotDeadlock(): void
    {
        $repo = GitRepoFixture::create($this->repoPath());
        $body = str_repeat('padding line to inflate the log output. ', 20);

        foreach (range(1, 60) as $i) {
            $repo->commit("commit {$i}\n\n{$body}", "file{$i}.txt");
        }

        $commits = $this->readAll($this->reader($repo->path));

        self::assertCount(60, $commits);
    }
}
