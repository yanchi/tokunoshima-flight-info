<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Provider\Odpt;

use App\Application\Port\TimetableProviderInterface;
use App\Infrastructure\Provider\Odpt\OdptTimetableClient;
use Psr\Log\LoggerAwareInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * The consumer key is in the request URL, and Symfony's HTTP clients log
 * every URL once they have a logger. The ODPT client must get one without.
 * No request is sent.
 */
final class OdptHttpClientWiringTest extends KernelTestCase
{
    public function testOdptRequestsGoThroughAClientWithoutLogger(): void
    {
        $provider = self::getContainer()->get(TimetableProviderInterface::class);

        $timetableClient = self::property($provider, 'client');
        self::assertInstanceOf(OdptTimetableClient::class, $timetableClient);

        $retryable = self::property($timetableClient, 'httpClient');
        self::assertInstanceOf(RetryableHttpClient::class, $retryable);

        $transport = self::property($retryable, 'client');
        // The transports (curl, native, amphp) are LoggerAware; the
        // framework's http_client is a decorator around one.
        self::assertInstanceOf(LoggerAwareInterface::class, $transport);
        self::assertNull(self::property($transport, 'logger'));
    }

    private static function property(object $object, string $name): mixed
    {
        return new \ReflectionProperty($object, $name)->getValue($object);
    }
}
