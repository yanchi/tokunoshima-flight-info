<?php

declare(strict_types=1);

namespace App\Application\Gate;

use App\Application\DTO\TimetableEntry;
use App\Domain\Flight\Weekday;

/**
 * Hash of the compared items of one direction's timetable inside the
 * compared period (docs/data-source-verification.md §7 step 7). The sync
 * and the gate command must both use this class, so the value set by the
 * operator and the value checked on every sync are made the same way.
 *
 * The method is fixed by ComparedItemsHasherTest's golden values and must
 * not change: a different method makes comparison (a) fail for both
 * directions right after a deploy, and nothing is published until the
 * operator compares again. In particular, keep:
 *
 * - entries not overlapping the period are left out; the others have their
 *   valid days cut to the period first;
 * - each entry becomes the JSON list [flight number, origin, destination,
 *   ISO weekday number, origin time, destination time, origin day
 *   difference, destination day difference, valid from, valid to, note,
 *   airline], dates as Y-m-d and missing values as null (never 0 or "");
 * - the weekday is the ISO number (Monday = 1 … Sunday = 7), mapped here
 *   rather than taken from the enum's backing value, so changing the enum
 *   cannot change the hash;
 * - the encoded entries are sorted by byte order (strcmp), so the order of
 *   records and entries in the response does not matter, and duplicates
 *   are kept;
 * - the hashed text is the JSON object {"format", "period", "entries"},
 *   where "entries" is the list of the encoded entries as JSON strings
 *   (encoded twice), with json_encode's default escaping (no flags other
 *   than JSON_THROW_ON_ERROR);
 * - the algorithm is SHA-256, written as 64 lowercase hex digits.
 */
final class ComparedItemsHasher
{
    private const FORMAT = 'tkn-timetable-compared-items/1';

    /**
     * @param list<TimetableEntry> $entries all stored entries of one direction
     *
     * @return string 64 lowercase hex digits
     *
     * @throws \JsonException when a text value is not valid UTF-8
     */
    public function hash(ComparedPeriod $period, array $entries): string
    {
        $encoded = [];
        foreach ($entries as $entry) {
            if (!$period->overlaps($entry->validFrom, $entry->validTo)) {
                continue;
            }
            [$validFrom, $validTo] = $period->clamp($entry->validFrom, $entry->validTo);
            $encoded[] = $this->encode([
                $entry->flightNumber->value,
                $entry->originAirport->value,
                $entry->destinationAirport->value,
                self::isoWeekday($entry->weekday),
                $entry->originTime,
                $entry->destinationTime,
                $entry->originDayDifference,
                $entry->destinationDayDifference,
                $validFrom->toString(),
                $validTo->toString(),
                $entry->note,
                $entry->airline,
            ]);
        }
        usort($encoded, strcmp(...));

        // The entries are embedded as already encoded strings, so their
        // sorted form is exactly what is hashed.
        $text = $this->encode([
            'format' => self::FORMAT,
            'period' => [$period->from->toString(), $period->to->toString()],
            'entries' => $encoded,
        ]);

        return hash('sha256', $text);
    }

    private static function isoWeekday(Weekday $weekday): int
    {
        return match ($weekday) {
            Weekday::Monday => 1,
            Weekday::Tuesday => 2,
            Weekday::Wednesday => 3,
            Weekday::Thursday => 4,
            Weekday::Friday => 5,
            Weekday::Saturday => 6,
            Weekday::Sunday => 7,
        };
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }
}
