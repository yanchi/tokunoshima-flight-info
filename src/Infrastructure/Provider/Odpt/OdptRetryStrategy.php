<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Odpt;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Retries 429 and 5xx with exponential backoff (§8).
 *
 * RetryableHttpClient waits for Retry-After before asking the strategy for
 * a delay, and nothing else limits that wait. ODPT currently sends no
 * Retry-After (§9 M9), so the header is only guarded against
 * (docs/data-source-verification.md §8, decided 2026-11-12):
 * - more than 0 and at most MAX_DELAY_MS: wait as asked, then retry
 * - 0 or less: give up, since retrying without a pause would hammer the
 *   rate limit
 * - more than MAX_DELAY_MS: give up instead of stalling a cron run
 * - unreadable: ignored, as RetryableHttpClient does; own backoff applies
 */
final readonly class OdptRetryStrategy implements RetryStrategyInterface
{
    public const RETRY_STATUS_CODES = [429, 500, 502, 503, 504];

    /**
     * Kept well below OdptTimetableClient::MAX_DURATION_SECONDS, which
     * limits all attempts together, waits included (AsyncContext).
     */
    public const MAX_DELAY_MS = 20_000;

    private GenericRetryStrategy $backoff;

    public function __construct(private ClockInterface $clock, int $delayMs = 1000)
    {
        $this->backoff = new GenericRetryStrategy(self::RETRY_STATUS_CODES, $delayMs, 2.0, self::MAX_DELAY_MS);
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        $shouldRetry = $this->backoff->shouldRetry($context, $responseContent, $exception);
        if (true !== $shouldRetry || null !== $exception) {
            return $shouldRetry;
        }

        return $this->acceptsRetryAfter($context->getHeaders()['retry-after'][0] ?? null);
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return $this->backoff->getDelay($context, $responseContent, $exception);
    }

    /**
     * Reads the header the way RetryableHttpClient does (seconds or an
     * HTTP date), so the decision matches the wait it would apply.
     */
    public function acceptsRetryAfter(?string $value): bool
    {
        if (null === $value) {
            return true;
        }

        if (is_numeric($value)) {
            $delayMs = (float) $value * 1000;
        } elseif (false !== $time = strtotime($value)) {
            $delayMs = ($time - $this->clock->now()->getTimestamp()) * 1000;
        } else {
            return true;
        }

        return $delayMs > 0 && $delayMs <= self::MAX_DELAY_MS;
    }
}
