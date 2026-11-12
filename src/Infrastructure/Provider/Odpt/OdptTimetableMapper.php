<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Odpt;

use App\Application\DTO\TimetableEntry;
use App\Application\DTO\TimetableFetchResult;
use App\Application\Port\TimetableFetchFailed;
use App\Domain\Flight\AirportCode;
use App\Domain\Flight\Direction;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\Weekday;

/**
 * Maps a decoded odpt:FlightSchedule response to a TimetableFetchResult.
 *
 * Every value is checked; one invalid record fails the whole direction
 * (§7 step 7), so a timetable with missing flights is never saved.
 */
final class OdptTimetableMapper
{
    /** Static data responses are capped at 1000 records (§1 FAQ). */
    public const RECORD_LIMIT = 1000;

    private const AIRPORT_PREFIX = 'odpt.Airport:';
    private const CALENDAR_PREFIX = 'odpt.Calendar:';
    private const DATE_TIME_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/';
    private const TIME_PATTERN = '/\A(?:[01]\d|2[0-3]):[0-5]\d\z/';

    /**
     * @throws TimetableFetchFailed
     */
    public function map(Direction $direction, mixed $body, \DateTimeImmutable $requestStartedAt): TimetableFetchResult
    {
        if (!\is_array($body) || !array_is_list($body)) {
            throw $this->fail($direction, 'response is not a JSON array');
        }
        if ([] === $body) {
            throw $this->fail($direction, 'response has no records');
        }
        if (\count($body) >= self::RECORD_LIMIT) {
            throw $this->fail($direction, \sprintf('response has %d records and may be truncated', \count($body)));
        }

        $tkn = AirportCode::fromString('TKN');
        $latestDataDate = null;
        $seenRecords = [];
        $entries = [];

        foreach ($body as $index => $record) {
            $at = \sprintf('record %d', $index);
            if (!\is_array($record)) {
                throw $this->fail($direction, $at.' is not an object');
            }

            if ('odpt:FlightSchedule' !== ($record['@type'] ?? null)) {
                throw $this->fail($direction, $at.' has an unexpected @type');
            }
            if (OdptTimetableClient::OPERATOR !== ($record['odpt:operator'] ?? null)) {
                throw $this->fail($direction, $at.' has an unexpected odpt:operator');
            }

            $dataDate = $this->dateTime($direction, $record, 'dc:date', $at);
            $origin = $this->airport($direction, $record, 'odpt:originAirport', $at);
            $destination = $this->airport($direction, $record, 'odpt:destinationAirport', $at);
            $weekday = $this->weekday($direction, $record, $at);

            $ownEnd = Direction::Departure === $direction ? $origin : $destination;
            if (!$ownEnd->equals($tkn)) {
                throw $this->fail($direction, $at.' is not a Tokunoshima '.$direction->value);
            }

            $recordKey = $origin.'-'.$destination.'-'.$weekday->value;
            if (isset($seenRecords[$recordKey])) {
                throw $this->fail($direction, $at.' duplicates the route and calendar of another record');
            }
            $seenRecords[$recordKey] = true;

            if (null === $latestDataDate || $dataDate > $latestDataDate) {
                $latestDataDate = $dataDate;
            }

            $objects = $record['odpt:flightScheduleObject'] ?? [];
            if (!\is_array($objects) || !array_is_list($objects)) {
                throw $this->fail($direction, $at.' has an odpt:flightScheduleObject that is not an array');
            }

            foreach ($objects as $objectIndex => $object) {
                $entries[] = $this->entry($direction, $object, \sprintf('%s flight %d', $at, $objectIndex), $origin, $destination, $weekday);
            }
        }

        return new TimetableFetchResult(
            $direction,
            $requestStartedAt->setTimezone(new \DateTimeZone('UTC')),
            $latestDataDate,
            \count($body),
            $entries,
        );
    }

    private function entry(
        Direction $direction,
        mixed $object,
        string $at,
        AirportCode $origin,
        AirportCode $destination,
        Weekday $weekday,
    ): TimetableEntry {
        if (!\is_array($object)) {
            throw $this->fail($direction, $at.' is not an object');
        }

        $validFrom = $this->dateTime($direction, $object, 'odpt:isValidFrom', $at);
        $validTo = $this->dateTime($direction, $object, 'odpt:isValidTo', $at);
        if ($validFrom > $validTo) {
            throw $this->fail($direction, $at.' has odpt:isValidFrom after odpt:isValidTo');
        }

        return new TimetableEntry(
            $this->flightNumber($direction, $object, $at),
            $origin,
            $destination,
            $weekday,
            $this->time($direction, $object, 'odpt:originTime', $at),
            $this->time($direction, $object, 'odpt:destinationTime', $at),
            $this->optionalInt($direction, $object, 'odpt:originDayDifference', $at),
            $this->optionalInt($direction, $object, 'odpt:destinationDayDifference', $at),
            $validFrom,
            $validTo,
            $this->note($direction, $object, $at),
            $this->nonEmptyString($direction, $object, 'odpt:airline', $at),
        );
    }

