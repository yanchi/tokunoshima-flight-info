<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\DTO\TimetableFetchResult;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\Port\TimetableSaveResult;
use App\Application\Sync\TimetableReplacementRule;
use App\Domain\Flight\Direction;
use Psr\Clock\ClockInterface;

/**
 * TEST ONLY. Applies the same replacement rule as the Doctrine repository.
 */
final class InMemoryTimetableRepository implements TimetableRepositoryInterface
{
    /** @var array<string, TimetableFetchResult> */
    private array $stored = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    /** @var list<bool> whether the watched lock was held, per save */
    public array $lockedDuringSave = [];

    private ?StubTimetableLock $watchedLock = null;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /** @var (\Closure(): void)|null */
    private ?\Closure $beforeJudgment = null;

    /**
     * Runs right before each judgment, e.g. to move the clock between the
     * start of the run and the save.
     *
     * @param \Closure(): void $hook
     */
    public function beforeJudgment(\Closure $hook): void
    {
        $this->beforeJudgment = $hook;
    }

    public function watchLock(StubTimetableLock $lock): void
    {
        $this->watchedLock = $lock;
    }

    public function store(TimetableFetchResult $result): void
    {
        $this->stored[$result->direction->value] = self::asStored($result);
    }

    public function failNextSave(Direction $direction, \Throwable $exception): void
    {
        $this->failures[$direction->value] = $exception;
    }

    public function save(TimetableFetchResult $result, ?callable $whenReplaced = null): TimetableSaveResult
    {
        if (null !== $this->watchedLock) {
            $this->lockedDuringSave[] = $this->watchedLock->held;
        }
        if (isset($this->failures[$result->direction->value])) {
            $failure = $this->failures[$result->direction->value];
            unset($this->failures[$result->direction->value]);
            throw $failure;
        }

        $previous = $this->stored[$result->direction->value] ?? null;
        if (null !== $this->beforeJudgment) {
            ($this->beforeJudgment)();
        }
        $now = $this->clock->now();
        $outcome = TimetableReplacementRule::decide($result->storedRequestStartedAt(), $previous?->requestStartedAt, $now);
        if ($outcome->replaced()) {
            // Like a rollback: nothing is stored when the callback throws.
            if (null !== $whenReplaced) {
                $whenReplaced($previous);
            }
            $this->store($result);
        }

        return new TimetableSaveResult($outcome, $previous, $now);
    }

    public function findLatest(Direction $direction): ?TimetableFetchResult
    {
        return $this->stored[$direction->value] ?? null;
    }

    private static function asStored(TimetableFetchResult $result): TimetableFetchResult
    {
        return new TimetableFetchResult($result->direction, $result->storedRequestStartedAt(), $result->latestDataDate, $result->recordCount, $result->entries);
    }
}
