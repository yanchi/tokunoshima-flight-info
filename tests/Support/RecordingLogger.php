<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * TEST ONLY. Keeps every log call for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        \assert(\is_string($level));
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string> "level: message" of every record
     */
    public function lines(): array
    {
        return array_map(static fn (array $r): string => $r['level'].': '.$r['message'], $this->records);
    }

    public function has(string $level, string $messagePart, ?string $direction = null): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level
                && str_contains($record['message'], $messagePart)
                && (null === $direction || ($record['context']['direction'] ?? null) === $direction)) {
                return true;
            }
        }

        return false;
    }
}
