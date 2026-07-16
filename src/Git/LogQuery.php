<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

/**
 * Which commits to select. Mirrors the subset of `git log` filters git2ics exposes.
 */
final readonly class LogQuery
{
    /**
     * @param string|null  $branch  A revision. Ignored when $allRefs is true.
     * @param list<string> $paths   Path filters, passed after `--`.
     */
    public function __construct(
        public ?string $since = null,
        public ?string $until = null,
        public ?string $author = null,
        public ?string $branch = null,
        public bool $allRefs = false,
        public ?int $maxCount = null,
        public bool $noMerges = false,
        public array $paths = [],
    ) {
    }
}
