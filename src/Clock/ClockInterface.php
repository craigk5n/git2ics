<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Clock;

use DateTimeImmutable;

/**
 * Supplies the current time.
 *
 * Injected rather than calling time() directly so that DTSTAMP — which must be
 * generation time to work as RFC 5545's update tiebreaker — can still be frozen
 * in tests and byte-compared against golden files.
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
