<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * Code of the airline that actually operates the flight.
 *
 * Kept separate from the marketing carrier in the flight number so that
 * JAL and JAC are never conflated. Accepts IATA (2) or ICAO (3) designators;
 * which scheme the data source uses is decided in the Infrastructure mapper.
 */
final readonly class OperatorCode
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^[A-Z0-9]{2,3}$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid operator code: "%s".', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
