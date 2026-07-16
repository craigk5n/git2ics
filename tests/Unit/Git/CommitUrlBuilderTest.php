<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Tests\Unit\Git;

use K5n\Git2Ics\Git\CommitUrlBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure: turns a git remote URL into a browsable commit URL. The remote itself is
 * read by GitCliCommitReader; nothing here touches the network or the disk.
 */
#[CoversClass(CommitUrlBuilder::class)]
final class CommitUrlBuilderTest extends TestCase
{
    private const SHA = 'afb87de8347df30f6ab4c6fa17cc843026e52cb7';

    #[DataProvider('remoteProvider')]
    public function testBuildsCommitUrl(string $remote, ?string $expected): void
    {
        $builder = new CommitUrlBuilder($remote);

        self::assertSame($expected, $builder->forSha(self::SHA));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function remoteProvider(): iterable
    {
        $sha = self::SHA;

        yield 'github scp-like ssh' => [
            'git@github.com:craigk5n/ilibgo.git',
            "https://github.com/craigk5n/ilibgo/commit/{$sha}",
        ];
        yield 'github https with .git' => [
            'https://github.com/craigk5n/ilibgo.git',
            "https://github.com/craigk5n/ilibgo/commit/{$sha}",
        ];
        yield 'github https without .git' => [
            'https://github.com/craigk5n/ilibgo',
            "https://github.com/craigk5n/ilibgo/commit/{$sha}",
        ];
        yield 'github ssh protocol' => [
            'ssh://git@github.com/craigk5n/ilibgo.git',
            "https://github.com/craigk5n/ilibgo/commit/{$sha}",
        ];
        yield 'github with trailing slash' => [
            'https://github.com/craigk5n/ilibgo/',
            "https://github.com/craigk5n/ilibgo/commit/{$sha}",
        ];
        yield 'gitlab uses a different path' => [
            'git@gitlab.com:group/project.git',
            "https://gitlab.com/group/project/-/commit/{$sha}",
        ];
        yield 'gitlab https' => [
            'https://gitlab.com/group/project.git',
            "https://gitlab.com/group/project/-/commit/{$sha}",
        ];
        yield 'gitlab subgroup' => [
            'git@gitlab.com:group/subgroup/project.git',
            "https://gitlab.com/group/subgroup/project/-/commit/{$sha}",
        ];
        yield 'unknown host yields null rather than a guess' => [
            'git@git.example.com:me/thing.git',
            null,
        ];
        yield 'local path has no web view' => ['/home/cknudsen/programs/ilibgo', null];
        yield 'empty remote' => ['', null];

        // A bare host cannot name a repository. Without an explicit owner/repo
        // check these would emit https://github.com//commit/<sha>.
        yield 'bare host' => ['https://github.com', null];
        yield 'bare host with trailing slash' => ['https://github.com/', null];
        yield 'owner but no repo' => ['https://github.com/craigk5n', null];
        yield 'scp-like with owner but no repo' => ['git@github.com:craigk5n', null];
    }

    public function testNoRemoteYieldsNull(): void
    {
        self::assertNull((new CommitUrlBuilder(null))->forSha(self::SHA));
    }

    public function testUrlIncludesTheFullSha(): void
    {
        $url = (new CommitUrlBuilder('git@github.com:craigk5n/ilibgo.git'))->forSha(self::SHA);

        self::assertNotNull($url);
        self::assertStringEndsWith(self::SHA, $url);
    }
}
