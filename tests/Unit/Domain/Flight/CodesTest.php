<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Flight;

use App\Domain\Flight\AirportCode;
use App\Domain\Flight\FlightNumber;
use App\Domain\Flight\OperatorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test-only values: ZZ is not used as a real airline designator here,
 * so these never look like actual flights.
 */
final class CodesTest extends TestCase
{
    public function testAirportCode(): void
    {
        $code = AirportCode::fromString('TKN');

        self::assertSame('TKN', $code->value);
        self::assertTrue($code->equals(AirportCode::fromString('TKN')));
        self::assertFalse($code->equals(AirportCode::fromString('KOJ')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAirportCodes(): iterable
    {
        yield 'lowercase' => ['tkn'];
        yield 'ICAO code' => ['RJKN'];
        yield 'too short' => ['TK'];
        yield 'surrounding space' => [' TKN'];
    }

    #[DataProvider('invalidAirportCodes')]
    public function testAirportCodeRejectsInvalid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AirportCode::fromString($value);
    }

    public function testFlightNumber(): void
    {
        $number = FlightNumber::fromString('ZZ1234');

        self::assertSame('ZZ', $number->airlineDesignator());
        self::assertTrue($number->equals(FlightNumber::fromString('ZZ1234')));
        self::assertSame('ZZ1A', (string) FlightNumber::fromString('ZZ1A'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFlightNumbers(): iterable
    {
        yield 'no digits' => ['ZZ'];
        yield 'five digits' => ['ZZ12345'];
        yield 'space inside' => ['ZZ 123'];
        yield 'lowercase' => ['zz123'];
    }

    #[DataProvider('invalidFlightNumbers')]
    public function testFlightNumberRejectsInvalid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FlightNumber::fromString($value);
    }

    public function testOperatorCodeAcceptsIataAndIcao(): void
    {
        self::assertSame('ZZ', OperatorCode::fromString('ZZ')->value);
        self::assertSame('ZZZ', OperatorCode::fromString('ZZZ')->value);
        self::assertFalse(OperatorCode::fromString('ZZ')->equals(OperatorCode::fromString('Z9')));
    }

    public function testOperatorCodeRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OperatorCode::fromString('ZZZZ');
    }
}
