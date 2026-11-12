<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Odpt;

use App\Application\Port\TimetableFetchFailed;
use App\Domain\Flight\Direction;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls the ODPT FlightSchedule endpoint for Tokunoshima flights.
 *
 * Error messages are written here and never copied from HttpClient
 * exceptions, because those contain the request URL and with it the
 * consumer key.
 */
final readonly class OdptTimetableClient
{
    public const ENDPOINT = 'https://api.odpt.org/api/v4/odpt:FlightSchedule';
    public const OPERATOR = 'odpt.Operator:JAL';
    public const AIRPORT = 'odpt.Airport:TKN';

    // Parameter names in one place: a misspelt name is answered with
    // 200 and an empty array instead of an error (§3).
    public const PARAM_OPERATOR = 'odpt:operator';
    public const PARAM_ORIGIN_AIRPORT = 'odpt:originAirport';
    public const PARAM_DESTINATION_AIRPORT = 'odpt:destinationAirport';
    public const PARAM_CONSUMER_KEY = 'acl:consumerKey';

    // Only rate limiting and server errors are retried; 403 and 404 are
    // not (§8). Transport errors are not retried either.
    private const RETRY_STATUS_CODES = [429, 500, 502, 503, 504];
    private const MAX_RETRIES = 3;
    private const IDLE_TIMEOUT_SECONDS = 10.0;
    private const MAX_DURATION_SECONDS = 60.0;

    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private string $consumerKey,
        private LoggerInterface $logger,
        int $retryDelayMs = 1000,
    ) {
        $this->httpClient = new RetryableHttpClient(
            $httpClient,
            new GenericRetryStrategy(self::RETRY_STATUS_CODES, $retryDelayMs, 2.0),
            self::MAX_RETRIES,
        );
    }

    /**
     * @return array<string, string> query without the consumer key
     */
    public static function queryFor(Direction $direction): array
    {
        return [
            self::PARAM_OPERATOR => self::OPERATOR,
            match ($direction) {
                Direction::Departure => self::PARAM_ORIGIN_AIRPORT,
                Direction::Arrival => self::PARAM_DESTINATION_AIRPORT,
            } => self::AIRPORT,
        ];
    }

    /**
     * @return mixed decoded JSON body, not validated
     *
     * @throws TimetableFetchFailed
     */
    public function fetch(Direction $direction): mixed
    {
        if ('' === $this->consumerKey) {
            throw new TimetableFetchFailed('ODPT consumer key is not configured.');
        }

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => self::queryFor($direction) + [self::PARAM_CONSUMER_KEY => $this->consumerKey],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);
            $content = $response->getContent();
            $this->logRateLimit($direction, $response->getHeaders());
        } catch (HttpExceptionInterface $e) {
            throw new TimetableFetchFailed(\sprintf('ODPT timetable request (%s) returned HTTP %d.', $direction->value, $e->getResponse()->getStatusCode()));
        } catch (ExceptionInterface) {
            throw new TimetableFetchFailed(\sprintf('ODPT timetable request (%s) failed before a response was received.', $direction->value));
        }

        try {
            return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TimetableFetchFailed(\sprintf('ODPT timetable response (%s) is not valid JSON.', $direction->value));
        }
    }

    /**
     * Limits are "current settings" that may change (§1), so they are only
     * logged here and never hard-coded.
     *
     * @param array<string, list<string>> $headers
     */
    private function logRateLimit(Direction $direction, array $headers): void
    {
        $remaining = [];
        foreach ($headers as $name => $values) {
            if (str_starts_with($name, 'x-ratelimit-remaining-')) {
                $remaining[substr($name, \strlen('x-ratelimit-remaining-'))] = $values[0] ?? '';
            }
        }

        $this->logger->info('ODPT timetable fetched.', ['direction' => $direction->value, 'rate_limit_remaining' => $remaining]);
    }
}
