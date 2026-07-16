<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Calendar;

use Icalendar\Validation\SecurityValidator;
use K5n\Git2Ics\Git\Commit;
use K5n\Git2Ics\Git\CommitUrlBuilder;

/**
 * Builds the DESCRIPTION text for a commit: its body, then the metadata that
 * does not fit in a one-line SUMMARY.
 *
 * The subject is omitted — it is already the SUMMARY.
 */
final readonly class DescriptionFormatter
{
    private SecurityValidator $securityValidator;

    public function __construct(private CommitUrlBuilder $urlBuilder)
    {
        $this->securityValidator = new SecurityValidator();
    }

    public function format(Commit $commit): string
    {
        $sections = [];

        $body = $commit->body();
        if ($body !== '') {
            $sections[] = $body;
        }

        $sections[] = implode("\n", $this->metadataLines($commit));

        return $this->sanitise(implode("\n\n", $sections));
    }

    /**
     * @return list<string>
     */
    private function metadataLines(Commit $commit): array
    {
        $lines = [
            'Commit: ' . $commit->sha,
            sprintf('Author: %s <%s>', $commit->authorName, $commit->authorEmail),
        ];

        // Only worth a line when a rebase or a patch-applier made them differ.
        if ($this->committerDiffers($commit)) {
            $lines[] = sprintf('Committer: %s <%s>', $commit->committerName, $commit->committerEmail);
        }

        if ($commit->isMerge()) {
            $lines[] = sprintf('Merge of %d parents', count($commit->parents));
        }

        $url = $this->urlBuilder->forSha($commit->sha);
        if ($url !== null) {
            $lines[] = $url;
        }

        return $lines;
    }

    private function committerDiffers(Commit $commit): bool
    {
        return $commit->committerName !== $commit->authorName
            || $commit->committerEmail !== $commit->authorEmail;
    }

    /**
     * Control characters are illegal in an ICS TEXT value and git permits them
     * in a message. php-icalendar-core ships SecurityValidator but its Writer
     * never calls it, so this is our responsibility.
     */
    private function sanitise(string $text): string
    {
        return $this->securityValidator->sanitizeText($text);
    }
}
