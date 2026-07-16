<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Exception;

/**
 * Thrown when git cannot be run, exits non-zero, or emits output we cannot parse.
 */
final class GitException extends Git2IcsException
{
}
