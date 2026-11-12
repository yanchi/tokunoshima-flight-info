<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Provider\Odpt;

use Psr\Log\LoggerAwareInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * The consumer key is in the request URL. Symfony's HTTP clients log every
 * URL once they have a logger, and RetryableHttpClient logs exception
 * messages that contain it. Neither may get a logger. No request is sent.
 */
final class OdptHttpClientWiringTest extends KernelTestCase
{
    public function testOdptRequestsGoThroughClientsWithoutLogger(): void
    {
        $timetableClient = self::getContainer()->get('test.odpt_timetable_client');

        $retryable = self::property($timetableClient, 'httpClient');
        self::assertInstanceOf(RetryableHttpClient::class, $retryable);
        self::assertNull(self::property($retryable, 'logger'));

        // The transports (curl, native, amphp) are LoggerAware; the
        // framework's http_client is a decorator around one.
        $transport = self::property($retryable, 'client');
        self::assertInstanceOf(LoggerAwareInterface::class, $transport);
        self::assertNull(self::property($transport, 'logger'));
    }

    private static function property(object $object, string $name): mixed
    {
        return new \ReflectionProperty($object, $name)->getValue($object);
    }
}