    /**
     * Code-share flights would carry several numbers. None appear in the
     * verified data and how to show them is not decided, so they fail.
     *
     * @param array<mixed> $object
     */
    private function flightNumber(Direction $direction, array $object, string $at): FlightNumber
    {
        $numbers = $object['odpt:flightNumber'] ?? null;
        if (!\is_array($numbers) || !array_is_list($numbers) || 1 !== \count($numbers) || !\is_string($numbers[0])) {
            throw $this->fail($direction, $at.' must have exactly one odpt:flightNumber');
        }

        try {
            return FlightNumber::fromString($numbers[0]);
        } catch (\InvalidArgumentException) {
            throw $this->fail($direction, $at.' has an invalid odpt:flightNumber');
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function airport(Direction $direction, array $data, string $key, string $at): AirportCode
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || !str_starts_with($value, self::AIRPORT_PREFIX)) {
            throw $this->fail($direction, \sprintf('%s has an invalid %s', $at, $key));
        }

        try {
            return AirportCode::fromString(substr($value, \strlen(self::AIRPORT_PREFIX)));
        } catch (\InvalidArgumentException) {
            throw $this->fail($direction, \sprintf('%s has an invalid %s', $at, $key));
        }
    }

    /**
     * @param array<mixed> $record
     */
    private function weekday(Direction $direction, array $record, string $at): Weekday
    {
        $value = $record['odpt:calendar'] ?? null;
        $name = \is_string($value) && str_starts_with($value, self::CALENDAR_PREFIX)
            ? substr($value, \strlen(self::CALENDAR_PREFIX))
            : null;

        foreach (Weekday::cases() as $weekday) {
            if ($weekday->name === $name) {
                return $weekday;
            }
        }

        // Other calendars (holidays, specific days) are not in the verified
        // data; failing keeps them from being read as a weekday.
        throw $this->fail($direction, $at.' has an unsupported odpt:calendar');
    }

    /**
     * Requires an explicit offset: a value without one would be read in
     * PHP's default time zone.
     *
     * @param array<mixed> $data
     */
    private function dateTime(Direction $direction, array $data, string $key, string $at): \DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || 1 !== preg_match(self::DATE_TIME_PATTERN, $value)) {
            throw $this->fail($direction, \sprintf('%s has an invalid %s', $at, $key));
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        // getLastErrors() also reports overflowing dates such as 02-30.
        if (false === $parsed || false !== \DateTimeImmutable::getLastErrors()) {
            throw $this->fail($direction, \sprintf('%s has an invalid %s', $at, $key));
        }

        return $parsed;
    }

    /**
     * @param array<mixed> $object
     */
    private function time(Direction $direction, array $object, string $key, string $at): string
    {
        $value = $object[$key] ?? null;
        if (!\is_string($value) || 1 !== preg_match(self::TIME_PATTERN, $value)) {
            throw $this->fail($direction, \sprintf('%s has an invalid %s', $at, $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $object
     */
    private function optionalInt(Direction $direction, array $object, string $key, string $at): ?int
    {
        if (!\array_key_exists($key, $object)) {
            return null;
        }
        if (!\is_int($object[$key])) {
            throw $this->fail($direction, \sprintf('%s has an invalid %s', $at, $key));
        }

        return $object[$key];
    }

    /**
     * @param array<mixed> $object
     */
    private function note(Direction $direction, array $object, string $at): ?string
    {
        if (!\array_key_exists('odpt:note', $object)) {
            return null;
        }

        $note = $object['odpt:note'];
        if (!\is_array($note) || !isset($note['ja']) || !\is_string($note['ja']) || '' === trim($note['ja'])) {
            throw $this->fail($direction, $at.' has an odpt:note without Japanese text');
        }

        return $note['ja'];
    }

    /**
     * @param array<mixed> $object
     */
    private function nonEmptyString(Direction $direction, array $object, string $key, string $at): string
    {
        $value = $object[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw $this->fail($direction, \sprintf('%s has an invalid %s', $at, $key));
        }

        return $value;
    }

    private function fail(Direction $direction, string $reason): TimetableFetchFailed
    {
        return new TimetableFetchFailed(\sprintf('ODPT timetable (%s) mapping failed: %s.', $direction->value, $reason));
    }
}
