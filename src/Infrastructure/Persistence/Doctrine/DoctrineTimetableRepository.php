<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\DTO\TimetableFetchResult;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\Port\TimetableSaveResult;
use App\Application\Sync\TimetableReplacementRule;
use App\Domain\Flight\Direction;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableFetchRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableFlight;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

final readonly class DoctrineTimetableRepository implements TimetableRepositoryInterface
{
    // The mapper accepts +09:00 only (§7 step 7), so giving that offset back
    // to the stored UTC dc:date restores the fetched value.
    private const SOURCE_OFFSET = '+09:00';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private ManagerRegistry $registry,
    ) {
    }

    public function save(TimetableFetchResult $result, ?callable $whenReplaced = null): TimetableSaveResult
    {
        $requestStartedAt = $result->storedRequestStartedAt();

        try {
            return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($result, $requestStartedAt, $whenReplaced): TimetableSaveResult {
                $record = $em->find(TimetableFetchRecord::class, $result->direction);
                // find() returns an already managed record without a query, so
                // another process's newer save would go unseen. Re-read it inside
                // the transaction; the write lock is a no-op on SQLite and keeps
                // other databases from a lost update.
                if (null !== $record) {
                    $em->refresh($record, LockMode::PESSIMISTIC_WRITE);
                }
                $previous = null === $record ? null : $this->toResult($record);

                // Taken right after the re-read, unrounded (§7 step 7).
                $judgedAt = $this->clock->now();
                $outcome = TimetableReplacementRule::decide($requestStartedAt, $record?->requestStartedAt(), $judgedAt);
                if (!$outcome->replaced()) {
                    return new TimetableSaveResult($outcome, $previous, $judgedAt);
                }

                $em->createQueryBuilder()
                    ->delete(TimetableFlight::class, 'f')
                    ->where('f.direction = :direction')
                    ->setParameter('direction', $result->direction)
                    ->getQuery()
                    ->execute();

                $flights = array_map(static fn ($entry) => TimetableFlight::fromEntry($result->direction, $entry), $result->entries);
                foreach ($flights as $flight) {
                    $em->persist($flight);
                }

                if (null === $record) {
                    $em->persist(new TimetableFetchRecord($result->direction, $requestStartedAt, $result->latestDataDate, $result->recordCount));
                } else {
                    $record->replace($requestStartedAt, $result->latestDataDate, $result->recordCount);
                }

                $em->flush();
                if (null !== $whenReplaced) {
                    $whenReplaced($previous);
                }
                // Rows are only snapshots for the DTO; keeping them managed would
                // pile up in a long-running process.
                foreach ($flights as $flight) {
                    $em->detach($flight);
                }

                return new TimetableSaveResult($outcome, $previous, $judgedAt);
            });
        } catch (\Throwable $e) {
            // A failed transaction closes the entity manager. Reset it so the
            // other direction can still be saved in the same run.
            if (!$this->entityManager->isOpen()) {
                $this->registry->resetManager();
            }

            throw $e;
        }
    }

    public function findLatest(Direction $direction): ?TimetableFetchResult
    {
        $record = $this->entityManager->find(TimetableFetchRecord::class, $direction);

        return null === $record ? null : $this->toResult($record);
    }

    private function toResult(TimetableFetchRecord $record): TimetableFetchResult
    {
        /** @var list<TimetableFlight> $flights */
        $flights = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(TimetableFlight::class, 'f')
            ->where('f.direction = :direction')
            ->setParameter('direction', $record->direction())
            // Insertion order, i.e. the order of the fetched response.
            ->orderBy('f.id')
            ->getQuery()
            ->getResult();

        $offset = new \DateTimeZone(self::SOURCE_OFFSET);
        $entries = [];
        foreach ($flights as $flight) {
            $entries[] = $flight->toEntry();
            $this->entityManager->detach($flight);
        }

        return new TimetableFetchResult(
            $record->direction(),
            $record->requestStartedAt(),
            $record->latestDataDate()->setTimezone($offset),
            $record->recordCount(),
            $entries,
        );
    }
}
