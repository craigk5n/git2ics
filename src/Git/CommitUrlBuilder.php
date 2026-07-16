<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

/**
 * Turns a git remote URL into a browsable commit URL.
 *
 * Pure: the remote string is read elsewhere. Only hosts whose URL scheme is
 * actually known are handled — an unrecognised host yields null rather than a
 * guessed link that 404s.
 */
final readonly class CommitUrlBuilder
{
    /** host => path template, where %s is owner/repo and %2$s the SHA. */
    private const HOSTS = [
        'github.com' => 'https://github.com/%1$s/commit/%2$s',
        'gitlab.com' => 'https://gitlab.com/%1$s/-/commit/%2$s',
    ];

    public function __construct(private ?string $remote)
    {
    }

    public function forSha(string $sha): ?string
    {
        if ($this->remote === null || $this->remote === '') {
            return null;
        }

        $parsed = $this->parseRemote($this->remote);
        if ($parsed === null) {
            return null;
        }

        [$host, $path] = $parsed;

        $template = self::HOSTS[$host] ?? null;
        if ($template === null) {
            return null;
        }

        return sprintf($template, $path, $sha);
    }

    /**
     * Handles the three shapes git remotes actually take:
     *   git@host:owner/repo.git          (scp-like)
     *   ssh://git@host/owner/repo.git
     *   https://host/owner/repo(.git)
     *
     * @return array{string, string}|null [host, owner/repo]
     */
    private function parseRemote(string $remote): ?array
    {
        // scp-like syntax is not a URL and parse_url() cannot read it.
        if (preg_match('#^[^/@]+@([^:/]+):(.+)$#', $remote, $matches) === 1) {
            $host = $matches[1];
            $path = $matches[2];
        } else {
            $host = parse_url($remote, PHP_URL_HOST);
            $path = parse_url($remote, PHP_URL_PATH);

            if (!is_string($host) || !is_string($path)) {
                return null;
            }
        }

        $path = $this->normalisePath($path);

        // A commit URL needs owner/repo. A bare host, or a host with only one
        // path segment, cannot produce a working link — so decline rather than
        // emit something like https://github.com//commit/<sha>.
        if (!str_contains($path, '/')) {
            return null;
        }

        return [$host, $path];
    }

    private function normalisePath(string $path): string
    {
        $path = trim($path, '/');

        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return $path;
    }
}
