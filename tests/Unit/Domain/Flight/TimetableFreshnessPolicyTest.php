<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Flight;

use App\Domain\Flight\TimetableFreshness;
use App\Domain\Flight\TimetableFreshnessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimetableFreshnessPolicyTest extends TestCase
{
    private const NOW = '2026-11-12T03:00:00+00:00';

    /**
     * @return iterable<string, array{string, TimetableFreshness}>
     */
    public static function fetchTimes(): iterable
    {
        // Thresholds: warn after 100 seconds, suspend after 200.
        yield 'just fetched' => ['2026-11-12T03:00:00+00:00', TimetableFreshness::Fresh];
        yield 'exactly at the warning threshold' => ['2026-11-12T02:58:20+00:00', TimetableFreshness::Fresh];
        yield 'half a second past the warning threshold' => ['2026-11-12T02:58:19.5+00:00', TimetableFreshness::Warning];
        yield 'one second past the warning threshold' => ['2026-11-12T02:58:19+00:00', TimetableFreshness::Warning];
        yield 'exactly at the stop threshold' => ['2026-11-12T02:56:40+00:00', TimetableFreshness::Warning];
        yield 'half a second past the stop threshold' => ['2026-11-12T02:56:39.5+00:00', TimetableFreshness::Suspended];
        yield 'one second past the stop threshold' => ['2026-11-12T02:56:39+00:00', TimetableFreshness::Suspended];
        yield 'one second in the future' => ['2026-11-12T03:00:01+00:00', TimetableFreshness::FutureDated];
        yield 'a fraction of a second in the future' => ['2026-11-12T03:00:00.5+00:00', TimetableFreshness::FutureDated];
        yield 'half a second under the warning threshold, now with a fraction' => ['2026-11-12T02:58:20.5+00:00', TimetableFreshness::Fresh];
        yield 'same instant in JST' => ['2026-11-12T12:00:00+09:00', TimetableFreshness::Fresh];
    }

    #[DataProvider('fetchTimes')]
    public function testJudge(string $fetchedAt, TimetableFreshness $expected): void
    {
        $policy = new TimetableFreshnessPolicy('100', '200');

        self::assertTrue($policy->isValid());
        self::assertSame($expected, $policy->judge(new \DateTimeImmutable($fetchedAt), new \DateTimeImmutable(self::NOW)));
    }

    public function testDefaultsAreValid(): void
    {
        self::assertTrue(new TimetableFreshnessPolicy('259200', '604800')->isValid());
    }

    public function testStopThresholdMayBeExactlySevenDays(): void
    {
        self::assertTrue(new TimetableFreshnessPolicy('1', '604800')->isValid());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidThresholds(): iterable
    {
        yield 'both unset' => ['', ''];
        yield 'warning unset' => ['', '604800'];
        yield 'stop unset' => ['259200', ''];
        yield 'zero' => ['0', '604800'];
        yield 'negative' => ['-1', '604800'];
        yield 'leading space' => [' 259200', '604800'];
        yield 'trailing newline' => ["259200\n", '604800'];
        yield 'exponent' => ['1e5', '604800'];
        yield 'decimal' => ['259200.0', '604800'];
        yield 'plus sign' => ['+259200', '604800'];
        yield 'leading zero' => ['0259200', '604800'];
        yield 'not a number' => ['three days', '604800'];
        yield 'warning equals stop' => ['604800', '604800'];
        yield 'warning above stop' => ['604800', '259200'];
        yield 'stop above seven days' => ['259200', '604801'];
        yield 'huge' => ['259200', '99999999999999999999'];
    }

    #[DataProvider('invalidThresholds')]
    public function testInvalidThresholdsPublishNothingWithoutThrowing(string $warn, string $suspend): void
    {
        $policy = new TimetableFreshnessPolicy($warn, $suspend);

        self::assertFalse($policy->isValid());
        self::assertSame(
            TimetableFreshness::InvalidThresholds,
            $policy->judge(new \DateTimeImmutable(self::NOW), new \DateTimeImmutable(self::NOW)),
        );
        self::assertFalse(TimetableFreshness::InvalidThresholds->isPublishable());
    }

    public function testOnlyFreshAndWarningArePublishable(): void
    {
        self::assertSame(
            [TimetableFreshness::Fresh, TimetableFreshness::Warning],
            array_values(array_filter(TimetableFreshness::cases(), static fn (TimetableFreshness $f): bool => $f->isPublishable())),
        );
    }
}
