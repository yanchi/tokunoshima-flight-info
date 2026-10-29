<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * Flight number as published: 2-character airline designator, 1–4 digits,
 * optional operational suffix letter (e.g. JL3791).
 */
final readonly class FlightNumber
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^[A-Z0-9]{2}[0-9]{1,4}[A-Z]?$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid flight number: "%s".', $value));
        }

        return new self($value);
    }

    public function airlineDesignator(): string
    {
        return substr($this->value, 0, 2);
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
