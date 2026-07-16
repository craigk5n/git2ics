<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Integration;

use Icalendar\Parser\Parser;
use K5n\Git2Ics\Cli\Application;
use K5n\Git2Ics\Cli\Usage;
use K5n\Git2Ics\Clock\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The whole program, from argv to ICS bytes, against real repositories.
 */
#[CoversClass(Application::class)]
#[CoversClass(Usage::class)]
final class ApplicationTest extends TestCase
{
    private const NOW = '2026-07-16T15:11:22+00:00';

    private string $workspace;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/git2ics-app-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0o777, true);

        $stdout = fopen('php://memory', 'w+b');
        $stderr = fopen('php://memory', 'w+b');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    protected function tearDown(): void
    {
        GitRepoFixture::remove($this->workspace);
    }

    /**
     * @param list<string> $args
     */
    private function invoke(array $args): int
    {
        return (new Application($this->stdout, $this->stderr, FrozenClock::at(self::NOW)))
            ->run(['bin/git2ics', ...$args]);
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function out(): string
    {
        return $this->contents($this->stdout);
    }

    private function err(): string
    {
        return $this->contents($this->stderr);
    }

    private function repo(string $name = 'repo'): GitRepoFixture
    {
        return GitRepoFixture::create($this->workspace . '/' . $name);
    }

    public function testGeneratesACalendarFromARealRepository(): void
    {
        $repo = $this->repo();
        $repo->commit('first subject', 'a.txt');
        $repo->commit('second subject', 'b.txt');

        $exit = $this->invoke([$repo->path]);

        self::assertSame(Application::EXIT_SUCCESS, $exit);

        $calendar = (new Parser(Parser::STRICT))->parse($this->out());
        self::assertCount(2, $calendar->getComponents('VEVENT'));
    }

    public function testCalendarNameDefaultsToTheRepositoryName(): void
    {
        $repo = $this->repo('my-project');
        $repo->commit('c');

        $this->invoke([$repo->path]);

        self::assertStringContainsString('X-WR-CALNAME:my-project', $this->out());
    }

    public function testCalendarNameCanBeOverridden(): void
    {
        $repo = $this->repo();
        $repo->commit('c');

        $this->invoke([$repo->path, '--calendar-name=My Work']);

        self::assertStringContainsString('X-WR-CALNAME:My Work', $this->out());
    }

    public function testEventsAreZeroDurationByDefault(): void
    {
        $repo = $this->repo();
        $repo->commit('c');

        $this->invoke([$repo->path]);

        self::assertStringNotContainsString('DTEND', $this->out());
        self::assertStringNotContainsString('DURATION', $this->out());
    }

    public function testDurationFlagAddsDtEnd(): void
    {
        $repo = $this->repo();
        $repo->commit('c', 'a.txt', 'x', authorDate: '2026-01-01T10:00:00+00:00', committerDate: '2026-01-01T10:00:00+00:00');

        $this->invoke([$repo->path, '--duration=15m']);

        self::assertStringContainsString('DTSTART:20260101T100000', $this->out());
        self::assertStringContainsString('DTEND:20260101T101500', $this->out());
    }

    /**
     * git gives an author date with an explicit offset, so the instant is
     * unambiguous. UTC carries it across without loss.
     */
    public function testUtcIsTheDefault(): void
    {
        $repo = $this->repo();
        $repo->commit('c', 'a.txt', 'x', authorDate: '2026-01-01T10:00:00+09:00', committerDate: '2026-01-01T10:00:00+09:00');

        $this->invoke([$repo->path]);

        self::assertStringContainsString("DTSTART:20260101T010000Z\r\n", $this->out());
    }

    public function testLocalModeEmitsFloatingTime(): void
    {
        $repo = $this->repo();
        $repo->commit('c', 'a.txt', 'x', authorDate: '2026-01-01T10:00:00+09:00', committerDate: '2026-01-01T10:00:00+09:00');

        $this->invoke([$repo->path, '--tz=local']);

        self::assertStringContainsString("DTSTART:20260101T100000\r\n", $this->out());
    }

    /**
     * The end-to-end consequence of the default: what we write is what a parser
     * reads back. Floating output is corrupted on the way back in by
     * php-icalendar-core 1.1.5 (ISSUES.md #1); UTC is not.
     */
    public function testDefaultOutputSurvivesAParseRoundTrip(): void
    {
        $repo = $this->repo();
        $repo->commit('c', 'a.txt', 'x', authorDate: '2026-01-01T10:00:00+09:00', committerDate: '2026-01-01T10:00:00+09:00');

        $this->invoke([$repo->path]);

        $calendar = (new Parser(Parser::STRICT))->parse($this->out());
        $dtstart = $calendar->getComponents('VEVENT')[0]->getProperty('DTSTART')?->getValue()->getRawValue();

        self::assertSame('20260101T010000Z', $dtstart, 'the instant must survive being read back');
    }

    public function testVjournalComponent(): void
    {
        $repo = $this->repo();
        $repo->commit('c');

        $this->invoke([$repo->path, '--component=vjournal']);

        $calendar = (new Parser(Parser::STRICT))->parse($this->out());
        self::assertCount(1, $calendar->getComponents('VJOURNAL'));
        self::assertSame([], $calendar->getComponents('VEVENT'));
    }

    public function testOutputFileIsWritten(): void
    {
        $repo = $this->repo();
        $repo->commit('c');
        $target = $this->workspace . '/out.ics';

        $exit = $this->invoke([$repo->path, '-o', $target]);

        self::assertSame(Application::EXIT_SUCCESS, $exit);
        self::assertFileExists($target);
        self::assertSame('', $this->out(), 'nothing goes to stdout when -o is used');

        $calendar = (new Parser(Parser::STRICT))->parse((string) file_get_contents($target));
        self::assertCount(1, $calendar->getComponents('VEVENT'));
    }

    /**
     * `git2ics | head` closes stdout the moment head has its lines. Every
     * standard tool exits quietly when that happens; complaining would put an
     * error on the terminal after a command that did exactly what was asked.
     *
     * Driven through the real binary because the behaviour only exists when a
     * real reader closes a real pipe.
     */
    public function testClosedStdoutPipeExitsQuietly(): void
    {
        $repo = $this->repo();
        foreach (range(1, 30) as $i) {
            $repo->commit("commit {$i}", "file{$i}.txt");
        }

        $binary = dirname(__DIR__, 2) . '/bin/git2ics';
        $command = sprintf('%s %s 2>/tmp/git2ics-pipe-err.txt | head -3', escapeshellarg($binary), escapeshellarg($repo->path));

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, 'a closed stdout pipe must not be an error');
        self::assertSame(
            '',
            trim((string) @file_get_contents('/tmp/git2ics-pipe-err.txt')),
            'nothing should be printed to stderr when a reader simply stops reading',
        );
        self::assertSame('BEGIN:VCALENDAR', $output[0] ?? null);
    }

    /**
     * The same behaviour as testClosedStdoutPipeExitsQuietly, but in-process so
     * it is visible to coverage. A read-only stream makes every fwrite fail,
     * exactly as a closed pipe does.
     */
    public function testWriteFailureOnStdoutIsSilent(): void
    {
        $repo = $this->repo();
        $repo->commit('c');

        $unwritable = fopen('php://memory', 'r');
        self::assertIsResource($unwritable);

        $exit = (new Application($unwritable, $this->stderr, FrozenClock::at(self::NOW)))
            ->run(['bin/git2ics', $repo->path]);

        self::assertSame(Application::EXIT_SUCCESS, $exit);
        self::assertSame('', $this->err(), 'a reader that stopped reading is not an error');
    }

    /**
     * The other half of the same decision: a write that fails to a named file is
     * a real error and must be reported, however similar it looks internally.
     *
     * /dev/full accepts the open and fails every write with ENOSPC — a genuine
     * disk-full, without needing to fill a disk.
     */
    public function testWriteFailureToAFileIsReported(): void
    {
        if (!file_exists('/dev/full')) {
            self::markTestSkipped('/dev/full is not available on this platform');
        }

        $repo = $this->repo();
        $repo->commit('c');

        $exit = $this->invoke([$repo->path, '-o', '/dev/full']);

        self::assertSame(Application::EXIT_FAILURE, $exit);
        self::assertStringContainsString('could not write', $this->err());
    }

    public function testUnwritableOutputFileIsAnError(): void
    {
        $repo = $this->repo();
        $repo->commit('c');

        $exit = $this->invoke([$repo->path, '-o', $this->workspace . '/no-such-dir/out.ics']);

        self::assertSame(Application::EXIT_FAILURE, $exit);
        self::assertStringContainsString('could not open', $this->err());
    }

    /**
     * --reproducible exists so the output can be committed or diffed; that is
     * only true if two runs really are byte-identical.
     */
    public function testReproducibleOutputIsByteIdenticalAcrossRuns(): void
    {
        $repo = $this->repo();
        $repo->commit('first', 'a.txt');
        $repo->commit('second', 'b.txt');

        $first = $this->workspace . '/one.ics';
        $second = $this->workspace . '/two.ics';

        $this->invoke([$repo->path, '--reproducible', '-o', $first]);
        $this->invoke([$repo->path, '--reproducible', '-o', $second]);

        self::assertFileEquals($first, $second);
        self::assertStringNotContainsString('20260716T151122Z', (string) file_get_contents($first), 'DTSTAMP is pinned to the commit, not to now');
    }

    public function testDefaultDtStampIsGenerationTime(): void
    {
        $repo = $this->repo();
        $repo->commit('c');

        $this->invoke([$repo->path]);

        self::assertStringContainsString('DTSTAMP:20260716T151122Z', $this->out());
    }

    public function testShallowCloneEmitsAWarningButStillSucceeds(): void
    {
        $origin = $this->repo('origin');
        $origin->commit('first', 'a.txt');
        $origin->commit('second', 'b.txt');

        $shallow = $this->workspace . '/shallow';
        $cloner = $this->repo('cloner');
        $cloner->git(['clone', '-q', '--depth', '1', 'file://' . $origin->path, $shallow]);

        $exit = $this->invoke([$shallow]);

        self::assertSame(Application::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('shallow', $this->err());
        self::assertStringContainsString('unshallow', $this->err(), 'the warning should say how to fix it');
        self::assertStringContainsString('BEGIN:VEVENT', $this->out(), 'a warning must not suppress output');
    }

    public function testEmptyRepositoryProducesAnEmptyCalendar(): void
    {
        $repo = $this->repo();

        $exit = $this->invoke([$repo->path]);

        self::assertSame(Application::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('BEGIN:VCALENDAR', $this->out());
        self::assertStringNotContainsString('BEGIN:VEVENT', $this->out());
    }

    public function testNotARepositoryIsReportedAsAFailure(): void
    {
        $plain = $this->workspace . '/plain';
        mkdir($plain);

        $exit = $this->invoke([$plain]);

        self::assertSame(Application::EXIT_FAILURE, $exit);
        self::assertStringContainsString('not a git repository', $this->err());
        self::assertSame('', $this->out());
    }

    public function testUsageErrorExitsWithCodeTwoAndSuggestsHelp(): void
    {
        $exit = $this->invoke(['--nonsense']);

        self::assertSame(Application::EXIT_USAGE, $exit);
        self::assertStringContainsString('--nonsense', $this->err());
        self::assertStringContainsString('--help', $this->err());
    }

    public function testHelp(): void
    {
        $exit = $this->invoke(['--help']);

        self::assertSame(Application::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('USAGE', $this->out());
        self::assertStringContainsString('--component', $this->out());
        self::assertStringContainsString('--duration', $this->out());
        self::assertSame('', $this->err());
    }

    public function testVersion(): void
    {
        $exit = $this->invoke(['--version']);

        self::assertSame(Application::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('git2ics', $this->out());
    }

    public function testUnicodeSurvivesTheEntirePipeline(): void
    {
        $subject = 'fix: 日本語のバグ 🎉';
        $repo = $this->repo();
        $repo->commit($subject);

        $this->invoke([$repo->path]);
        $ics = $this->out();

        self::assertTrue(mb_check_encoding($ics, 'UTF-8'), 'output must be valid UTF-8');

        $calendar = (new Parser(Parser::STRICT))->parse($ics);
        self::assertSame(
            $subject,
            $calendar->getComponents('VEVENT')[0]->getProperty('SUMMARY')?->getValue()->getRawValue(),
        );
    }

    public function testCommitUrlIsDerivedFromTheRemote(): void
    {
        $repo = $this->repo();
        $sha = $repo->commit('c');
        $repo->setRemote('git@github.com:craigk5n/ilibgo.git');

        $this->invoke([$repo->path]);

        self::assertStringContainsString('github.com/craigk5n/ilibgo/commit', str_replace("\r\n ", '', $this->out()));
    }

    public function testLogFiltersAreApplied(): void
    {
        $repo = $this->repo();
        $repo->commit('old', 'old.txt', 'x', authorDate: '2020-01-01T10:00:00+00:00', committerDate: '2020-01-01T10:00:00+00:00');
        $repo->commit('new', 'new.txt', 'x', authorDate: '2026-01-01T10:00:00+00:00', committerDate: '2026-01-01T10:00:00+00:00');

        $this->invoke([$repo->path, '--since=2025-01-01']);

        self::assertStringContainsString('SUMMARY:new', $this->out());
        self::assertStringNotContainsString('SUMMARY:old', $this->out());
    }
}
