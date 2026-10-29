<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * What the data store holds for one day and direction, and when it was
 * fetched from which source.
 */
final readonly class FlightBoardSnapshot
{
    /**
     * @param list<FlightRow> $flights
     */
    public function __construct(
        public array $flights,
        public ?\DateTimeImmutable $fetchedAt,
        public ?string $sourceName,
    ) {
        if ((null === $fetchedAt) !== (null === $sourceName)) {
            throw new \InvalidArgumentException('Fetch time and source name must be given together.');
        }

        if (null === $fetchedAt && [] !== $flights) {
            throw new \InvalidArgumentException('Flights require a fetch time and a source name.');
        }
    }

    /**
     * Nothing has ever been fetched.
     */
    public static function unavailable(): self
    {
        return new self([], null, null);
    }
}
