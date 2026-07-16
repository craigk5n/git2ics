<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Cli;

use K5n\Git2Ics\Config\Config;

/**
 * The outcome of parsing argv: a command, plus a Config when there is work to do.
 *
 * Modelled as data rather than as control-flow exceptions, so that --help and
 * --version stay ordinary testable return values.
 */
final readonly class Invocation
{
    private function __construct(
        public Command $command,
        public ?Config $config = null,
    ) {
    }

    public static function generate(Config $config): self
    {
        return new self(Command::Generate, $config);
    }

    public static function help(): self
    {
        return new self(Command::Help);
    }

    public static function version(): self
    {
        return new self(Command::Version);
    }
}
