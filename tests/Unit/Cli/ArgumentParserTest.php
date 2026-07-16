<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Cli;

use K5n\Git2Ics\Cli\ArgumentParser;
use K5n\Git2Ics\Cli\Command;
use K5n\Git2Ics\Cli\Invocation;
use K5n\Git2Ics\Config\ComponentType;
use K5n\Git2Ics\Config\Config;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Exception\UsageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArgumentParser::class)]
#[CoversClass(Config::class)]
#[CoversClass(Command::class)]
#[CoversClass(Invocation::class)]
final class ArgumentParserTest extends TestCase
{
    private ArgumentParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ArgumentParser();
    }

    /**
     * argv[0] is the script name; the parser must skip it.
     *
     * @param list<string> $args
     */
    private function parse(array $args): Config
    {
        $invocation = $this->parser->parse(['bin/git2ics', ...$args]);

        self::assertSame(Command::Generate, $invocation->command);
        self::assertNotNull($invocation->config);

        return $invocation->config;
    }

    public function testDefaults(): void
    {
        $config = $this->parse([]);

        self::assertSame('.', $config->repositoryPath);
        self::assertNull($config->outputFile);
        self::assertTrue($config->writesToStdout());
        self::assertSame(ComponentType::Vevent, $config->component);
        self::assertSame(DateSource::Author, $config->dateSource);
        self::assertSame(TimezoneMode::Utc, $config->timezoneMode, 'UTC preserves the instant git gives us');
        self::assertNull($config->duration, 'zero-duration is the default');
        self::assertFalse($config->reproducible);
        self::assertSame('git2ics.k5n.us', $config->uidDomain);
    }

    public function testRepositoryPathPositional(): void
    {
        self::assertSame('/home/user/projects/thing', $this->parse(['/home/user/projects/thing'])->repositoryPath);
    }

    public function testSecondPositionalIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/one repository/i');

        $this->parse(['repo-a', 'repo-b']);
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('outputProvider')]
    public function testOutputFile(array $args): void
    {
        self::assertSame('out.ics', $this->parse($args)->outputFile);
        self::assertFalse($this->parse($args)->writesToStdout());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function outputProvider(): iterable
    {
        yield 'long with equals' => [['--output=out.ics']];
        yield 'long with space' => [['--output', 'out.ics']];
        yield 'short with space' => [['-o', 'out.ics']];
    }

    #[DataProvider('componentProvider')]
    public function testComponent(string $arg, ComponentType $expected): void
    {
        self::assertSame($expected, $this->parse([$arg])->component);
    }

    /**
     * @return iterable<string, array{string, ComponentType}>
     */
    public static function componentProvider(): iterable
    {
        yield 'vevent' => ['--component=vevent', ComponentType::Vevent];
        yield 'vjournal' => ['--component=vjournal', ComponentType::Vjournal];
        yield 'uppercase is accepted' => ['--component=VJOURNAL', ComponentType::Vjournal];
    }

    public function testShortComponentFlag(): void
    {
        self::assertSame(ComponentType::Vjournal, $this->parse(['-c', 'vjournal'])->component);
    }

    public function testInvalidComponentListsTheValidValues(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/vevent.*vjournal|vjournal.*vevent/is');

        $this->parse(['--component=vtodo']);
    }

    public function testDateSource(): void
    {
        self::assertSame(DateSource::Author, $this->parse(['--date=author'])->dateSource);
        self::assertSame(DateSource::Committer, $this->parse(['--date=committer'])->dateSource);
    }

    public function testInvalidDateSourceIsRejected(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['--date=whenever']);
    }

    public function testTimezoneMode(): void
    {
        self::assertSame(TimezoneMode::Floating, $this->parse(['--tz=local'])->timezoneMode);
        self::assertSame(TimezoneMode::Utc, $this->parse(['--tz=utc'])->timezoneMode);
    }

    public function testInvalidTimezoneModeIsRejected(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['--tz=Asia/Tokyo']);
    }

    #[DataProvider('durationProvider')]
    public function testDurationIsNormalisedToIso8601(string $spec, string $expected): void
    {
        self::assertSame($expected, $this->parse(["--duration={$spec}"])->duration);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function durationProvider(): iterable
    {
        yield 'minutes' => ['15m', 'PT15M'];
        yield 'hours' => ['1h', 'PT1H'];
        yield 'hours and minutes' => ['1h30m', 'PT1H30M'];
        yield 'seconds' => ['45s', 'PT45S'];
        yield 'all three' => ['2h15m30s', 'PT2H15M30S'];
        yield 'large minute count' => ['90m', 'PT90M'];
        yield 'iso passthrough' => ['PT15M', 'PT15M'];
        yield 'iso lowercase is upcased' => ['pt15m', 'PT15M'];
    }

    #[DataProvider('invalidDurationProvider')]
    public function testInvalidDurationIsRejected(string $spec): void
    {
        $this->expectException(UsageException::class);

        $this->parse(["--duration={$spec}"]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDurationProvider(): iterable
    {
        yield 'words' => ['fifteen minutes'];
        yield 'empty' => [''];
        yield 'zero' => ['0m'];
        yield 'no unit' => ['15'];
        yield 'unknown unit' => ['15d'];
        yield 'wrong order' => ['30m1h'];
    }

    public function testLogFilters(): void
    {
        $config = $this->parse([
            '--since=2026-01-01',
            '--until=2026-06-30',
            '--author=craig',
            '--branch=develop',
            '--max-count=25',
            '--no-merges',
        ]);

        self::assertSame('2026-01-01', $config->query->since);
        self::assertSame('2026-06-30', $config->query->until);
        self::assertSame('craig', $config->query->author);
        self::assertSame('develop', $config->query->branch);
        self::assertSame(25, $config->query->maxCount);
        self::assertTrue($config->query->noMerges);
    }

    public function testAllRefsFlag(): void
    {
        self::assertTrue($this->parse(['--all'])->query->allRefs);
        self::assertFalse($this->parse([])->query->allRefs);
    }

    public function testMaxCountMustBeAPositiveInteger(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/max-count/i');

        $this->parse(['--max-count=zero']);
    }

    public function testMaxCountRejectsZero(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['--max-count=0']);
    }

    /**
     * The boundary: 1 is the smallest useful value and must be accepted.
     */
    public function testMaxCountAcceptsOne(): void
    {
        self::assertSame(1, $this->parse(['--max-count=1'])->query->maxCount);
    }

    /**
     * Without both regex anchors, "12abc" would parse as 12 and silently
     * discard the rest of what the user typed.
     */
    #[DataProvider('nonIntegerMaxCountProvider')]
    public function testMaxCountRejectsPartialNumbers(string $value): void
    {
        $this->expectException(UsageException::class);

        $this->parse(["--max-count={$value}"]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonIntegerMaxCountProvider(): iterable
    {
        yield 'trailing letters' => ['12abc'];
        yield 'leading letters' => ['abc12'];
        yield 'decimal' => ['1.5'];
        yield 'whitespace padded' => ['12 '];
        yield 'plus sign' => ['+12'];
    }

    public function testMaxCountRejectsNegative(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['--max-count=-5']);
    }

    public function testPathsAfterDoubleDash(): void
    {
        $config = $this->parse(['repo', '--', 'src/', 'README.md']);

        self::assertSame('repo', $config->repositoryPath);
        self::assertSame(['src/', 'README.md'], $config->query->paths);
    }

    /**
     * Everything after -- is a path, including things shaped like flags.
     */
    public function testFlagsAfterDoubleDashAreTreatedAsPaths(): void
    {
        $config = $this->parse(['--', '--all', '-o']);

        self::assertSame(['--all', '-o'], $config->query->paths);
        self::assertFalse($config->query->allRefs, '--all after -- is a path, not a flag');
        self::assertNull($config->outputFile);
    }

    public function testCalendarName(): void
    {
        self::assertSame('My Work', $this->parse(['--calendar-name=My Work'])->calendarName);
        self::assertNull($this->parse([])->calendarName);
    }

    public function testUidDomain(): void
    {
        self::assertSame('example.org', $this->parse(['--uid-domain=example.org'])->uidDomain);
    }

    public function testReproducibleFlag(): void
    {
        self::assertTrue($this->parse(['--reproducible'])->reproducible);
    }

    #[DataProvider('helpProvider')]
    public function testHelpIsRequested(string $flag): void
    {
        self::assertSame(Command::Help, $this->parser->parse(['bin/git2ics', $flag])->command);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function helpProvider(): iterable
    {
        yield 'short' => ['-h'];
        yield 'long' => ['--help'];
    }

    #[DataProvider('versionProvider')]
    public function testVersionIsRequested(string $flag): void
    {
        self::assertSame(Command::Version, $this->parser->parse(['bin/git2ics', $flag])->command);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versionProvider(): iterable
    {
        yield 'short' => ['-V'];
        yield 'long' => ['--version'];
    }

    /**
     * Help beats everything, including otherwise-invalid arguments: someone
     * asking for help should get help, not a parse error.
     */
    public function testHelpWinsOverOtherArguments(): void
    {
        self::assertSame(
            Command::Help,
            $this->parser->parse(['bin/git2ics', '--component=nonsense', '--help'])->command,
        );
    }

    /**
     * A switch given a value is a misunderstanding worth reporting rather than
     * silently ignoring.
     */
    public function testFlagOptionGivenAValueIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/--all does not take a value/');

        $this->parse(['--all=yes']);
    }

    public function testReproducibleGivenAValueIsRejected(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['--reproducible=true']);
    }

    /**
     * The help scan must stop at `--` like everything else: a file genuinely
     * named --help is a path, not a request for help.
     */
    public function testHelpAfterDoubleDashIsAPathNotAHelpRequest(): void
    {
        $config = $this->parse(['--', '--help']);

        self::assertSame(['--help'], $config->query->paths);
    }

    public function testVersionAfterDoubleDashIsAPathNotAVersionRequest(): void
    {
        $config = $this->parse(['--', '-V']);

        self::assertSame(['-V'], $config->query->paths);
    }

    public function testUnknownLongOptionIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/--nope/');

        $this->parse(['--nope']);
    }

    public function testUnknownShortOptionIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/-z/');

        $this->parse(['-z']);
    }

    public function testOptionRequiringAValueRejectsAMissingValue(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches('/--output/');

        $this->parse(['--output']);
    }

    public function testShortOptionRequiringAValueRejectsAMissingValue(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['-o']);
    }

    /**
     * An empty --since would silently widen the range rather than filter it.
     */
    public function testEmptyOptionValueIsRejected(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['--since=']);
    }

    /**
     * Whitespace is as empty as empty, and easy to produce by quoting a shell
     * variable that turned out to be unset.
     */
    public function testWhitespaceOnlyOptionValueIsRejected(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['--since=   ']);
    }

    /**
     * Only the first `=` separates name from value, so a value containing `=`
     * survives intact.
     */
    public function testOptionValueMayContainEqualsSigns(): void
    {
        self::assertSame('a=b=c', $this->parse(['--author=a=b=c'])->query->author);
    }

    public function testBranchThatLooksLikeAnOptionIsRejected(): void
    {
        $this->expectException(UsageException::class);

        $this->parse(['--branch=--upload-pack=/bin/sh']);
    }

    public function testRealisticInvocation(): void
    {
        $config = $this->parse([
            '~/programs/ilibgo',
            '--since=2026-01-01',
            '--component=vjournal',
            '--tz=utc',
            '--duration=15m',
            '-o', 'ilibgo.ics',
            '--no-merges',
            '--', 'src/',
        ]);

        self::assertSame('~/programs/ilibgo', $config->repositoryPath);
        self::assertSame('2026-01-01', $config->query->since);
        self::assertSame(ComponentType::Vjournal, $config->component);
        self::assertSame(TimezoneMode::Utc, $config->timezoneMode);
        self::assertSame('PT15M', $config->duration);
        self::assertSame('ilibgo.ics', $config->outputFile);
        self::assertTrue($config->query->noMerges);
        self::assertSame(['src/'], $config->query->paths);
    }
}
