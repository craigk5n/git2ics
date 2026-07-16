<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

/**
 * Facts about the repository being read.
 *
 * `isShallow` is data, not a warning: the reader reports it and the CLI decides
 * whether to say anything. A shallow clone silently truncates history, so
 * without surfacing it the calendar just looks like the project started later
 * than it did.
 */
final readonly class RepositoryInfo
{
    public function __construct(
        public string $path,
        public string $name,
        public ?string $remote,
        public bool $isShallow,
        public bool $hasCommits,
    ) {
    }
}
