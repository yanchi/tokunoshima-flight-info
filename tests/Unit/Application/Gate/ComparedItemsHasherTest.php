<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Gate;

use App\Application\DTO\TimetableEntry;
use App\Application\Gate\ComparedItemsHasher;
use App\Application\Gate\ComparedPeriod;
use App\Domain\Flight\AirportCode;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\ServiceDate;
use App\Domain\Flight\Weekday;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test-only values: every flight number, airport, airline and note in this
 * file (ZZ…, ZZZ, ZZY, odpt.Operator:ZZ / ZY) is fictional and must never
 * reach production data.
 *
 * The golden values fix the hash method (docs/data-source-verification.md
 * §7 step 7). If a golden test fails, fix the code, not the expected value:
 * changing the method blocks publishing until the operator compares again.
 */
final class ComparedItemsHasherTest extends TestCase
{
    public function testGoldenHashOfTheFixedInput(): void
    {
        $hash = (new ComparedItemsHasher())->hash(self::period(), self::goldenEntries());

        // The exact text that is hashed, written out in full so the method
        // can be read here without the code. It and the expected value were
        // made outside PHP (Python's json module, with "/" escaped as PHP
        // does, then sha256).
        $text = '{"format":"tkn-timetable-compared-items\\/1","period":["2026-11-01","2026-11-30"],"entries":['
            .'"[\\"ZZ1\\",\\"TKN\\",\\"ZZZ\\",1,\\"10:00\\",\\"10:50\\",null,null,\\"2026-11-01\\",\\"2026-11-30\\",null,\\"odpt.Operator:ZZ\\"]",'
            .'"[\\"ZZ2\\",\\"TKN\\",\\"ZZZ\\",1,\\"15:00\\",\\"15:50\\",0,0,\\"2026-11-01\\",\\"2026-11-15\\",\\"11\\\\\\/3\\\\u306f\\\\u904b\\\\u4f11\\",\\"odpt.Operator:ZZ\\"]",'
            .'"[\\"ZZ2\\",\\"TKN\\",\\"ZZZ\\",1,\\"15:10\\",\\"16:00\\",null,null,\\"2026-11-16\\",\\"2026-11-30\\",null,\\"odpt.Operator:ZZ\\"]",'
            .'"[\\"ZZ4\\",\\"TKN\\",\\"ZZZ\\",2,\\"12:00\\",\\"12:50\\",null,null,\\"2026-11-01\\",\\"2026-11-30\\",\\"say \\\\\\"x\\\\\\" \\\\\\\\ end\\",\\"odpt.Operator:ZZ\\"]",'
            .'"[\\"ZZ4\\",\\"TKN\\",\\"ZZZ\\",3,\\"12:00\\",\\"12:50\\",null,null,\\"2026-11-01\\",\\"2026-11-30\\",\\"<a&b>\'\\",\\"odpt.Operator:ZZ\\"]",'
            .'"[\\"ZZ4\\",\\"TKN\\",\\"ZZZ\\",4,\\"12:00\\",\\"12:50\\",null,null,\\"2026-11-01\\",\\"2026-11-30\\",\\"1\\\\u884c\\\\u76ee\\\\n2\\\\u884c\\\\u76ee\\",\\"odpt.Operator:ZZ\\"]",'
            .'"[\\"ZZ4\\",\\"TKN\\",\\"ZZZ\\",5,\\"12:00\\",\\"12:50\\",null,null,\\"2026-11-01\\",\\"2026-11-30\\",\\"\\\\ud842\\\\udfb7\\\\t\\",\\"odpt.Operator:ZZ\\"]",'
            .'"[\\"ZZ4\\",\\"TKN\\",\\"ZZZ\\",6,\\"12:00\\",\\"12:50\\",null,null,\\"2026-11-01\\",\\"2026-11-30\\",\\"123\\",\\"odpt.Operator:ZZ\\"]",'
            .'"[\\"ZZ4\\",\\"TKN\\",\\"ZZZ\\",7,\\"12:00\\",\\"12:50\\",null,null,\\"2026-11-01\\",\\"2026-11-30\\",null,\\"odpt.Operator:ZZ\\"]"'
            .']}';

        self::assertSame(hash('sha256', $text), $hash);
        self::assertSame('37143049140538037ea6e88166e86559b58a462f3acd898de7aa80de3f456383', $hash);
    }

    public function testGoldenHashOfNoEntries(): void
    {
        // Same text with "entries":[].
        self::assertSame('657f0301fa7ebe95ebbc119c42a26ed496e7e07ec836e21ab9eb59065961b569', (new ComparedItemsHasher())->hash(self::period(), []));
    }

