<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Config;

use K5n\Git2Ics\Calendar\UidGenerator;
use K5n\Git2Ics\Git\LogQuery;

/**
 * A fully resolved invocation. Immutable, and produced only by ArgumentParser.
 */
final readonly class Config
{
    /**
     * @param string|null $duration ISO 8601, already normalised. Null means a
     *                              zero-duration event, which is the default.
     */
    public function __construct(
        public string $repositoryPath = '.',
        public ?string $outputFile = null,
        public ComponentType $component = ComponentType::Vevent,
        public DateSource $dateSource = DateSource::Author,
        public TimezoneMode $timezoneMode = TimezoneMode::Utc,
        public ?string $duration = null,
        public LogQuery $query = new LogQuery(),
        public ?string $calendarName = null,
        public string $uidDomain = UidGenerator::DEFAULT_DOMAIN,
        public bool $reproducible = false,
    ) {
    }

    public function writesToStdout(): bool
    {
        return $this->outputFile === null;
    }
}
