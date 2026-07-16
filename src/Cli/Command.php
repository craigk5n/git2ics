<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Cli;

/**
 * What the user asked the program to do.
 */
enum Command
{
    case Generate;
    case Help;
    case Version;
}
