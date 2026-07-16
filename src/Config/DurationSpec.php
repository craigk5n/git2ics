<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Config;

use K5n\Git2Ics\Exception\UsageException;

/**
 * Normalises a --duration argument to an ISO 8601 duration.
 *
 * Accepts the shorthand people actually type (`15m`, `1h30m`) as well as a
 * literal ISO 8601 duration (`PT15M`) for anyone who already thinks in RFC.
 */
final class DurationSpec
{
    public static function toIso8601(string $spec): string
    {
        $trimmed = trim($spec);

        if ($trimmed === '') {
            throw new UsageException('--duration cannot be empty');
        }

        // Already ISO 8601: hand it straight to DateInterval.
        if (str_starts_with(strtoupper($trimmed), 'P')) {
            return strtoupper($trimmed);
        }

        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i', $trimmed, $matches) !== 1) {
            throw new UsageException(sprintf(
                'invalid --duration "%s": expected something like 15m, 1h, 1h30m, or an ISO 8601 duration like PT15M',
                $spec,
            ));
        }

        $hours = (int) ($matches[1] ?? 0);
        $minutes = (int) ($matches[2] ?? 0);
        $seconds = (int) ($matches[3] ?? 0);

        // The regex makes every part optional, so "" matches it happily.
        if ($hours === 0 && $minutes === 0 && $seconds === 0) {
            throw new UsageException(sprintf('invalid --duration "%s": duration is zero', $spec));
        }

        return 'PT'
            . ($hours > 0 ? $hours . 'H' : '')
            . ($minutes > 0 ? $minutes . 'M' : '')
            . ($seconds > 0 ? $seconds . 'S' : '');
    }
}
