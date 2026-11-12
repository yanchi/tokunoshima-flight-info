<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Odpt;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Retries 429 and 5xx with exponential backoff (§8), but gives up instead
 * of waiting when the server asks for a longer pause than MAX_DELAY_MS.
 *
 * RetryableHttpClient honours Retry-After before asking the strategy for a
 * delay, and no other limit applies to that wait, so the cap has to be
 * enforced when deciding whether to retry. ODPT currently sends no
 * Retry-After (§9 M9); this guards against it appearing later and
 * stalling a cron run for hours.
 */
final readonly class OdptRetryStrategy implements RetryStrategyInterface
{
    public const RETRY_STATUS_CODES = [429, 500, 502, 503, 504];
    public const MAX_DELAY_MS = 60_000;

    private GenericRetryStrategy $backoff;

    public function __construct(int $delayMs = 1000)
    {
        $this->backoff = new GenericRetryStrategy(self::RETRY_STATUS_CODES, $delayMs, 2.0, self::MAX_DELAY_MS);
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        $shouldRetry = $this->backoff->shouldRetry($context, $responseContent, $exception);
        if (true !== $shouldRetry || null !== $exception) {
            return $shouldRetry;
        }

        $retryAfterMs = self::retryAfterMs($context->getHeaders()['retry-after'][0] ?? null);

        return null === $retryAfterMs || $retryAfterMs <= self::MAX_DELAY_MS;
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return $this->backoff->getDelay($context, $responseContent, $exception);
    }

    /**
     * Same interpretation as RetryableHttpClient: seconds or an HTTP date.
     * A value it cannot read is ignored there, so it is ignored here too.
     */
    private static function retryAfterMs(?string $value): ?int
    {
        if (null === $value) {
            return null;
        }
        if (is_numeric($value)) {
            return (int) ((float) $value * 1000);
        }
        if (false !== $time = strtotime($value)) {
            return max(0, $time - time()) * 1000;
        }

        return null;
    }
}
