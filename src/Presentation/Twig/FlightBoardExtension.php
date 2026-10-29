<?php

declare(strict_types=1);

namespace App\Presentation\Twig;

use App\Application\DTO\DailyFlightBoard;
use App\Application\DTO\FlightRow;
use App\Domain\Flight\FlightStatus;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Display rules for board rows. Once the data is stale, observed statuses
 * and estimated times are withheld so that they are not read as current.
 */
final class FlightBoardExtension extends AbstractExtension
{
    public const STALE_STATUS_LABEL = '更新停止／要公式確認';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('flight_status_label', $this->statusLabel(...)),
            new TwigFunction('flight_estimated_at', $this->estimatedAt(...)),
            new TwigFunction('flight_status_modifier', $this->statusModifier(...)),
        ];
    }

    public function statusLabel(DailyFlightBoard $board, FlightRow $flight): string
    {
        if ($this->isWithheld($board, $flight)) {
            return self::STALE_STATUS_LABEL;
        }

        return match ($flight->status) {
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
     * CSS modifier for the status badge. Withheld statuses get none, so that
     * a stale "delayed" or "cancelled" is not styled as current.
     */
    public function statusModifier(DailyFlightBoard $board, FlightRow $flight): ?string
    {
        return $this->isWithheld($board, $flight) ? null : 'status--'.$flight->status->value;
    }

    public function estimatedAt(DailyFlightBoard $board, FlightRow $flight): ?\DateTimeImmutable
    {
        return $board->isStale() ? null : $flight->estimatedAt;
    }

    private function isWithheld(DailyFlightBoard $board, FlightRow $flight): bool
    {
        return $board->isStale() && $flight->status->isObserved();
    }
}
