<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Provider\Odpt;

use App\Infrastructure\Provider\Odpt\OdptRetryStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OdptRetryStrategyTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function retryAfters(): iterable
    {
        yield 'no header' => [null, true];
        yield 'unreadable' => ['abc', true];
        yield 'one second' => ['1', true];
        yield 'fraction of a second' => ['0.5', true];
        yield 'at the cap' => ['20', true];
        yield 'just over the cap' => ['20.001', false];
        yield 'zero' => ['0', false];
        yield 'negative' => ['-5', false];
        yield 'one hour' => ['3600', false];
        yield 'HTTP date at the cap' => ['Thu, 12 Nov 2026 00:00:20 GMT', true];
        yield 'HTTP date over the cap' => ['Thu, 12 Nov 2026 00:00:21 GMT', false];
        yield 'HTTP date now' => ['Thu, 12 Nov 2026 00:00:00 GMT', false];
        yield 'HTTP date in the past' => ['Wed, 11 Nov 2026 23:59:00 GMT', false];
    }

    #[DataProvider('retryAfters')]
    public function testAcceptsRetryAfterOnlyWithinTheCap(?string $value, bool $expected): void
    {
        $strategy = new OdptRetryStrategy(new MockClock('2026-11-12T00:00:00+00:00'), 0);

        self::assertSame($expected, $strategy->acceptsRetryAfter($value));
    }
}
