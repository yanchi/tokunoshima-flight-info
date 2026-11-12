<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\DTO\TimetableFetchResult;
use App\Application\Port\TimetableFetchFailed;
use App\Application\Port\TimetableProviderInterface;
use App\Domain\Flight\Direction;

/**
 * TEST ONLY. Returns a prepared result or failure per direction.
 */
final class StubTimetableProvider implements TimetableProviderInterface
{
    /** @var array<string, TimetableFetchResult|TimetableFetchFailed> */
    private array $responses = [];

    /** @var list<Direction> */
    public array $fetched = [];

    public function respond(Direction $direction, TimetableFetchResult|TimetableFetchFailed $response): void
    {
        $this->responses[$direction->value] = $response;
    }

    public function fetch(Direction $direction): TimetableFetchResult
    {
        $this->fetched[] = $direction;
        $response = $this->responses[$direction->value] ?? new TimetableFetchFailed('No response prepared.');
        if ($response instanceof TimetableFetchFailed) {
            throw $response;
        }

        return $response;
    }
}
