<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Calendar;

use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Tests\CommitFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UidGenerator::class)]
final class UidGeneratorTest extends TestCase
{
    public function testUidIsTheShaAtTheDomain(): void
    {
        $generator = new UidGenerator('git2ics.k5n.us');
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7');

        self::assertSame(
            'afb87de8347df30f6ab4c6fa17cc843026e52cb7@git2ics.k5n.us',
            $generator->forCommit($commit),
        );
    }

    public function testDomainIsConfigurable(): void
    {
        $generator = new UidGenerator('example.org');
        $commit = CommitFactory::create(sha: 'deadbeef');

        self::assertSame('deadbeef@example.org', $generator->forCommit($commit));
    }

    /**
     * The whole point: regenerating must produce the same UID so a re-import
     * updates the existing entry instead of duplicating it.
     */
    public function testUidIsStableAcrossInvocations(): void
    {
        $commit = CommitFactory::create(sha: 'afb87de8347df30f6ab4c6fa17cc843026e52cb7');

        self::assertSame(
            (new UidGenerator('git2ics.k5n.us'))->forCommit($commit),
            (new UidGenerator('git2ics.k5n.us'))->forCommit($commit),
        );
    }

    public function testDifferentCommitsGetDifferentUids(): void
    {
        $generator = new UidGenerator('git2ics.k5n.us');

        self::assertNotSame(
            $generator->forCommit(CommitFactory::create(sha: 'aaa')),
            $generator->forCommit(CommitFactory::create(sha: 'bbb')),
        );
    }

    /**
     * The full 40-char SHA, not the short one: an abbreviated SHA collides.
     */
    public function testUidUsesTheFullSha(): void
    {
        $sha = 'afb87de8347df30f6ab4c6fa17cc843026e52cb7';
        $uid = (new UidGenerator('git2ics.k5n.us'))->forCommit(CommitFactory::create(sha: $sha));

        self::assertStringStartsWith($sha, $uid);
    }
}
