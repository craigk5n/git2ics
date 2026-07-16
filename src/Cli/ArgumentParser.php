<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Cli;

use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Config\ComponentType;
use K5n\Git2Ics\Config\Config;
use K5n\Git2Ics\Config\DateSource;
use K5n\Git2Ics\Config\DurationSpec;
use K5n\Git2Ics\Config\TimezoneMode;
use K5n\Git2Ics\Exception\GitException;
use K5n\Git2Ics\Exception\UsageException;
use K5n\Git2Ics\Git\GitLogCommand;
use K5n\Git2Ics\Git\LogQuery;

/**
 * Turns argv into an Invocation.
 *
 * Hand-rolled rather than getopt(): getopt() reads global state, cannot be
 * driven from an array in tests, and mishandles the `--` separator that path
 * filters depend on.
 */
final class ArgumentParser
{
    /** Long options that take a value. */
    private const VALUE_OPTIONS = [
        'output',
        'component',
        'date',
        'tz',
        'duration',
        'since',
        'until',
        'author',
        'branch',
        'max-count',
        'calendar-name',
        'uid-domain',
    ];

    /** Long options that are switches. */
    private const FLAG_OPTIONS = [
        'all',
        'no-merges',
        'reproducible',
    ];

    private const SHORT_ALIASES = [
        'o' => 'output',
        'c' => 'component',
    ];

    /**
     * @param list<string> $argv Including argv[0], the script name.
     */
    public function parse(array $argv): Invocation
    {
        $arguments = array_slice($argv, 1);

        // Checked before anything else so that `--help` beside a typo still
        // prints help rather than an error about the typo.
        foreach ($arguments as $argument) {
            if ($argument === '--') {
                break;
            }
            if ($argument === '-h' || $argument === '--help') {
                return Invocation::help();
            }
            if ($argument === '-V' || $argument === '--version') {
                return Invocation::version();
            }
        }

        return Invocation::generate($this->buildConfig($arguments));
    }

    /**
     * @param list<string> $arguments
     */
    private function buildConfig(array $arguments): Config
    {
        /** @var array<string, string> $values */
        $values = [];
        /** @var array<string, bool> $flags */
        $flags = [];
        /** @var list<string> $paths */
        $paths = [];
        $repositoryPath = null;

        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if ($argument === '--') {
                // Everything after this is a path, flag-shaped or not.
                $paths = array_slice($arguments, $i + 1);

                break;
            }

            if (str_starts_with($argument, '--')) {
                $i = $this->readLongOption($argument, $arguments, $i, $values, $flags);

                continue;
            }

            if (str_starts_with($argument, '-') && $argument !== '-') {
                $i = $this->readShortOption($argument, $arguments, $i, $values);

                continue;
            }

            if ($repositoryPath !== null) {
                throw new UsageException(sprintf(
                    'unexpected argument "%s": git2ics reads one repository at a time',
                    $argument,
                ));
            }

            $repositoryPath = $argument;
        }

        return $this->assemble($values, $flags, $paths, $repositoryPath);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $values
     * @param array<string, bool>   $flags
     *
     * @return int the index to continue from
     */
    private function readLongOption(string $argument, array $arguments, int $index, array &$values, array &$flags): int
    {
        // With a limit of 2, index 1 exists only when there was an `=`.
        $parts = explode('=', substr($argument, 2), 2);
        $name = $parts[0];
        $value = $parts[1] ?? null;

        if (in_array($name, self::FLAG_OPTIONS, true)) {
            if ($value !== null) {
                throw new UsageException(sprintf('--%s does not take a value', $name));
            }
            $flags[$name] = true;

            return $index;
        }

        if (!in_array($name, self::VALUE_OPTIONS, true)) {
            throw new UsageException(sprintf('unknown option "--%s"', $name));
        }

        if ($value === null) {
            $value = $arguments[$index + 1] ?? null;

            if ($value === null) {
                throw new UsageException(sprintf('--%s requires a value', $name));
            }

            $index++;
        }

        $values[$name] = $this->requireNonEmpty($name, $value);

        return $index;
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $values
     *
     * @return int the index to continue from
     */
    private function readShortOption(string $argument, array $arguments, int $index, array &$values): int
    {
        $letter = substr($argument, 1);
        $name = self::SHORT_ALIASES[$letter] ?? null;

        if ($name === null) {
            throw new UsageException(sprintf('unknown option "-%s"', $letter));
        }

        $value = $arguments[$index + 1] ?? null;

        if ($value === null) {
            throw new UsageException(sprintf('-%s requires a value', $letter));
        }

        $values[$name] = $this->requireNonEmpty($name, $value);

        return $index + 1;
    }

    private function requireNonEmpty(string $name, string $value): string
    {
        if (trim($value) === '') {
            throw new UsageException(sprintf('--%s requires a non-empty value', $name));
        }

        return $value;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, bool>   $flags
     * @param list<string>          $paths
     */
    private function assemble(array $values, array $flags, array $paths, ?string $repositoryPath): Config
    {
        $branch = $values['branch'] ?? null;
        if ($branch !== null) {
            // GitLogCommand owns the rule, since it is what builds the argv.
            // Surface it as a usage error: it is bad input, not a git failure.
            try {
                (new GitLogCommand())->build(new LogQuery(branch: $branch));
            } catch (GitException $e) {
                throw new UsageException($e->getMessage(), previous: $e);
            }
        }

        return new Config(
            repositoryPath: $repositoryPath ?? '.',
            outputFile: $values['output'] ?? null,
            component: $this->enumValue(ComponentType::class, 'component', $values['component'] ?? null, ComponentType::Vevent),
            dateSource: $this->enumValue(DateSource::class, 'date', $values['date'] ?? null, DateSource::Author),
            timezoneMode: $this->enumValue(TimezoneMode::class, 'tz', $values['tz'] ?? null, TimezoneMode::Utc),
            duration: isset($values['duration']) ? DurationSpec::toIso8601($values['duration']) : null,
            query: new LogQuery(
                since: $values['since'] ?? null,
                until: $values['until'] ?? null,
                author: $values['author'] ?? null,
                branch: $branch,
                allRefs: $flags['all'] ?? false,
                maxCount: isset($values['max-count']) ? $this->positiveInt('max-count', $values['max-count']) : null,
                noMerges: $flags['no-merges'] ?? false,
                paths: $paths,
            ),
            calendarName: $values['calendar-name'] ?? null,
            uidDomain: $values['uid-domain'] ?? UidGenerator::DEFAULT_DOMAIN,
            reproducible: $flags['reproducible'] ?? false,
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     * @param T               $default
     *
     * @return T
     */
    private function enumValue(string $enum, string $option, ?string $value, \BackedEnum $default): \BackedEnum
    {
        if ($value === null) {
            return $default;
        }

        $resolved = $enum::tryFrom(strtolower($value));

        if ($resolved === null) {
            throw new UsageException(sprintf(
                'invalid --%s "%s": expected one of %s',
                $option,
                $value,
                implode(', ', array_map(
                    static fn (\BackedEnum $case): string => (string) $case->value,
                    $enum::cases(),
                )),
            ));
        }

        return $resolved;
    }

    private function positiveInt(string $option, string $value): int
    {
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            throw new UsageException(sprintf('invalid --%s "%s": expected a positive integer', $option, $value));
        }

        return (int) $value;
    }
}
