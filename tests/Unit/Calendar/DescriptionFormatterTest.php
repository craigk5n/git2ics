<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use K5n\Git2Ics\Calendar\DescriptionFormatter;
use K5n\Git2Ics\Git\CommitUrlBuilder;
use K5n\Git2Ics\Tests\CommitFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DescriptionFormatter::class)]
final class DescriptionFormatterTest extends TestCase
{
    private function formatter(?string $remote = null): DescriptionFormatter
    {
        return new DescriptionFormatter(new CommitUrlBuilder($remote));
    }

    public function testIncludesTheCommitBody(): void
    {
        $commit = CommitFactory::create(rawBody: "subject line\n\nthe body explains why\nacross two lines");

        $description = $this->formatter()->format($commit);

        self::assertStringContainsString("the body explains why\nacross two lines", $description);
    }

    public function testOmitsTheSubjectFromTheBodySectionBecauseItIsAlreadyTheSummary(): void
    {
        $commit = CommitFactory::create(rawBody: "unique-subject-text\n\nbody text");

        $description = $this->formatter()->format($commit);

        self::assertStringNotContainsString('unique-subject-text', $description);
    }

    public function testIncludesTheFullSha(): void
    {
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7');

        self::assertStringContainsString(
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            $this->formatter()->format($commit),
        );
    }

    public function testIncludesTheAuthor(): void
    {
        $commit = CommitFactory::create(authorName: 'Craig', authorEmail: 'c@k5n.us');

        $description = $this->formatter()->format($commit);

        self::assertStringContainsString('Craig', $description);
        self::assertStringContainsString('c@k5n.us', $description);
    }

    /**
     * Noise reduction: the committer line only earns its place when it differs.
     */
    public function testCommitterIsOmittedWhenItMatchesTheAuthor(): void
    {
        $commit = CommitFactory::create(
            authorName: 'Craig',
            authorEmail: 'c@k5n.us',
            committerName: 'Craig',
            committerEmail: 'c@k5n.us',
        );

        self::assertStringNotContainsString('Committer', $this->formatter()->format($commit));
    }

    public function testCommitterIsShownWhenItDiffersFromTheAuthor(): void
    {
        $commit = CommitFactory::create(
            authorName: 'Craig',
            authorEmail: 'c@k5n.us',
            committerName: 'Someone Else',
            committerEmail: 'other@example.com',
        );

        $description = $this->formatter()->format($commit);

        self::assertStringContainsString('Committer', $description);
        self::assertStringContainsString('Someone Else', $description);
    }

    /**
     * Either half differing is enough. A shared account committing another
     * person's patch keeps the name and changes only the email.
     */
    public function testCommitterIsShownWhenOnlyTheEmailDiffers(): void
    {
        $commit = CommitFactory::create(
            authorName: 'Craig',
            authorEmail: 'c@k5n.us',
            committerName: 'Craig',
            committerEmail: 'noreply@github.com',
        );

        $description = $this->formatter()->format($commit);

        self::assertStringContainsString('Committer', $description);
        self::assertStringContainsString('noreply@github.com', $description);
    }

    public function testCommitterIsShownWhenOnlyTheNameDiffers(): void
    {
        $commit = CommitFactory::create(
            authorName: 'Craig',
            authorEmail: 'c@k5n.us',
            committerName: 'Craig Knudsen',
            committerEmail: 'c@k5n.us',
        );

        self::assertStringContainsString('Committer', $this->formatter()->format($commit));
    }

    public function testShaIsLabelled(): void
    {
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7');

        self::assertStringContainsString(
            'Commit: afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            $this->formatter()->format($commit),
        );
    }

    public function testAuthorLineIsLabelledAndFormatted(): void
    {
        $commit = CommitFactory::create(authorName: 'Craig', authorEmail: 'c@k5n.us');

        self::assertStringContainsString('Author: Craig <c@k5n.us>', $this->formatter()->format($commit));
    }

    public function testIncludesTheCommitUrlWhenTheRemoteIsRecognised(): void
    {
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7');

        $description = $this->formatter('git@github.com:craigk5n/ilibgo.git')->format($commit);

        self::assertStringContainsString(
            'https://github.com/craigk5n/ilibgo/commit/afb87de8347df30f6ab4c6fa17cc843026e52cb7',
            $description,
        );
    }

    public function testOmitsTheUrlWhenTheRemoteIsUnrecognised(): void
    {
        $description = $this->formatter('git@internal.example.com:me/thing.git')
            ->format(CommitFactory::create());

        self::assertStringNotContainsString('http', $description);
    }

    public function testMergeCommitsAreLabelled(): void
    {
        $commit = CommitFactory::create(parents: ['aaa', 'bbb']);

        self::assertStringContainsString('Merge', $this->formatter()->format($commit));
    }

    public function testOrdinaryCommitsAreNotLabelledAsMerges(): void
    {
        self::assertStringNotContainsString(
            'Merge',
            $this->formatter()->format(CommitFactory::create(parents: ['aaa'])),
        );
    }

    public function testMessageWithNoBodyStillProducesMetadata(): void
    {
        $description = $this->formatter()->format(CommitFactory::create(rawBody: 'subject only'));

        self::assertStringContainsString('afb87de8347df30f6ab4c6fa17cc843026e52cb7', $description);
        self::assertStringStartsNotWith("\n", $description, 'no leading blank line when body is empty');
    }

    /**
     * Control characters are illegal in an ICS TEXT value, and the library's
     * writer does not strip them — SecurityValidator exists but Writer never
     * calls it, so the formatter must.
     */
    public function testControlCharactersAreNeutralised(): void
    {
        $commit = CommitFactory::create(rawBody: "subject\n\nbody with \x01 a control char");

        $description = $this->formatter()->format($commit);

        self::assertStringNotContainsString("\x01", $description);
    }

    public function testNullBytesAreStripped(): void
    {
        $commit = CommitFactory::create(rawBody: "subject\n\nbody with \x00 a null");

        self::assertStringNotContainsString("\x00", $this->formatter()->format($commit));
    }

    /**
     * Tab and newline are legal and meaningful in a commit message; only the
     * other C0 controls are escaped.
     */
    public function testTabsAndNewlinesSurvive(): void
    {
        $commit = CommitFactory::create(rawBody: "subject\n\nindented:\n\tvalue");

        $description = $this->formatter()->format($commit);

        self::assertStringContainsString("\t", $description);
        self::assertStringContainsString("\n", $description);
    }

    public function testUnicodeIsPreserved(): void
    {
        $commit = CommitFactory::create(rawBody: "subject\n\n日本語 and 🎉");

        $description = $this->formatter()->format($commit);

        self::assertStringContainsString('日本語', $description);
        self::assertStringContainsString('🎉', $description);
    }
}
