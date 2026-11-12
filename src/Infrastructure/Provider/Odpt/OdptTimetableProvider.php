<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Odpt;

use App\Application\DTO\TimetableFetchResult;
use App\Application\Port\TimetableProviderInterface;
use App\Domain\Flight\Direction;
use Psr\Clock\ClockInterface;

final readonly class OdptTimetableProvider implements TimetableProviderInterface
{
    public function __construct(
        private OdptTimetableClient $client,
        private OdptTimetableMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function fetch(Direction $direction): TimetableFetchResult
    {
        // Taken before the first attempt, so retries never make the data
        // look newer than it is (§7 step 7).
        $requestStartedAt = $this->clock->now();

        return $this->mapper->map($direction, $this->client->fetch($direction), $requestStartedAt);
    }
}
