<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Integration;

use RuntimeException;

/**
 * Builds throwaway git repositories with byte-for-byte reproducible SHAs.
 *
 * A commit SHA hashes the tree, message, author and committer identities *and*
 * their timestamps, so pinning all of those makes the SHAs deterministic and
 * golden files viable.
 *
 * The subtle part is the environment. Without GIT_CONFIG_GLOBAL and
 * GIT_CONFIG_SYSTEM pointed at /dev/null, a developer's own ~/.gitconfig leaks
 * in — commit.gpgsign or core.autocrlf change the resulting SHAs, so fixtures
 * pass locally and fail in CI (or vice versa) for reasons that look like magic.
 */
final class GitRepoFixture
{
    private const NAME = 'Craig';
    private const EMAIL = 'c@k5n.us';

    private function __construct(public readonly string $path)
    {
    }

    public static function create(string $path): self
    {
        if (!mkdir($path, 0o777, true) && !is_dir($path)) {
            throw new RuntimeException("could not create fixture directory {$path}");
        }

        $fixture = new self($path);
        // --template= suppresses sample hooks, which would otherwise vary by host.
        $fixture->git(['init', '-q', '-b', 'main', '--template=', '.']);

        return $fixture;
    }

    /**
     * @param list<string> $args
     */
    public function git(array $args, string $authorDate = '2026-01-01T10:00:00+00:00', ?string $committerDate = null): string
    {
        $committerDate ??= $authorDate;

        $env = [
            'PATH' => getenv('PATH') !== false ? getenv('PATH') : '/usr/bin:/bin',
            'HOME' => $this->path,
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
            'LC_ALL' => 'C.UTF-8',
            'GIT_AUTHOR_NAME' => self::NAME,
            'GIT_AUTHOR_EMAIL' => self::EMAIL,
            'GIT_COMMITTER_NAME' => self::NAME,
            'GIT_COMMITTER_EMAIL' => self::EMAIL,
            'GIT_AUTHOR_DATE' => $authorDate,
            'GIT_COMMITTER_DATE' => $committerDate,
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['git', ...$args], $descriptors, $pipes, $this->path, $env);

        if (!is_resource($process)) {
            throw new RuntimeException('could not start git');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new RuntimeException(sprintf('git %s failed: %s', implode(' ', $args), $stderr));
        }

        return $stdout;
    }

    public function commit(
        string $message,
        string $file = 'file.txt',
        string $contents = 'contents',
        string $authorDate = '2026-01-01T10:00:00+00:00',
        ?string $committerDate = null,
    ): string {
        file_put_contents($this->path . '/' . $file, $contents);
        $this->git(['add', $file], $authorDate, $committerDate);
        $this->git(['commit', '-q', '-m', $message], $authorDate, $committerDate);

        return trim($this->git(['rev-parse', 'HEAD'], $authorDate, $committerDate));
    }

    public function commitAllowingEmptyMessage(string $file = 'empty-msg.txt'): string
    {
        file_put_contents($this->path . '/' . $file, 'x');
        $this->git(['add', $file]);
        $this->git(['commit', '-q', '--allow-empty-message', '-m', '']);

        return trim($this->git(['rev-parse', 'HEAD']));
    }

    public function setRemote(string $url): void
    {
        $this->git(['remote', 'add', 'origin', $url]);
    }

    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
