<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Provider\Odpt;

use App\Application\Port\TimetableFetchFailed;
use App\Domain\Flight\Direction;
use App\Infrastructure\Provider\Odpt\OdptTimetableClient;
use App\Infrastructure\Provider\Odpt\OdptTimetableMapper;
use App\Infrastructure\Provider\Odpt\OdptTimetableProvider;
use App\Tests\Support\FictionalOdptTimetable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Never reaches the real API: every response comes from MockHttpClient.
 * The consumer key below is a test placeholder.
 */
final class OdptTimetableClientTest extends TestCase
{
    private const TEST_KEY = 'test-consumer-key';

    /**
     * @return iterable<string, array{Direction, string}>
     */
    public static function directions(): iterable
    {
        yield 'departure' => [Direction::Departure, 'odpt:originAirport=odpt.Airport:TKN'];
        yield 'arrival' => [Direction::Arrival, 'odpt:destinationAirport=odpt.Airport:TKN'];
    }

    #[DataProvider('directions')]
    public function testRequestsTheTimetableForTheDirection(Direction $direction, string $airportQuery): void
    {
        $requests = [];
        $client = $this->client(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return new MockResponse('[]');
        });

        $client->fetch($direction);

        self::assertSame([[
            'GET',
            'https://api.odpt.org/api/v4/odpt:FlightSchedule?odpt:operator=odpt.Operator:JAL&'.$airportQuery.'&acl:consumerKey='.self::TEST_KEY,
        ]], $requests);
    }

    public function testDoesNotCallTheApiWithoutAKey(): void
    {
        $http = new MockHttpClient([]);
        $client = new OdptTimetableClient($http, '', new NullLogger(), 0);

        try {
            $client->fetch(Direction::Departure);
            self::fail('Expected TimetableFetchFailed.');
        } catch (TimetableFetchFailed) {
        }

        self::assertSame(0, $http->getRequestsCount());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function notRetriedStatuses(): iterable
    {
        yield 'missing or invalid key' => [403];
        yield 'unknown data type' => [404];
        yield 'bad request' => [400];
    }

    #[DataProvider('notRetriedStatuses')]
    public function testClientErrorsAreNotRetried(int $status): void
    {
        $http = new MockHttpClient([new MockResponse('Invalid acl:consumerKey.', ['http_code' => $status])]);

        $this->expectFailure(new OdptTimetableClient($http, self::TEST_KEY, new NullLogger(), 0), \sprintf('returned HTTP %d', $status));
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testRateLimitIsRetried(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 429]),
            new MockResponse('[]'),
        ]);

        $body = new OdptTimetableClient($http, self::TEST_KEY, new NullLogger(), 0)->fetch(Direction::Departure);

        self::assertSame([], $body);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testServerErrorsAreRetriedUpToTheLimit(): void
    {
        $http = new MockHttpClient(array_fill(0, 5, new MockResponse('', ['http_code' => 503])));

        $this->expectFailure(new OdptTimetableClient($http, self::TEST_KEY, new NullLogger(), 0), 'returned HTTP 503');
        self::assertSame(4, $http->getRequestsCount());
    }

    public function testTransportErrorsAreNotRetried(): void
    {
        $http = new MockHttpClient([
            new MockResponse([new TransportException('Timeout for '.self::TEST_KEY)]),
            new MockResponse('[]'),
        ]);

        $this->expectFailure(new OdptTimetableClient($http, self::TEST_KEY, new NullLogger(), 0), 'failed before a response');
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testInvalidJsonFails(): void
    {
        $this->expectFailure($this->client(new MockResponse('<html>')), 'not valid JSON');
    }

    public function testProviderStampsTheStartOfTheFirstAttemptInUtc(): void
    {
        $clock = new MockClock('2026-11-12T09:00:00+09:00');
        $http = new MockHttpClient(static function () use ($clock): MockResponse {
            // Time passes during the request; the stamp must not move.
            $clock->sleep(30);

            return new MockResponse((string) json_encode([FictionalOdptTimetable::departureRecord()]));
        });
        $provider = new OdptTimetableProvider(
            new OdptTimetableClient($http, self::TEST_KEY, new NullLogger(), 0),
            new OdptTimetableMapper(),
            $clock,
        );

        $result = $provider->fetch(Direction::Departure);

        self::assertSame('2026-11-12T00:00:00+00:00', $result->requestStartedAt->format(\DATE_ATOM));
        self::assertCount(2, $result->entries);
    }

    private function client(callable|MockResponse $responses): OdptTimetableClient
    {
        return new OdptTimetableClient(new MockHttpClient($responses), self::TEST_KEY, new NullLogger(), 0);
    }

    /**
     * Also checks that the message never leaks the key or the request URL.
     */
    private function expectFailure(OdptTimetableClient $client, string $messagePart): void
    {
        try {
            $client->fetch(Direction::Departure);
            self::fail('Expected TimetableFetchFailed.');
        } catch (TimetableFetchFailed $e) {
            self::assertStringContainsString($messagePart, $e->getMessage());
            self::assertStringNotContainsString(self::TEST_KEY, $e->getMessage());
            self::assertStringNotContainsString('api.odpt.org', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
