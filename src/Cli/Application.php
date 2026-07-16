<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Cli;

use K5n\Git2Ics\Calendar\ComponentMapper;
use K5n\Git2Ics\Calendar\DescriptionFormatter;
use K5n\Git2Ics\Calendar\IcsDateFormatter;
use K5n\Git2Ics\Calendar\StreamingIcsWriter;
use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Calendar\VEventMapper;
use K5n\Git2Ics\Calendar\VJournalMapper;
use K5n\Git2Ics\Clock\ClockInterface;
use K5n\Git2Ics\Clock\SystemClock;
use K5n\Git2Ics\Config\ComponentType;
use K5n\Git2Ics\Config\Config;
use K5n\Git2Ics\Exception\Git2IcsException;
use K5n\Git2Ics\Exception\OutputWriteException;
use K5n\Git2Ics\Exception\UsageException;
use K5n\Git2Ics\Git\CommitLogParser;
use K5n\Git2Ics\Git\CommitUrlBuilder;
use K5n\Git2Ics\Git\GitCliCommitReader;
use K5n\Git2Ics\Git\GitCommandRunner;
use K5n\Git2Ics\Git\GitLogCommand;
use K5n\Git2Ics\Git\RepositoryInfo;
use K5n\Git2Ics\Version;

/**
 * Wires the object graph and runs one invocation.
 *
 * Streams and the clock are injected so the whole CLI is testable end to end
 * without touching real stdout or the real time.
 */
final class Application
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE = 2;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ArgumentParser $parser = new ArgumentParser(),
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            $invocation = $this->parser->parse($argv);

            return match ($invocation->command) {
                Command::Help => $this->emit($this->stdout, Usage::text()),
                Command::Version => $this->emit($this->stdout, 'git2ics ' . Version::VERSION),
                Command::Generate => $this->generate($this->requireConfig($invocation)),
            };
        } catch (UsageException $e) {
            $this->emit($this->stderr, 'git2ics: ' . $e->getMessage());
            $this->emit($this->stderr, "Try 'git2ics --help' for more information.");

            return self::EXIT_USAGE;
        } catch (Git2IcsException $e) {
            $this->emit($this->stderr, 'git2ics: ' . $e->getMessage());

            return self::EXIT_FAILURE;
        }
    }

    private function requireConfig(Invocation $invocation): Config
    {
        // Generate always carries a Config; this narrows the type for analysis.
        $config = $invocation->config;
        assert($config !== null);

        return $config;
    }

    private function generate(Config $config): int
    {
        $reader = $this->reader($config);
        $info = $reader->inspect();

        $this->warnAboutShallowClone($info);

        $output = $this->openOutput($config);

        try {
            (new StreamingIcsWriter())->write(
                $output,
                $reader->read(),
                $this->mapper($config, $info),
                $config->calendarName ?? $info->name,
            );
        } catch (OutputWriteException $e) {
            // `git2ics | head` closes stdout once head has what it wants. That
            // is ordinary shell usage, and every standard tool exits quietly —
            // so complaining here would be noise on a successful command.
            // A failed write to a named file is a genuine error, so re-throw.
            if (!$config->writesToStdout()) {
                throw $e;
            }
        } finally {
            if (!$config->writesToStdout()) {
                fclose($output);
            }
        }

        return self::EXIT_SUCCESS;
    }

    private function reader(Config $config): GitCliCommitReader
    {
        return new GitCliCommitReader(
            runner: new GitCommandRunner($config->repositoryPath),
            command: new GitLogCommand(),
            parser: new CommitLogParser(),
            query: $config->query,
            path: $config->repositoryPath,
        );
    }

    private function mapper(Config $config, RepositoryInfo $info): ComponentMapper
    {
        $urlBuilder = new CommitUrlBuilder($info->remote);
        $uidGenerator = new UidGenerator($config->uidDomain);
        $descriptionFormatter = new DescriptionFormatter($urlBuilder);
        $dateFormatter = new IcsDateFormatter();

        return match ($config->component) {
            ComponentType::Vevent => new VEventMapper(
                uidGenerator: $uidGenerator,
                descriptionFormatter: $descriptionFormatter,
                dateFormatter: $dateFormatter,
                urlBuilder: $urlBuilder,
                clock: $this->clock,
                dateSource: $config->dateSource,
                timezoneMode: $config->timezoneMode,
                duration: $config->duration,
                reproducible: $config->reproducible,
            ),
            ComponentType::Vjournal => new VJournalMapper(
                uidGenerator: $uidGenerator,
                descriptionFormatter: $descriptionFormatter,
                dateFormatter: $dateFormatter,
                urlBuilder: $urlBuilder,
                clock: $this->clock,
                dateSource: $config->dateSource,
                timezoneMode: $config->timezoneMode,
                reproducible: $config->reproducible,
            ),
        };
    }

    /**
     * A shallow clone truncates history silently. Without this the calendar just
     * looks like the project started later than it did.
     */
    private function warnAboutShallowClone(RepositoryInfo $info): void
    {
        if ($info->isShallow) {
            $this->emit(
                $this->stderr,
                'git2ics: warning: this is a shallow clone, so history is truncated. '
                . 'Run `git fetch --unshallow` for the full history.',
            );
        }
    }

    /**
     * @return resource
     */
    private function openOutput(Config $config)
    {
        $file = $config->outputFile;

        if ($file === null) {
            return $this->stdout;
        }

        $handle = @fopen($file, 'wb');

        if ($handle === false) {
            throw new Git2IcsException(sprintf('could not open "%s" for writing', $file));
        }

        return $handle;
    }

    /**
     * @param resource $stream
     */
    private function emit($stream, string $message): int
    {
        fwrite($stream, $message . "\n");

        return self::EXIT_SUCCESS;
    }
}
