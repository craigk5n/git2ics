<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

use Generator;
use K5n\Git2Ics\Exception\GitException;

/**
 * Reads commits by shelling out to the git CLI.
 *
 * Shelling out rather than using a native git library: `git log` handles every
 * repository git handles, and the only thing we need from it is a formatted log.
 */
final readonly class GitCliCommitReader implements CommitReader
{
    public function __construct(
        private GitCommandRunner $runner,
        private GitLogCommand $command,
        private CommitLogParser $parser,
        private LogQuery $query,
        private string $path,
    ) {
    }

    #[\Override]
    public function read(): Generator
    {
        $info = $this->inspect();

        // An empty repository is not an error — it simply has nothing to report,
        // and an empty calendar is the honest answer. Without this guard `git
        // log` would exit 128 and look like a failure.
        if (!$info->hasCommits) {
            return;
        }

        yield from $this->parser->parse($this->runner->run($this->command->build($this->query)));
    }

    #[\Override]
    public function inspect(): RepositoryInfo
    {
        $this->assertIsRepository();

        return new RepositoryInfo(
            path: $this->path,
            name: $this->repositoryName(),
            remote: $this->remote(),
            isShallow: $this->isShallow(),
            hasCommits: $this->hasCommits(),
        );
    }

    /**
     * `git log` exits 128 both for a repository with no commits and for a path
     * that is not a repository at all, so the exit code alone cannot tell them
     * apart. Probing --git-dir separates the two before we ever run log.
     */
    private function assertIsRepository(): void
    {
        if ($this->runner->tryRun(['rev-parse', '--git-dir'])['exitCode'] !== 0) {
            throw new GitException(sprintf('not a git repository: %s', $this->path));
        }
    }

    private function hasCommits(): bool
    {
        return $this->runner->tryRun(['rev-parse', '--verify', 'HEAD'])['exitCode'] === 0;
    }

    private function isShallow(): bool
    {
        $result = $this->runner->tryRun(['rev-parse', '--is-shallow-repository']);

        return trim($result['stdout']) === 'true';
    }

    private function remote(): ?string
    {
        $result = $this->runner->tryRun(['remote', 'get-url', 'origin']);

        if ($result['exitCode'] !== 0) {
            return null; // no origin configured is perfectly normal
        }

        $remote = trim($result['stdout']);

        return $remote === '' ? null : $remote;
    }

    /**
     * Prefers the remote's repository name over the directory name, since a
     * working copy is often checked out under a different folder name.
     */
    private function repositoryName(): string
    {
        $toplevel = $this->runner->tryRun(['rev-parse', '--show-toplevel']);

        if ($toplevel['exitCode'] === 0 && trim($toplevel['stdout']) !== '') {
            return basename(trim($toplevel['stdout']));
        }

        // A bare repository has no working tree; fall back to the given path.
        return basename(rtrim($this->path, '/'));
    }
}
