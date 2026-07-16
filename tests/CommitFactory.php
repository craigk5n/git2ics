<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests;

use DateTimeImmutable;
use K5n\Git2Ics\Git\Commit;

/**
 * Builds Commit fixtures with sensible defaults, so each test names only the
 * fields it actually cares about.
 */
final class CommitFactory
{
    /**
     * @param list<string> $parents
     */
    public static function create(
        string $sha = 'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
        string $authorDate = '2026-01-01T10:00:00+09:00',
        string $committerDate = '2026-01-02T11:30:00-05:00',
        string $authorName = 'Craig',
        string $authorEmail = 'c@k5n.us',
        string $committerName = 'Craig',
        string $committerEmail = 'c@k5n.us',
        array $parents = ['bb5edcf5b722c480fc0adc0b3cab73aa4b80c39e'],
        string $rawBody = 'fix: a representative subject',
    ): Commit {
        return new Commit(
            sha: $sha,
            authorDate: new DateTimeImmutable($authorDate),
            committerDate: new DateTimeImmutable($committerDate),
            authorName: $authorName,
            authorEmail: $authorEmail,
            committerName: $committerName,
            committerEmail: $committerEmail,
            parents: $parents,
            rawBody: $rawBody,
        );
    }
}
