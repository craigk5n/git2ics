<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use K5n\Git2Ics\Git\Commit;

/**
 * Builds the stable UID for a commit's calendar component.
 *
 * Content-addressed on the full SHA, so regenerating a calendar produces
 * identical UIDs and a re-import updates existing entries rather than
 * duplicating them.
 *
 * The same SHA appearing in a fork or submodule deliberately collides: it is
 * literally the same commit, so collapsing the two is correct.
 */
final readonly class UidGenerator
{
    public const DEFAULT_DOMAIN = 'git2ics.k5n.us';

    public function __construct(private string $domain = self::DEFAULT_DOMAIN)
    {
    }

    public function forCommit(Commit $commit): string
    {
        return $commit->sha . '@' . $this->domain;
    }
}
