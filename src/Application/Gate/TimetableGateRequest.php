<?php

declare(strict_types=1);

namespace App\Application\Gate;

use App\Domain\Flight\Direction;
use App\Domain\Flight\ServiceDate;

/**
 * The operator's input to set a gate settings version, checked for format
 * only (docs/data-source-verification.md §7 step 7 「コマンドの必須の入力と形式」).
 * Whether the commit exists and holds the step 5 record is the operator's
 * check, not this class's.
 */
final readonly class TimetableGateRequest
{
    private const COMMIT_PATTERN = '/\A[0-9a-f]{40}\z/';
    // The form the mapper accepts from ODPT (seconds, +09:00 only), so the
    // value can be checked against the step 5 record by eye (2026-11-16
    // operator decision, PR #26).
    private const DATA_DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00\z/';

    private function __construct(
        public ComparedPeriod $period,
        public ServiceDate $comparedOn,
        public string $recordCommit,
        public \DateTimeImmutable $departureDataDate,
        public \DateTimeImmutable $arrivalDataDate,
    ) {
    }

    /**
     * @throws TimetableGateInputInvalid naming the first input that is missing or malformed
     */
    public static function fromStrings(
        ?string $from,
        ?string $to,
        ?string $comparedOn,
        ?string $recordCommit,
        ?string $departureDataDate,
        ?string $arrivalDataDate,
    ): self {
        $fromDate = self::date('from', $from);
        $toDate = self::date('to', $to);
        if ($fromDate->compareTo($toDate) > 0) {
            throw new TimetableGateInputInvalid('The compared period starts after it ends.');
        }

        if (null === $recordCommit || 1 !== preg_match(self::COMMIT_PATTERN, $recordCommit)) {
            throw new TimetableGateInputInvalid('The record commit must be 40 lowercase hexadecimal digits.');
        }

        return new self(
            new ComparedPeriod($fromDate, $toDate),
            self::date('compared-on', $comparedOn),
            $recordCommit,
            self::dataDate('departure-data-date', $departureDataDate),
            self::dataDate('arrival-data-date', $arrivalDataDate),
        );
    }

    public function dataDateFor(Direction $direction): \DateTimeImmutable
    {
        return match ($direction) {
            Direction::Departure => $this->departureDataDate,
            Direction::Arrival => $this->arrivalDataDate,
        };
    }

    private static function date(string $name, ?string $value): ServiceDate
    {
        if (null === $value) {
            throw new TimetableGateInputInvalid(\sprintf('The %s date is required (Y-m-d).', $name));
        }

        try {
            return ServiceDate::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new TimetableGateInputInvalid(\sprintf('The %s date must be a JST calendar day in Y-m-d format.', $name));
        }
    }

    private static function dataDate(string $name, ?string $value): \DateTimeImmutable
    {
        $message = \sprintf('The %s must be a date and time with seconds and the +09:00 offset, e.g. 2026-08-21T13:00:00+09:00.', $name);
        if (null === $value || 1 !== preg_match(self::DATA_DATE_PATTERN, $value)) {
            throw new TimetableGateInputInvalid($message);
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        // The round trip rejects overflowing values such as 02-30 or 25:00.
        if (false === $parsed || $parsed->format('Y-m-d\TH:i:sP') !== $value) {
            throw new TimetableGateInputInvalid($message);
        }

        return $parsed;
    }
}
