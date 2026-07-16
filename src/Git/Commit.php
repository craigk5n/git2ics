<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

use DateTimeImmutable;

/**
 * A single commit, as read from `git log`.
 *
 * Reports facts only. Presentation decisions — falling back to the short SHA
 * when a message is empty, formatting a DESCRIPTION — belong to the mappers.
 */
final readonly class Commit
{
    /**
     * @param list<string> $parents Parent SHAs: empty for a root commit, >1 for a merge.
     * @param string       $rawBody The full message (`%B`) with its trailing newline removed.
     */
    public function __construct(
        public string $sha,
        public DateTimeImmutable $authorDate,
        public DateTimeImmutable $committerDate,
        public string $authorName,
        public string $authorEmail,
        public string $committerName,
        public string $committerEmail,
        public array $parents,
        public string $rawBody,
    ) {
    }

    /**
     * The first line of the message. May be empty: `git commit --allow-empty-message`.
     */
    public function subject(): string
    {
        $break = strpos($this->rawBody, "\n");

        return $break === false ? $this->rawBody : substr($this->rawBody, 0, $break);
    }

    /**
     * Everything after the subject line, with the blank separator line removed.
     */
    public function body(): string
    {
        $break = strpos($this->rawBody, "\n");

        return $break === false ? '' : trim(substr($this->rawBody, $break + 1), "\n");
    }

    public function shortSha(): string
    {
        return substr($this->sha, 0, 7);
    }

    public function isMerge(): bool
    {
        return count($this->parents) > 1;
    }

    public function isRoot(): bool
    {
        return $this->parents === [];
    }
}
