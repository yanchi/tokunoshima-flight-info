<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * IATA 3-letter airport code (e.g. TKN for Tokunoshima).
 */
final readonly class AirportCode
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid IATA airport code: "%s".', $value));
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
