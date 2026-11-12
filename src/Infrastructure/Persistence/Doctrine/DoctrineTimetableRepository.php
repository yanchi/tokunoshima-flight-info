<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\DTO\TimetableFetchResult;
use App\Application\Port\TimetableRepositoryInterface;
use App\Application\Port\TimetableSaveOutcome;
use App\Domain\Flight\Direction;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableFetchRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\TimetableFlight;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTimetableRepository implements TimetableRepositoryInterface
{
    // The mapper accepts +09:00 only (§7 step 7), so giving that offset back
    // to the stored UTC instants restores the fetched values. Revisit with
    // the JST calendar-day definition if other offsets are ever accepted.
    private const SOURCE_OFFSET = '+09:00';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(TimetableFetchResult $result): TimetableSaveOutcome
    {
        // Stored at whole seconds. Rounding down makes the data look older,
        // never newer, than it is (§7 step 7).
        $requestStartedAt = new \DateTimeImmutable('@'.$result->requestStartedAt->getTimestamp())
            ->setTimezone(new \DateTimeZone('UTC'));

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($result, $requestStartedAt): TimetableSaveOutcome {
            $record = $em->find(TimetableFetchRecord::class, $result->direction);

            // Two fetches in the same second cannot be ordered, so the
            // stored one is kept.
            if (null !== $record && $requestStartedAt <= $record->requestStartedAt()) {
                return TimetableSaveOutcome::NotNewerThanStored;
            }

            $em->createQueryBuilder()
                ->delete(TimetableFlight::class, 'f')
                ->where('f.direction = :direction')
                ->setParameter('direction', $result->direction)
                ->getQuery()
                ->execute();

            foreach ($result->entries as $entry) {
                $em->persist(TimetableFlight::fromEntry($result->direction, $entry));
            }

            if (null === $record) {
                $em->persist(new TimetableFetchRecord($result->direction, $requestStartedAt, $result->latestDataDate, $result->recordCount));
            } else {
                $record->replace($requestStartedAt, $result->latestDataDate, $result->recordCount);
            }

            $em->flush();

            return TimetableSaveOutcome::Saved;
        });
    }

    public function findLatest(Direction $direction): ?TimetableFetchResult
    {
        $record = $this->entityManager->find(TimetableFetchRecord::class, $direction);
        if (null === $record) {
            return null;
        }

        /** @var list<TimetableFlight> $flights */
        $flights = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(TimetableFlight::class, 'f')
            ->where('f.direction = :direction')
            ->setParameter('direction', $direction)
            // Insertion order, i.e. the order of the fetched response.
            ->orderBy('f.id')
            ->getQuery()
            ->getResult();

        $offset = new \DateTimeZone(self::SOURCE_OFFSET);

        return new TimetableFetchResult(
            $direction,
            $record->requestStartedAt(),
            $record->latestDataDate()->setTimezone($offset),
            $record->recordCount(),
            array_map(static fn (TimetableFlight $flight) => $flight->toEntry($offset), $flights),
        );
    }
}
