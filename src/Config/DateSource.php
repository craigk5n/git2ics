<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Config;

use DateTimeImmutable;
use K5n\Git2Ics\Git\Commit;

/**
 * Which of a commit's two timestamps drives DTSTART.
 */
enum DateSource: string
{
    /**
     * Default. When the work was actually written. Survives rebase, cherry-pick
     * and amend, which is what makes it the honest answer to "when did I work?".
     */
    case Author = 'author';

    /**
     * When the commit entered this branch's history. A rebase collapses every
     * committer date onto the instant of the rebase.
     */
    case Committer = 'committer';

    public function of(Commit $commit): DateTimeImmutable
    {
        return match ($this) {
            self::Author => $commit->authorDate,
            self::Committer => $commit->committerDate,
        };
    }
}
