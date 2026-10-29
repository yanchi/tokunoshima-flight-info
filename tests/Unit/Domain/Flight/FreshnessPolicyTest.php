<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Flight;

use App\Domain\Flight\FreshnessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FreshnessPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function fetchTimes(): iterable
    {
        // now is fixed at 2026-10-27T03:00:00Z, threshold 600 seconds
        yield 'just fetched' => ['2026-10-27T03:00:00+00:00', true];
        yield 'exactly at threshold' => ['2026-10-27T02:50:00+00:00', true];
        yield 'one second over threshold' => ['2026-10-27T02:49:59+00:00', false];
        yield 'fetched in the future' => ['2026-10-27T03:00:01+00:00', false];
        yield 'different time zone, same instant' => ['2026-10-27T12:00:00+09:00', true];
    }

    #[DataProvider('fetchTimes')]
    public function testIsFresh(string $fetchedAt, bool $expected): void
    {
        $policy = new FreshnessPolicy(600);

        self::assertSame(
            $expected,
            $policy->isFresh(new \DateTimeImmutable($fetchedAt), new \DateTimeImmutable('2026-10-27T03:00:00+00:00')),
        );
    }

    public function testRejectsNonPositiveThreshold(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FreshnessPolicy(0);
    }
}
