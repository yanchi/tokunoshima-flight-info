<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Twig;

use App\Domain\Flight\FlightStatus;
use App\Presentation\Twig\FlightBoardExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlightBoardExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{FlightStatus, string}>
     */
    public static function labels(): iterable
    {
        yield 'scheduled' => [FlightStatus::Scheduled, '運航状況未確認'];
        yield 'on time' => [FlightStatus::OnTime, '定刻'];
        yield 'delayed' => [FlightStatus::Delayed, '遅延'];
        yield 'cancelled' => [FlightStatus::Cancelled, '欠航'];
        yield 'departed' => [FlightStatus::Departed, '出発済み'];
        yield 'arrived' => [FlightStatus::Arrived, '到着済み'];
        yield 'unknown' => [FlightStatus::Unknown, '状況不明'];
    }

    #[DataProvider('labels')]
    public function testLabelsStatus(FlightStatus $status, string $expected): void
    {
        self::assertSame($expected, new FlightBoardExtension()->statusLabel($status));
    }

    public function testEveryStatusHasALabel(): void
    {
        self::assertCount(\count(FlightStatus::cases()), iterator_to_array(self::labels()));
    }

    public function testStatusModifierIsTheStatusValue(): void
    {
        self::assertSame('status--scheduled', new FlightBoardExtension()->statusModifier(FlightStatus::Scheduled));
    }
}
