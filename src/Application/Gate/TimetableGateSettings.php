<?php

declare(strict_types=1);

namespace App\Application\Gate;

use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;

/**
 * The values of one gate settings version (docs/data-source-verification.md
 * §7 step 7 「設定値と外した状態の保存・解除」): the compared period shared by
 * both directions, and per direction the dc:date of the compared fetch and
 * the hash of its compared items.
 */
final readonly class TimetableGateSettings
{
    /**
     * @param string $recordCommit merge commit of the PR that added the step 5 record
     */
    public function __construct(
        public ComparedPeriod $period,
        public ServiceDate $comparedOn,
        public string $recordCommit,
        public \DateTimeImmutable $departureDataDate,
        public \DateTimeImmutable $arrivalDataDate,
        public string $departureHash,
        public string $arrivalHash,
    ) {
    }

    public function dataDateFor(Direction $direction): \DateTimeImmutable
    {
        return match ($direction) {
            Direction::Departure => $this->departureDataDate,
            Direction::Arrival => $this->arrivalDataDate,
        };
    }

    public function hashFor(Direction $direction): string
    {
        return match ($direction) {
            Direction::Departure => $this->departureHash,
            Direction::Arrival => $this->arrivalHash,
        };
    }
}
