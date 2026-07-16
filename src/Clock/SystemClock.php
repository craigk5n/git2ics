<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real wall clock, in UTC.
 */
final class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
