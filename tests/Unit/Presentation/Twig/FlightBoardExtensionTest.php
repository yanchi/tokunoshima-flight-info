<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Twig;

use App\Application\DTO\DailyFlightBoard;
use App\Application\DTO\DataAvailability;
use App\Application\DTO\FlightRow;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightStatus;
use App\Domain\Flight\ServiceDate;
use App\Presentation\Twig\FlightBoardExtension;
use App\Tests\Support\FictionalFlights;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlightBoardExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{FlightStatus, string}>
     */
    public static function freshLabels(): iterable
    {
        yield 'scheduled' => [FlightStatus::Scheduled, '運航状況未確認'];
        yield 'on time' => [FlightStatus::OnTime, '定刻'];
        yield 'delayed' => [FlightStatus::Delayed, '遅延'];
        yield 'cancelled' => [FlightStatus::Cancelled, '欠航'];
        yield 'departed' => [FlightStatus::Departed, '出発済み'];
        yield 'arrived' => [FlightStatus::Arrived, '到着済み'];
        yield 'unknown' => [FlightStatus::Unknown, '状況不明'];
    }

    #[DataProvider('freshLabels')]
    public function testLabelsFreshStatus(FlightStatus $status, string $expected): void
    {
        $flight = FictionalFlights::departure($status, '2026-10-29T10:00:00+09:00');

        self::assertSame($expected, (new FlightBoardExtension())->statusLabel($this->board(DataAvailability::Fresh, $flight), $flight));
    }

    public function testEveryStatusHasALabel(): void
    {
        self::assertCount(\count(FlightStatus::cases()), iterator_to_array(self::freshLabels()));
    }

    /**
     * @return iterable<string, array{FlightStatus, string}>
     */
    public static function staleLabels(): iterable
    {
        // Statuses that never came from real-time data keep their label.
        yield 'scheduled' => [FlightStatus::Scheduled, '運航状況未確認'];
        yield 'unknown' => [FlightStatus::Unknown, '状況不明'];
        yield 'on time' => [FlightStatus::OnTime, FlightBoardExtension::STALE_STATUS_LABEL];
        yield 'delayed' => [FlightStatus::Delayed, FlightBoardExtension::STALE_STATUS_LABEL];
        yield 'cancelled' => [FlightStatus::Cancelled, FlightBoardExtension::STALE_STATUS_LABEL];
        yield 'departed' => [FlightStatus::Departed, FlightBoardExtension::STALE_STATUS_LABEL];
        yield 'arrived' => [FlightStatus::Arrived, FlightBoardExtension::STALE_STATUS_LABEL];
    }

    #[DataProvider('staleLabels')]
    public function testWithholdsObservedStatusWhenStale(FlightStatus $status, string $expected): void
    {
        $flight = FictionalFlights::departure($status, '2026-10-29T10:00:00+09:00');

        self::assertSame($expected, (new FlightBoardExtension())->statusLabel($this->board(DataAvailability::Stale, $flight), $flight));
    }

    public function testWithholdsEstimatedTimeWhenStale(): void
    {
        $flight = FictionalFlights::departure(FlightStatus::Delayed, '2026-10-29T10:00:00+09:00', '2026-10-29T10:30:00+09:00');
        $extension = new FlightBoardExtension();

        self::assertEquals($flight->estimatedAt, $extension->estimatedAt($this->board(DataAvailability::Fresh, $flight), $flight));
        self::assertNull($extension->estimatedAt($this->board(DataAvailability::Stale, $flight), $flight));
    }

    public function testStatusModifierFollowsWithholding(): void
    {
        $delayed = FictionalFlights::departure(FlightStatus::Delayed, '2026-10-29T10:00:00+09:00');
        $scheduled = FictionalFlights::departure(FlightStatus::Scheduled, '2026-10-29T10:00:00+09:00');
        $extension = new FlightBoardExtension();

        self::assertSame('status--delayed', $extension->statusModifier($this->board(DataAvailability::Fresh, $delayed), $delayed));
        self::assertNull($extension->statusModifier($this->board(DataAvailability::Stale, $delayed), $delayed));
        self::assertSame('status--scheduled', $extension->statusModifier($this->board(DataAvailability::Stale, $scheduled), $scheduled));
    }

    private function board(DataAvailability $availability, FlightRow $flight): DailyFlightBoard
    {
        return new DailyFlightBoard(
            ServiceDate::fromString('2026-10-29'),
            Direction::Departure,
            $availability,
            [$flight],
            new \DateTimeImmutable('2026-10-29T00:00:00+00:00'),
            'test source',
        );
    }
}
