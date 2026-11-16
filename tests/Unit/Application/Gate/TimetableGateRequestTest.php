<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Gate;

use App\Application\Gate\TimetableGateRequest;
use App\Domain\Flight\Direction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimetableGateRequestTest extends TestCase
{
    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';

    public function testValidInputIsAccepted(): void
    {
        $request = self::request([]);

        self::assertSame('2026-11-11', $request->period->from->toString());
        self::assertSame('2027-01-04', $request->period->to->toString());
        self::assertSame('2026-11-12', $request->comparedOn->toString());
        self::assertSame(self::COMMIT, $request->recordCommit);
        self::assertEquals(new \DateTimeImmutable('2026-08-21T04:00:00Z'), $request->dataDateFor(Direction::Departure));
        self::assertEquals(new \DateTimeImmutable('2026-08-21T05:00:00Z'), $request->dataDateFor(Direction::Arrival));
    }

    public function testOneDayPeriodAndUtcDataDateAreAccepted(): void
    {
        $request = self::request(['from' => '2026-11-11', 'to' => '2026-11-11', 'departure' => '2026-08-21T04:00:00Z']);

        self::assertTrue($request->period->from->equals($request->period->to));
        self::assertEquals(new \DateTimeImmutable('2026-08-21T13:00:00+09:00'), $request->departureDataDate);
    }

    /**
     * @return iterable<string, array{array<string, ?string>, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'missing from' => [['from' => null], 'from'];
        yield 'missing to' => [['to' => null], 'to'];
        yield 'missing compared-on' => [['comparedOn' => null], 'compared-on'];
        yield 'missing commit' => [['commit' => null], 'commit'];
        yield 'missing departure dc:date' => [['departure' => null], 'departure-data-date'];
        yield 'missing arrival dc:date' => [['arrival' => null], 'arrival-data-date'];
        yield 'from after to' => [['from' => '2027-01-05'], 'starts after it ends'];
        yield 'from not a day' => [['from' => '2026-02-30'], 'from'];
        yield 'from with a newline' => [['from' => "2026-11-11\n"], 'from'];
        yield 'compared-on with a time' => [['comparedOn' => '2026-11-12T00:00:00'], 'compared-on'];
        yield 'uppercase commit' => [['commit' => strtoupper(self::COMMIT)], 'commit'];
        yield 'short commit' => [['commit' => substr(self::COMMIT, 0, 39)], 'commit'];
        yield 'long commit' => [['commit' => self::COMMIT.'0'], 'commit'];
        yield 'commit with a newline' => [['commit' => self::COMMIT."\n"], 'commit'];
        yield 'dc:date without a time zone' => [['departure' => '2026-08-21T13:00:00'], 'departure-data-date'];
        yield 'dc:date without seconds' => [['departure' => '2026-08-21T13:00+09:00'], 'departure-data-date'];
        yield 'dc:date with a fraction' => [['departure' => '2026-08-21T13:00:00.5+09:00'], 'departure-data-date'];
        yield 'dc:date not a day' => [['arrival' => '2026-02-30T13:00:00+09:00'], 'arrival-data-date'];
        yield 'dc:date not a time' => [['arrival' => '2026-08-21T25:00:00+09:00'], 'arrival-data-date'];
        yield 'dc:date with a newline' => [['arrival' => "2026-08-21T13:00:00+09:00\n"], 'arrival-data-date'];
    }

    /**
     * @param array<string, ?string> $override
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRefused(array $override, string $messagePart): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        self::request($override);
    }

    /**
     * @param array<string, ?string> $override
     */
    private static function request(array $override): TimetableGateRequest
    {
        $values = $override + [
            'from' => '2026-11-11',
            'to' => '2027-01-04',
            'comparedOn' => '2026-11-12',
            'commit' => self::COMMIT,
            'departure' => '2026-08-21T13:00:00+09:00',
            'arrival' => '2026-08-21T14:00:00+09:00',
        ];

        return TimetableGateRequest::fromStrings($values['from'], $values['to'], $values['comparedOn'], $values['commit'], $values['departure'], $values['arrival']);
    }
}
