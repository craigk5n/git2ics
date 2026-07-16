<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

use Generator;

/**
 * Supplies commits. The port that keeps everything downstream free of I/O.
 */
interface CommitReader
{
    /**
     * @return Generator<int, Commit> Newest first, as `git log` orders them.
     */
    public function read(): Generator;

    public function inspect(): RepositoryInfo;
}
