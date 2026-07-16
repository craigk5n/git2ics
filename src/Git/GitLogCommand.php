<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Git;

use K5n\Git2Ics\Exception\GitException;

/**
 * Builds the argv for `git log`.
 *
 * Pure, and deliberately separate from GitCommandRunner: argument construction
 * carries all the interesting decisions (ordering, flag injection, the path
 * separator) and can be tested exhaustively without running anything.
 */
final class GitLogCommand
{
    /**
     * %H %aI %cI %an %ae %cn %ce %P %B, separated by 0x1f.
     *
     * %B is last so that a 0x1f inside a commit message is absorbed by the
     * parser's explode limit rather than shifting every later field. %s is
     * deliberately absent: it would sit before %B and reintroduce that hazard,
     * and it is redundant because %B already starts with the subject.
     */
    public const FORMAT = '%H%x1f%aI%x1f%cI%x1f%an%x1f%ae%x1f%cn%x1f%ce%x1f%P%x1f%B';

    /**
     * @return list<string> argv for git, excluding the `git` binary itself
     *
     * @throws GitException if the branch could be mistaken for an option
     */
    public function build(LogQuery $query): array
    {
        $args = [
            // Pin the output encoding so a user's i18n.logOutputEncoding cannot
            // hand us latin-1 and make every commit message invalid UTF-8.
            '-c',
            'i18n.logOutputEncoding=UTF-8',
            'log',
            // -z: NUL-delimit records. tformat: terminate rather than separate.
            '-z',
            '--pretty=tformat:' . self::FORMAT,
        ];

        if ($query->noMerges) {
            $args[] = '--no-merges';
        }

        if ($query->maxCount !== null) {
            $args[] = '--max-count=' . $query->maxCount;
        }

        if ($query->since !== null) {
            $args[] = '--since=' . $query->since;
        }

        if ($query->until !== null) {
            $args[] = '--until=' . $query->until;
        }

        if ($query->author !== null) {
            $args[] = '--author=' . $query->author;
        }

        if ($query->allRefs) {
            $args[] = '--all';
        } elseif ($query->branch !== null) {
            $args[] = $this->validateBranch($query->branch);
        }

        if ($query->paths !== []) {
            // Everything after -- is a path, so a flag-shaped path is inert.
            $args[] = '--';
            foreach ($query->paths as $path) {
                $args[] = $path;
            }
        }

        return $args;
    }

    /**
     * A branch is passed as a bare revision, so a leading `-` would make git read
     * it as an option (`--upload-pack=...` being the well-known attack). Nothing
     * here ever reaches a shell, but this is argv injection, not shell injection.
     */
    private function validateBranch(string $branch): string
    {
        if (str_starts_with($branch, '-')) {
            throw new GitException(sprintf(
                'invalid branch "%s": a branch cannot begin with "-", which git would read as an option',
                $branch,
            ));
        }

        return $branch;
    }
}
