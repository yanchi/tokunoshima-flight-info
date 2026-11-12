<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Sync;

use App\Application\Port\TimetableSaveOutcome;
use App\Application\Sync\TimetableReplacementRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimetableReplacementRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, string, TimetableSaveOutcome}>
     */
    public static function cases(): iterable
    {
        // [this fetch, stored fetch, now, expected]
        yield 'first fetch' => ['2026-11-12T03:00:00Z', null, '2026-11-12T03:00:05Z', TimetableSaveOutcome::Saved];
        yield 'newer fetch' => ['2026-11-12T03:00:01Z', '2026-11-12T03:00:00Z', '2026-11-12T03:00:05Z', TimetableSaveOutcome::Saved];
        yield 'same second' => ['2026-11-12T03:00:00Z', '2026-11-12T03:00:00Z', '2026-11-12T03:00:05Z', TimetableSaveOutcome::SameSecondAsStored];
        yield 'older fetch' => ['2026-11-12T02:59:59Z', '2026-11-12T03:00:00Z', '2026-11-12T03:00:05Z', TimetableSaveOutcome::OlderThanStored];
        yield 'stored record in the future' => ['2026-11-12T03:00:00Z', '2026-11-12T03:00:06Z', '2026-11-12T03:00:05Z', TimetableSaveOutcome::SavedOverFutureRecord];
        yield 'stored record at now is not the future' => ['2026-11-12T03:00:00Z', '2026-11-12T03:00:05Z', '2026-11-12T03:00:05Z', TimetableSaveOutcome::OlderThanStored];
        yield 'stored record a fraction ahead of now' => ['2026-11-12T03:00:00Z', '2026-11-12T03:00:05Z', '2026-11-12T03:00:04.9Z', TimetableSaveOutcome::SavedOverFutureRecord];
        yield 'this fetch in the future' => ['2026-11-12T03:00:06Z', '2026-11-12T03:00:00Z', '2026-11-12T03:00:05Z', TimetableSaveOutcome::CurrentFetchInFuture];
        yield 'this fetch in the future, first fetch' => ['2026-11-12T03:00:06Z', null, '2026-11-12T03:00:05Z', TimetableSaveOutcome::CurrentFetchInFuture];
        yield 'this fetch in the future beats a future record' => ['2026-11-12T03:00:06Z', '2026-11-12T03:00:09Z', '2026-11-12T03:00:05Z', TimetableSaveOutcome::CurrentFetchInFuture];
        yield 'this fetch at now is not the future' => ['2026-11-12T03:00:05Z', '2026-11-12T03:00:00Z', '2026-11-12T03:00:05.5Z', TimetableSaveOutcome::Saved];
    }

    #[DataProvider('cases')]
    public function testDecide(string $current, ?string $stored, string $now, TimetableSaveOutcome $expected): void
    {
        self::assertSame($expected, TimetableReplacementRule::decide(
            new \DateTimeImmutable($current),
            null === $stored ? null : new \DateTimeImmutable($stored),
            new \DateTimeImmutable($now),
        ));
    }
}
