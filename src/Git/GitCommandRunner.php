<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

use K5n\Git2Ics\Exception\GitException;

/**
 * Runs git. The only class in git2ics that touches the outside world.
 *
 * Everything else is pure, so this is the single place where coverage cannot be
 * honest — the proc_open and fread failure paths need fault injection to reach.
 * Keeping it small and dumb is the point.
 */
final readonly class GitCommandRunner
{
    private const READ_CHUNK_BYTES = 65536;

    public function __construct(private string $workingDirectory)
    {
    }

    /**
     * @param list<string> $args argv for git, excluding the binary
     *
     * @throws GitException if git cannot be started or exits non-zero
     */
    public function run(array $args): string
    {
        $result = $this->execute($args);

        if ($result['exitCode'] !== 0) {
            throw new GitException(sprintf(
                'git %s failed (exit %d): %s',
                implode(' ', $args),
                $result['exitCode'],
                trim($result['stderr']) !== '' ? trim($result['stderr']) : '(no output)',
            ));
        }

        return $result['stdout'];
    }

    /**
     * Like run(), but returns the exit code instead of throwing. For probes such
     * as `rev-parse --verify HEAD`, where failure is an answer rather than an error.
     *
     * @param list<string> $args
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public function tryRun(array $args): array
    {
        return $this->execute($args);
    }

    /**
     * @param list<string> $args
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function execute(array $args): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];

        // An array command bypasses the shell entirely: no quoting, no
        // metacharacter handling, no injection surface.
        $process = @proc_open(['git', ...$args], $descriptors, $pipes, $this->workingDirectory);

        // Unreachable without fault injection: git would have to be absent from
        // PATH, or the fork itself would have to fail.
        // @codeCoverageIgnoreStart
        if (!is_resource($process)) {
            throw new GitException(sprintf(
                'could not run git in "%s" (is git installed and the path a directory?)',
                $this->workingDirectory,
            ));
        }
        // @codeCoverageIgnoreEnd

        [$stdout, $stderr] = $this->drain($pipes[1], $pipes[2]);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => proc_close($process),
        ];
    }

    /**
     * Reads both pipes concurrently.
     *
     * Draining stdout to EOF first would deadlock: git blocks once its stderr
     * pipe buffer (~64KB) fills, so it never finishes writing stdout, so we
     * never reach EOF. Both must be read as they fill.
     *
     * @param resource $stdout
     * @param resource $stderr
     *
     * @return array{string, string}
     */
    private function drain($stdout, $stderr): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $buffers = ['out' => '', 'err' => ''];
        $open = ['out' => $stdout, 'err' => $stderr];

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;

            // Only reachable if select is interrupted by a signal.
            // @codeCoverageIgnoreStart
            if (@stream_select($read, $write, $except, null) === false) {
                break;
            }
            // @codeCoverageIgnoreEnd

            foreach ($read as $stream) {
                $key = array_search($stream, $open, true);
                if ($key === false) {
                    continue; // @codeCoverageIgnore
                }

                $chunk = fread($stream, self::READ_CHUNK_BYTES);

                if ($chunk !== false && $chunk !== '') {
                    $buffers[$key] .= $chunk;

                    continue;
                }

                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$key]);
                }
            }
        }

        return [$buffers['out'], $buffers['err']];
    }
}
