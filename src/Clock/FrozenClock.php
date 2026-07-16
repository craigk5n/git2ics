<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Clock;

use DateTimeImmutable;

/**
 * A clock stopped at a fixed instant, making DTSTAMP deterministic in tests.
 *
 * Lives in src/ rather than tests/ so that `--reproducible` can reuse it.
 */
final readonly class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public static function at(string $iso8601): self
    {
        return new self(new DateTimeImmutable($iso8601));
    }

    #[\Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