    public function testHashIsLowercaseSha256Hex(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (new ComparedItemsHasher())->hash(self::period(), self::goldenEntries()));
    }

    public function testOrderOfEntriesDoesNotMatter(): void
    {
        $hasher = new ComparedItemsHasher();

        self::assertSame(
            $hasher->hash(self::period(), self::goldenEntries()),
            $hasher->hash(self::period(), array_reverse(self::goldenEntries())),
        );
    }

    public function testDuplicateEntriesAreCounted(): void
    {
        $hasher = new ComparedItemsHasher();
        $entry = self::entry();

        self::assertNotSame($hasher->hash(self::period(), [$entry]), $hasher->hash(self::period(), [$entry, $entry]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function entriesOutsideThePeriod(): iterable
    {
        yield 'ends the day before' => ['2026-10-01', '2026-10-31'];
        yield 'starts the day after' => ['2026-12-01', '2027-03-27'];
    }

    #[DataProvider('entriesOutsideThePeriod')]
    public function testEntriesOutsideThePeriodAreLeftOut(string $from, string $to): void
    {
        $hasher = new ComparedItemsHasher();
        $outside = self::entry(validFrom: $from, validTo: $to);

        self::assertSame(
            $hasher->hash(self::period(), self::goldenEntries()),
            $hasher->hash(self::period(), [...self::goldenEntries(), $outside]),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function entriesTouchingOneEndOfThePeriod(): iterable
    {
        yield 'ends on the first day' => ['2026-10-01', '2026-11-01'];
        yield 'starts on the last day' => ['2026-11-30', '2027-03-27'];
    }

    #[DataProvider('entriesTouchingOneEndOfThePeriod')]
    public function testEntriesSharingOneDayAreIncluded(string $from, string $to): void
    {
        $hasher = new ComparedItemsHasher();

        self::assertNotSame(
            $hasher->hash(self::period(), []),
            $hasher->hash(self::period(), [self::entry(validFrom: $from, validTo: $to)]),
        );
    }

    public function testValidDaysOutsideThePeriodDoNotMatter(): void
    {
        $hasher = new ComparedItemsHasher();

        // Change (b): only the days outside the compared period differ.
        self::assertSame(
            $hasher->hash(self::period(), [self::entry(validFrom: '2026-10-25', validTo: '2027-01-04')]),
            $hasher->hash(self::period(), [self::entry(validFrom: '2026-09-01', validTo: '2027-03-27')]),
        );
    }

    public function testThePeriodIsPartOfTheHash(): void
    {
        $hasher = new ComparedItemsHasher();
        $other = new ComparedPeriod(ServiceDate::fromString('2026-11-01'), ServiceDate::fromString('2026-11-29'));

        // Entries ending inside both periods: only the period itself differs.
        $entries = [self::entry(validFrom: '2026-11-02', validTo: '2026-11-20')];

        self::assertNotSame($hasher->hash(self::period(), $entries), $hasher->hash($other, $entries));
    }

    /**
     * Every compared item, changed alone, changes the hash (change (a)).
     *
     * @return iterable<string, array{TimetableEntry}>
     */
    public static function entriesWithOneItemChanged(): iterable
    {
        yield 'flight number' => [self::entry(flightNumber: 'ZZ9')];
        yield 'origin' => [self::entry(origin: 'ZZY', destination: 'TKN')];
        yield 'destination' => [self::entry(destination: 'ZZY')];
        yield 'weekday' => [self::entry(weekday: Weekday::Tuesday)];
        yield 'origin time' => [self::entry(originTime: '10:01')];
        yield 'destination time' => [self::entry(destinationTime: '10:51')];
        yield 'origin day difference null to 0' => [self::entry(originDayDifference: 0)];
        yield 'destination day difference null to 0' => [self::entry(destinationDayDifference: 0)];
        yield 'valid from inside the period' => [self::entry(validFrom: '2026-11-02')];
        yield 'valid to inside the period' => [self::entry(validTo: '2026-11-29')];
        yield 'note added' => [self::entry(note: '運休日あり')];
        yield 'empty note' => [self::entry(note: '')];
        yield 'note of one space' => [self::entry(note: ' ')];
        yield 'airline' => [self::entry(airline: 'odpt.Operator:ZY')];
    }

    #[DataProvider('entriesWithOneItemChanged')]
    public function testEachComparedItemChangesTheHash(TimetableEntry $changed): void
    {
        $hasher = new ComparedItemsHasher();

        self::assertNotSame($hasher->hash(self::period(), [self::entry()]), $hasher->hash(self::period(), [$changed]));
    }

    public function testSplittingAnEntryWithTheSameContentChangesTheHash(): void
    {
        $hasher = new ComparedItemsHasher();

        // Operator decision 2026-11-12: the same flights split into two
        // adjacent periods is change (a), accepted as the safe side.
        self::assertNotSame(
            $hasher->hash(self::period(), [self::entry(validFrom: '2026-10-25', validTo: '2027-01-04')]),
            $hasher->hash(self::period(), [
                self::entry(validFrom: '2026-10-25', validTo: '2026-11-15'),
                self::entry(validFrom: '2026-11-16', validTo: '2027-01-04'),
            ]),
        );
    }

    public function testNotesThatDifferOnlyInUnicodeNormalisationDiffer(): void
    {
        $hasher = new ComparedItemsHasher();

        // "が" precomposed (U+304C) and decomposed (U+304B U+3099): the raw
        // text is compared, never normalised.
        self::assertNotSame(
            $hasher->hash(self::period(), [self::entry(note: "\u{304C}")]),
            $hasher->hash(self::period(), [self::entry(note: "\u{304B}\u{3099}")]),
        );
    }

    public function testInvalidUtf8InANoteFails(): void
    {
        $this->expectException(\JsonException::class);

        (new ComparedItemsHasher())->hash(self::period(), [self::entry(note: "\xff")]);
    }

    private static function period(): ComparedPeriod
    {
        return new ComparedPeriod(ServiceDate::fromString('2026-11-01'), ServiceDate::fromString('2026-11-30'));
    }

    /**
     * Covers every weekday and notes with characters that JSON escapes or
     * that optional json_encode flags would change (quote, backslash,
     * <, >, &, apostrophe, newline, tab, a character outside the BMP, and
     * digits only).
     *
     * @return list<TimetableEntry>
     */
    private static function goldenEntries(): array
    {
        return [
            // Cut to the period on both ends.
            self::entry(),
            // Ends inside the period; has a note and day differences of 0.
            self::entry(
                flightNumber: 'ZZ2',
                originTime: '15:00',
                destinationTime: '15:50',
                originDayDifference: 0,
                destinationDayDifference: 0,
                validTo: '2026-11-15',
                note: '11/3は運休',
            ),
            // Starts inside the period, the next period of the same flight.
            self::entry(
                flightNumber: 'ZZ2',
                originTime: '15:10',
                destinationTime: '16:00',
                validFrom: '2026-11-16',
            ),
            // Outside the period: left out.
            self::entry(flightNumber: 'ZZ3', validFrom: '2026-12-01', validTo: '2027-03-27'),
            self::weekdayEntry(Weekday::Tuesday, 'say "x" \\ end'),
            self::weekdayEntry(Weekday::Wednesday, "<a&b>'"),
            self::weekdayEntry(Weekday::Thursday, "1行目\n2行目"),
            self::weekdayEntry(Weekday::Friday, "\u{20BB7}\t"),
            self::weekdayEntry(Weekday::Saturday, '123'),
            self::weekdayEntry(Weekday::Sunday, null),
        ];
    }

    private static function weekdayEntry(Weekday $weekday, ?string $note): TimetableEntry
    {
        return self::entry(
            flightNumber: 'ZZ4',
            weekday: $weekday,
            originTime: '12:00',
            destinationTime: '12:50',
            note: $note,
        );
    }

    private static function entry(
        string $flightNumber = 'ZZ1',
        string $origin = 'TKN',
        string $destination = 'ZZZ',
        Weekday $weekday = Weekday::Monday,
        string $originTime = '10:00',
        string $destinationTime = '10:50',
        ?int $originDayDifference = null,
        ?int $destinationDayDifference = null,
        string $validFrom = '2026-10-25',
        string $validTo = '2027-01-04',
        ?string $note = null,
        string $airline = 'odpt.Operator:ZZ',
    ): TimetableEntry {
        return new TimetableEntry(
            FlightNumber::fromString($flightNumber),
            AirportCode::fromString($origin),
            AirportCode::fromString($destination),
            $weekday,
            $originTime,
            $destinationTime,
            $originDayDifference,
            $destinationDayDifference,
            ServiceDate::fromString($validFrom),
            ServiceDate::fromString($validTo),
            $note,
            $airline,
        );
    }
}
