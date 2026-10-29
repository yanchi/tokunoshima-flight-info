<?php

declare(strict_types=1);

namespace App\Domain\Flight;

/**
 * Decides whether fetched operation data may still be presented as current.
 *
 * The threshold is configuration, not a domain constant: it depends on the
 * data provider's update frequency, which is not yet verified.
 */
final readonly class FreshnessPolicy
{
    public function __construct(private int $maxAgeSeconds)
    {
        if ($maxAgeSeconds <= 0) {
            throw new \InvalidArgumentException('Max age must be a positive number of seconds.');
        }
    }

    /**
     * A fetch time later than now (clock skew or bad data) is treated as
     * stale, because we cannot trust it to be current.
     */
    public function isFresh(\DateTimeInterface $fetchedAt, \DateTimeInterface $now): bool
    {
        $age = $now->getTimestamp() - $fetchedAt->getTimestamp();

        return $age >= 0 && $age <= $this->maxAgeSeconds;
    }
}
