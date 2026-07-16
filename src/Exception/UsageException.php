<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Exception;

/**
 * The command line was wrong. Reported with usage text rather than a stack trace.
 */
final class UsageException extends Git2IcsException
{
}
