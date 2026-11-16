<?php

declare(strict_types=1);

namespace App\Presentation\Twig;

use App\Domain\Flight\FlightStatus;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Labels of the board's status column.
 */
final class FlightBoardExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('flight_status_label', $this->statusLabel(...)),
            new TwigFunction('flight_status_modifier', $this->statusModifier(...)),
        ];
    }

    public function statusLabel(FlightStatus $status): string
    {
        return match ($status) {
            FlightStatus::Scheduled => '運航状況未確認',
            FlightStatus::OnTime => '定刻',
            FlightStatus::Delayed => '遅延',
            FlightStatus::Cancelled => '欠航',
            FlightStatus::Departed => '出発済み',
            FlightStatus::Arrived => '到着済み',
            FlightStatus::Unknown => '状況不明',
        };
    }

    /**
     * CSS modifier for the status badge.
     */
    public function statusModifier(FlightStatus $status): string
    {
        return 'status--'.$status->value;
    }
}
